package kz.sental.ncasign.pades.service;

import kz.sental.ncasign.pades.model.PadesFinalizeRequest;
import kz.sental.ncasign.pades.model.PadesFinalizeResponse;
import kz.sental.ncasign.pades.model.PadesPrepareRequest;
import kz.sental.ncasign.pades.model.PadesPrepareResponse;
import kz.sental.ncasign.pades.model.SignerPayload;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.ExternalSigningSupport;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureOptions;
import org.springframework.context.annotation.Primary;
import org.springframework.stereotype.Service;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.security.MessageDigest;
import java.time.Instant;
import java.time.format.DateTimeFormatter;
import java.util.Base64;
import java.util.Calendar;
import java.util.Comparator;
import java.util.Date;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;

@Service
@Primary
public class PdfBoxPadesEmbeddingService implements PadesEmbeddingService {
    private static final int PREFERRED_SIGNATURE_SIZE = 131072;

    private final Map<String, PreparedSigningSession> sessions = new ConcurrentHashMap<>();

    @Override
    public PadesPrepareResponse prepareExternalSignature(PadesPrepareRequest request) {
        byte[] pdfBytes = decodeBase64(request.draftPdfBase64, "PDF");
        int signerOrder = extractSignerOrder(request.activeSigner);
        SignatureSlot slot = resolveSignatureSlot(request.manifest, signerOrder);
        Date signingDate = new Date();
        String signerName = extractSignerName(request.activeSigner, signerOrder);
        String sessionId = UUID.randomUUID().toString();

        PreparedSigningSession session = createSession(sessionId, pdfBytes, signerName, signingDate, slot);
        sessions.put(sessionId, session);

        PadesPrepareResponse response = new PadesPrepareResponse();
        response.status = "ok";
        response.message = "Prepared PDF ByteRange content for external CMS signing";
        response.sessionId = sessionId;
        response.fieldName = slot.fieldName;
        response.payloadMode = "prepared_pdf_dtbs";
        response.signablePayloadBase64 = Base64.getEncoder().encodeToString(session.contentToSign);
        response.signablePayloadSha256 = sha256Hex(session.contentToSign);
        response.signingTime = DateTimeFormatter.ISO_INSTANT.format(signingDate.toInstant());
        response.evidence = new HashMap<>();
        response.evidence.put("fieldName", slot.fieldName);
        response.evidence.put("signerOrder", signerOrder);
        response.evidence.put("payloadType", "pdfbox_external_signing_content");
        response.evidence.put("contentLength", session.contentToSign.length);
        return response;
    }

    @Override
    public PadesFinalizeResponse finalizeDetachedCms(PadesFinalizeRequest request) {
        SignerPayload signer = selectLatestSignerWithSession(request.signers);
        if (signer == null) {
            return PadesFinalizeResponse.error("No signed signer with a prepared PDFBox session was found.");
        }

        String sessionId = extractPrepareSessionId(signer);
        PreparedSigningSession session = sessions.remove(sessionId);
        if (session == null) {
            return PadesFinalizeResponse.error("Prepared signing session was not found or has expired for signer #" + signer.order + ".");
        }

        try {
            byte[] cmsBytes = decodeBase64(signer.rawCmsBase64, "CMS");
            session.externalSigning.setSignature(cmsBytes);
            byte[] signedPdf = session.output.toByteArray();

            PadesFinalizeResponse response = new PadesFinalizeResponse();
            response.status = "ok";
            response.message = "Embedded CMS into PDF using PDFBox external signing";
            response.filename = request.isFinal
                ? "signed_final_job_" + request.job.id + ".pdf"
                : "signed_progress_job_" + request.job.id + ".pdf";
            response.pdfBase64 = Base64.getEncoder().encodeToString(signedPdf);
            response.finalHash = sha256Hex(signedPdf);
            response.mode = "pdfbox_external_signing";
            response.source = "java_pdfbox_pades_sidecar";
            response.evidence = new HashMap<>();
            response.evidence.put("embeddedSignerOrder", signer.order);
            response.evidence.put("fieldName", session.fieldName);
            response.evidence.put("sessionId", sessionId);
            response.evidence.put("cmsLength", cmsBytes.length);
            return response;
        } catch (Exception e) {
            throw new IllegalStateException("PDFBox external signing failed for signer #" + signer.order + ": " + e.getMessage(), e);
        } finally {
            session.close();
        }
    }

    private PreparedSigningSession createSession(String sessionId, byte[] pdfBytes, String signerName, Date signingDate, SignatureSlot slot) {
        try {
            PDDocument document = PDDocument.load(pdfBytes);
            PDSignature signature = new PDSignature();
            signature.setFilter(PDSignature.FILTER_ADOBE_PPKLITE);
            signature.setSubFilter(PDSignature.SUBFILTER_ADBE_PKCS7_DETACHED);
            signature.setName(signerName);
            signature.setReason("local_ncasign PDFBox external signing");
            Calendar calendar = Calendar.getInstance();
            calendar.setTime(signingDate);
            signature.setSignDate(calendar);

            SignatureOptions options = new SignatureOptions();
            options.setPreferredSignatureSize(PREFERRED_SIGNATURE_SIZE);

            document.addSignature(signature, options);

            ByteArrayOutputStream output = new ByteArrayOutputStream();
            ExternalSigningSupport externalSigning = document.saveIncrementalForExternalSigning(output);
            byte[] contentToSign;
            try (InputStream content = externalSigning.getContent()) {
                contentToSign = content.readAllBytes();
            }

            return new PreparedSigningSession(sessionId, document, externalSigning, output, contentToSign, signingDate, slot.fieldName);
        } catch (IOException e) {
            throw new IllegalStateException("Unable to prepare PDFBox external signing session.", e);
        }
    }

