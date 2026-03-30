package kz.sental.ncasign.pades.service;

import eu.europa.esig.dss.enumerations.MimeTypeEnum;
import eu.europa.esig.dss.enumerations.SignatureLevel;
import eu.europa.esig.dss.model.DSSDocument;
import eu.europa.esig.dss.model.DSSMessageDigest;
import eu.europa.esig.dss.model.InMemoryDocument;
import eu.europa.esig.dss.pades.PAdESSignatureParameters;
import eu.europa.esig.dss.pades.SignatureFieldParameters;
import eu.europa.esig.dss.pades.SignatureImageParameters;
import eu.europa.esig.dss.pades.signature.PAdESWithExternalCMSService;
import eu.europa.esig.dss.pdf.PDFServiceMode;
import eu.europa.esig.dss.pdf.pdfbox.PdfBoxSignatureService;
import eu.europa.esig.dss.pdf.pdfbox.visible.defaultdrawer.PdfBoxDefaultSignatureDrawerFactory;
import kz.sental.ncasign.pades.model.PadesFinalizeRequest;
import kz.sental.ncasign.pades.model.PadesFinalizeResponse;
import kz.sental.ncasign.pades.model.PadesPrepareRequest;
import kz.sental.ncasign.pades.model.PadesPrepareResponse;
import kz.sental.ncasign.pades.model.SignerPayload;
import org.springframework.context.annotation.Primary;
import org.springframework.stereotype.Service;

import java.security.MessageDigest;
import java.time.Instant;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.Base64;
import java.util.Comparator;
import java.util.Date;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;

@Service
@Primary
public class DssPadesEmbeddingService implements PadesEmbeddingService {
    @Override
    public PadesPrepareResponse prepareExternalSignature(PadesPrepareRequest request) {
        DSSDocument document = decodePdfDocument(request.draftPdfBase64, request.draftFileName);
        int signerOrder = extractSignerOrder(request.activeSigner);
        SignatureSlot slot = resolveSignatureSlot(request.manifest, signerOrder);
        document = ensureFieldExists(document, slot);
        PAdESSignatureParameters parameters = buildParameters(slot, null);
        PAdESWithExternalCMSService service = new PAdESWithExternalCMSService();

        DSSMessageDigest messageDigest = service.getMessageDigest(document, parameters);
        byte[] digestBytes = messageDigest.getValue();

        PadesPrepareResponse response = new PadesPrepareResponse();
        response.status = "ok";
        response.message = "Prepared PDF signature digest";
        response.sessionId = UUID.randomUUID().toString();
        response.fieldName = slot.fieldName;
        response.payloadMode = "prepared_pdf_digest";
        response.signablePayloadBase64 = Base64.getEncoder().encodeToString(digestBytes);
        response.signablePayloadSha256 = sha256Hex(digestBytes);
        response.signingTime = DateTimeFormatter.ISO_INSTANT.format(Instant.now());
        response.evidence = new HashMap<>();
        response.evidence.put("digestAlgorithm", String.valueOf(messageDigest.getAlgorithm()));
        response.evidence.put("fieldName", slot.fieldName);
        response.evidence.put("signerOrder", signerOrder);
        return response;
    }

    @Override
    public PadesFinalizeResponse finalizeDetachedCms(PadesFinalizeRequest request) {
        DSSDocument currentDocument = decodePdfDocument(request.draftPdfBase64, request.draftFileName);
        List<SignerPayload> signedSigners = getSignedSigners(request.signers);
        if (signedSigners.isEmpty()) {
            return PadesFinalizeResponse.error("No signer CMS payloads were provided for PAdES finalization.");
        }

        PAdESWithExternalCMSService service = new PAdESWithExternalCMSService();
        for (SignerPayload signer : signedSigners) {
            SignatureSlot slot = resolveSignatureSlot(request.manifest, signer.order);
            currentDocument = ensureFieldExists(currentDocument, slot);
            PAdESSignatureParameters parameters = buildParameters(slot, signer.signedAt);
            byte[] cmsBytes = decodeBase64(signer.rawCmsBase64, "CMS");
            DSSDocument cmsDocument = new InMemoryDocument(cmsBytes, "signer-" + signer.order + ".p7s", MimeTypeEnum.BINARY);

            currentDocument = service.signDocument(currentDocument, parameters, cmsDocument);
        }

        byte[] signedPdf;
        try {
            signedPdf = currentDocument.openStream().readAllBytes();
        } catch (Exception e) {
            throw new IllegalStateException("Unable to read the signed PDF bytes from DSS.", e);
        }
        PadesFinalizeResponse response = new PadesFinalizeResponse();
        response.status = "ok";
        response.message = "Embedded detached CMS into PDF";
        response.filename = request.isFinal
            ? "signed_final_job_" + request.job.id + ".pdf"
            : "signed_progress_job_" + request.job.id + ".pdf";
        response.pdfBase64 = Base64.getEncoder().encodeToString(signedPdf);
        response.finalHash = sha256Hex(signedPdf);
        response.mode = "embedded_pades";
        response.source = "java_dss_pades_sidecar";
        response.evidence = new HashMap<>();
        response.evidence.put("embeddedSignerCount", signedSigners.size());
        response.evidence.put("fieldNames", signedSigners.stream().map(s -> resolveSignatureSlot(request.manifest, s.order).fieldName).toList());
        return response;
    }