    private SignerPayload selectLatestSignerWithSession(List<SignerPayload> signers) {
        if (signers == null || signers.isEmpty()) {
            return null;
        }
        return signers.stream()
            .filter(signer -> signer != null
                && signer.rawCmsBase64 != null
                && !signer.rawCmsBase64.isBlank()
                && extractPrepareSessionId(signer) != null
                && !extractPrepareSessionId(signer).isBlank())
            .max(Comparator.comparingInt(signer -> signer.order))
            .orElse(null);
    }

    @SuppressWarnings("unchecked")
    private String extractPrepareSessionId(SignerPayload signer) {
        if (signer == null || signer.signMeta == null) {
            return null;
        }
        Object payloadMetaObj = signer.signMeta.get("payload_meta");
        if (!(payloadMetaObj instanceof Map<?, ?> payloadMetaRaw)) {
            return null;
        }
        Map<String, Object> payloadMeta = (Map<String, Object>) payloadMetaRaw;
        Object prepareObj = payloadMeta.get("prepare");
        if (!(prepareObj instanceof Map<?, ?> prepareRaw)) {
            return null;
        }
        Map<String, Object> prepare = (Map<String, Object>) prepareRaw;
        Object sessionIdObj = prepare.get("sessionid");
        if (sessionIdObj instanceof String sessionId && !sessionId.isBlank()) {
            return sessionId;
        }
        return null;
    }

    private String extractSignerName(Map<String, Object> activeSigner, int signerOrder) {
        if (activeSigner == null) {
            return "Signer " + signerOrder;
        }
        Object name = activeSigner.get("name");
        if (name instanceof String value && !value.isBlank()) {
            return value;
        }
        return "Signer " + signerOrder;
    }

    private byte[] decodeBase64(String value, String label) {
        try {
            return Base64.getDecoder().decode((value == null ? "" : value).replaceAll("\\s+", ""));
        } catch (IllegalArgumentException e) {
            throw new IllegalArgumentException("Invalid base64 for " + label + ".", e);
        }
    }

    private int extractSignerOrder(Map<String, Object> activeSigner) {
        if (activeSigner == null) {
            return 1;
        }
        Object order = activeSigner.get("order");
        if (order instanceof Number number) {
            return number.intValue();
        }
        if (order instanceof String value && !value.isBlank()) {
            return Integer.parseInt(value);
        }
        return 1;
    }

    @SuppressWarnings("unchecked")
    private SignatureSlot resolveSignatureSlot(Map<String, Object> manifest, int signerOrder) {
        SignatureSlot fallback = new SignatureSlot("sig_slot_" + signerOrder);
        if (manifest != null) {
            Object slots = manifest.get("signature_slots");
            if (slots instanceof List<?> slotList) {
                for (Object slotObj : slotList) {
                    if (!(slotObj instanceof Map<?, ?> rawSlot)) {
                        continue;
                    }
                    Map<String, Object> slot = (Map<String, Object>) rawSlot;
                    String name = String.valueOf(slot.getOrDefault("name", ""));
                    if (!name.isBlank() && name.endsWith(String.valueOf(signerOrder))) {
                        return new SignatureSlot(name);
                    }
                }
            }
        }
        return fallback;
    }

    private String sha256Hex(byte[] bytes) {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] hash = digest.digest(bytes);
            StringBuilder builder = new StringBuilder(hash.length * 2);
            for (byte item : hash) {
                builder.append(String.format("%02x", item));
            }
            return builder.toString();
        } catch (Exception e) {
            throw new IllegalStateException("Unable to compute SHA-256.", e);
        }
    }

    private static final class PreparedSigningSession {
        private final String sessionId;
        private final PDDocument document;
        private final ExternalSigningSupport externalSigning;
        private final ByteArrayOutputStream output;
        private final byte[] contentToSign;
        private final Date signingDate;
        private final String fieldName;

        private PreparedSigningSession(
            String sessionId,
            PDDocument document,
            ExternalSigningSupport externalSigning,
            ByteArrayOutputStream output,
            byte[] contentToSign,
            Date signingDate,
            String fieldName
        ) {
            this.sessionId = sessionId;
            this.document = document;
            this.externalSigning = externalSigning;
            this.output = output;
            this.contentToSign = contentToSign;
            this.signingDate = signingDate;
            this.fieldName = fieldName;
        }

        private void close() {
            try {
                document.close();
            } catch (IOException ignored) {
                // Best effort cleanup.
            }
        }
    }

    private record SignatureSlot(String fieldName) { }
}