    private DSSDocument decodePdfDocument(String pdfBase64, String fileName) {
        byte[] pdfBytes = decodeBase64(pdfBase64, "PDF");
        String resolvedName = (fileName == null || fileName.isBlank()) ? "document.pdf" : fileName;
        return new InMemoryDocument(pdfBytes, resolvedName, MimeTypeEnum.PDF);
    }

    private byte[] decodeBase64(String value, String label) {
        try {
            return Base64.getDecoder().decode((value == null ? "" : value).replaceAll("\\s+", ""));
        } catch (IllegalArgumentException e) {
            throw new IllegalArgumentException("Invalid base64 for " + label + ".", e);
        }
    }

    private List<SignerPayload> getSignedSigners(List<SignerPayload> signers) {
        List<SignerPayload> result = new ArrayList<>();
        if (signers == null) {
            return result;
        }
        for (SignerPayload signer : signers) {
            if (signer == null || signer.rawCmsBase64 == null || signer.rawCmsBase64.isBlank()) {
                continue;
            }
            result.add(signer);
        }
        result.sort(Comparator.comparingInt(s -> s.order));
        return result;
    }

    private PAdESSignatureParameters buildParameters(SignatureSlot slot, Long signedAtUnix) {
        PAdESSignatureParameters parameters = new PAdESSignatureParameters();
        parameters.setSignatureLevel(SignatureLevel.PAdES_BASELINE_B);
        parameters.setContentSize(32768);
        parameters.setReason("local_ncasign detached CMS embedding");
        SignatureFieldParameters fieldParameters = new SignatureFieldParameters();
        fieldParameters.setFieldId(slot.fieldName);
        fieldParameters.setPage(slot.page);
        fieldParameters.setOriginX(slot.originX);
        fieldParameters.setOriginY(slot.originY);
        fieldParameters.setWidth(slot.width);
        fieldParameters.setHeight(slot.height);
        SignatureImageParameters imageParameters = new SignatureImageParameters();
        imageParameters.setFieldParameters(fieldParameters);
        parameters.setImageParameters(imageParameters);
        if (signedAtUnix != null && signedAtUnix > 0) {
            parameters.bLevel().setSigningDate(new Date(signedAtUnix * 1000L));
        } else {
            parameters.bLevel().setSigningDate(new Date());
        }
        return parameters;
    }

    private DSSDocument ensureFieldExists(DSSDocument document, SignatureSlot slot) {
        PdfBoxSignatureService pdfService = new PdfBoxSignatureService(
            PDFServiceMode.SIGNATURE,
            new PdfBoxDefaultSignatureDrawerFactory()
        );
        List<String> availableFields = pdfService.getAvailableSignatureFields(document, null);
        if (availableFields != null && availableFields.contains(slot.fieldName)) {
            return document;
        }

        SignatureFieldParameters fieldParameters = new SignatureFieldParameters();
        fieldParameters.setFieldId(slot.fieldName);
        fieldParameters.setPage(slot.page);
        fieldParameters.setOriginX(slot.originX);
        fieldParameters.setOriginY(slot.originY);
        fieldParameters.setWidth(slot.width);
        fieldParameters.setHeight(slot.height);
        return pdfService.addNewSignatureField(document, fieldParameters, null);
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
        SignatureSlot fallback = new SignatureSlot("Signature" + signerOrder, 1, 36f, 36f, 180f, 48f);
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
                        return createSlotFromManifest(slot, fallback);
                    }
                }
                if (!slotList.isEmpty() && slotList.get(0) instanceof Map<?, ?> rawFirst) {
                    return createSlotFromManifest((Map<String, Object>) rawFirst, fallback);
                }
            }
        }
        return fallback;
    }

    private SignatureSlot createSlotFromManifest(Map<String, Object> slot, SignatureSlot fallback) {
        return new SignatureSlot(
            stringValue(slot.get("name"), fallback.fieldName),
            intValue(slot.get("page"), fallback.page),
            floatValue(slot.get("x"), fallback.originX),
            floatValue(slot.get("y"), fallback.originY),
            floatValue(slot.get("w"), fallback.width),
            floatValue(slot.get("h"), fallback.height)
        );
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

    private String stringValue(Object value, String fallback) {
        if (value instanceof String string && !string.isBlank()) {
            return string;
        }
        return fallback;
    }

    private int intValue(Object value, int fallback) {
        if (value instanceof Number number) {
            return number.intValue();
        }
        if (value instanceof String string && !string.isBlank()) {
            return Integer.parseInt(string);
        }
        return fallback;
    }

    private float floatValue(Object value, float fallback) {
        if (value instanceof Number number) {
            return number.floatValue();
        }
        if (value instanceof String string && !string.isBlank()) {
            return Float.parseFloat(string);
        }
        return fallback;
    }

    private record SignatureSlot(String fieldName, int page, float originX, float originY, float width, float height) { }
}
