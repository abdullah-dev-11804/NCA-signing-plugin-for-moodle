package kz.sental.ncasign.pades.service;

import kz.gov.pki.kalkan.jce.provider.KalkanProvider;
import kz.gov.pki.kalkan.asn1.ASN1InputStream;
import kz.gov.pki.kalkan.asn1.ASN1OctetString;
import kz.gov.pki.kalkan.asn1.DERIA5String;
import kz.gov.pki.kalkan.asn1.DERObject;
import kz.gov.pki.kalkan.asn1.x509.AccessDescription;
import kz.gov.pki.kalkan.asn1.x509.AuthorityInformationAccess;
import kz.gov.pki.kalkan.asn1.x509.GeneralName;
import kz.gov.pki.kalkan.asn1.x509.X509Extensions;
import kz.gov.pki.kalkan.jce.provider.cms.CMSSignedData;
import kz.gov.pki.kalkan.jce.provider.cms.SignerInformation;
import kz.gov.pki.kalkan.ocsp.BasicOCSPResp;
import kz.gov.pki.kalkan.ocsp.CertificateID;
import kz.gov.pki.kalkan.ocsp.OCSPReq;
import kz.gov.pki.kalkan.ocsp.OCSPReqGenerator;
import kz.gov.pki.kalkan.ocsp.OCSPResp;
import kz.gov.pki.kalkan.ocsp.OCSPRespStatus;
import kz.gov.pki.kalkan.ocsp.SingleResp;
import kz.gov.pki.kalkan.tsp.TimeStampToken;
import kz.gov.pki.provider.utils.TSPUtil;
import kz.gov.pki.provider.utils.X509Util;
import kz.gov.pki.provider.utils.CMSUtil;
import kz.sental.ncasign.pades.model.PadesFinalizeRequest;
import kz.sental.ncasign.pades.model.PadesFinalizeResponse;
import kz.sental.ncasign.pades.model.PadesPrepareRequest;
import kz.sental.ncasign.pades.model.PadesPrepareResponse;
import kz.sental.ncasign.pades.model.SignerPayload;
import kz.sental.ncasign.pades.model.PadesVerifyRequest;
import kz.sental.ncasign.pades.model.PadesVerifyResponse;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.cos.COSArray;
import org.apache.pdfbox.cos.COSBase;
import org.apache.pdfbox.cos.COSDictionary;
import org.apache.pdfbox.cos.COSStream;
import org.apache.pdfbox.cos.COSUpdateInfo;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDDocumentCatalog;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.ExternalSigningSupport;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureOptions;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.context.annotation.Primary;
import org.springframework.stereotype.Service;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.cert.X509Certificate;
import java.security.cert.X509CRL;
import java.security.MessageDigest;
import java.security.Provider;
import java.security.Security;
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
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.ArrayList;
import java.util.Set;

@Service
@Primary
public class PdfBoxPadesEmbeddingService implements PadesEmbeddingService {
    private static final int PREFERRED_SIGNATURE_SIZE = 131072;
    private static final COSName PDF_SUBFILTER_CADES_DETACHED = COSName.getPDFName("ETSI.CAdES.detached");
    private static final Logger LOGGER = LoggerFactory.getLogger(PdfBoxPadesEmbeddingService.class);

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
        LOGGER.info(
            "Prepared PDFBox external signing session {} for signer #{} field {} contentLength={} contentSha256={}",
            sessionId,
            signerOrder,
            slot.fieldName,
            session.contentToSign.length,
            sha256Hex(session.contentToSign)
        );

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

        String phase = "decode_cms";
        try {
            byte[] cmsBytes = decodeBase64(signer.rawCmsBase64, "CMS");
            phase = "verify_prepared_content";
            verifyCmsAgainstPreparedContent(cmsBytes, session, signer);
            phase = "embed_signature";
            session.externalSigning.setSignature(cmsBytes);
            byte[] signedPdf = session.output.toByteArray();
            phase = "verify_embedded_signature";
            verifyEmbeddedPdfSignature(signedPdf, signer, session);
            LOGGER.info(
                "Embedded CMS for signer #{} field {} session {} finalPdfSha256={}",
                signer.order,
                session.fieldName,
                sessionId,
                sha256Hex(signedPdf)
            );

            phase = "encode_response";
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
            response.evidence.put("cmsVerifiedBy", KalkanProvider.PROVIDER_NAME);
            response.evidence.put("cmsVerifiedAgainst", "pdfbox_content_to_sign");
            response.evidence.put("contentToSignSha256", sha256Hex(session.contentToSign));
            response.evidence.put("embeddedPdfVerification", "kalkan_ok");
            return response;
        } catch (Throwable e) {
            throw new IllegalStateException(
                "PDFBox external signing failed for signer #" + signer.order + " during " + phase + ": " + rootMessage(e),
                e
            );
        } finally {
            session.close();
        }
    }

    @Override
    public PadesVerifyResponse verifySignedPdf(PadesVerifyRequest request) {
        byte[] pdfBytes = decodeBase64(request.pdfBase64, "PDF");
        Provider provider = ensureKalkanProvider();
        PadesVerifyResponse response = new PadesVerifyResponse();
        response.status = "ok";
        response.message = "Verified embedded PDF signatures with Kalkan";
        response.filename = request.filename;
        response.pdfSha256 = sha256Hex(pdfBytes);
        response.allValid = true;

        try (PDDocument document = PDDocument.load(pdfBytes)) {
            List<PDSignature> signatures = document.getSignatureDictionaries();
            response.signatureCount = signatures == null ? 0 : signatures.size();
            if (signatures == null || signatures.isEmpty()) {
                response.allValid = false;
                response.message = "No embedded PDF signatures were found.";
                return response;
            }

            int index = 1;
            for (PDSignature signature : signatures) {
                Map<String, Object> item = verifyEmbeddedSignature(pdfBytes, signature, index, provider);
                response.signatures.add(item);
                if (!Boolean.TRUE.equals(item.get("valid"))) {
                    response.allValid = false;
                }
                index++;
            }
        } catch (Throwable e) {
            throw new IllegalStateException("Unable to verify signed PDF: " + rootMessage(e), e);
        }

        response.evidence.put("provider", KalkanProvider.PROVIDER_NAME);
        response.evidence.put("pdfSha256", response.pdfSha256);
        response.evidence.put("signatureCount", response.signatureCount);
        if (!response.allValid) {
            response.message = "One or more embedded PDF signatures failed Kalkan verification.";
        }
        return response;
    }

    private LtvAugmentationResult augmentPdfWithLtvEvidence(
        byte[] signedPdf,
        List<SignerPayload> signers,
        Provider provider,
        boolean isFinal
    ) {
        Map<String, Object> evidence = new LinkedHashMap<>();
        evidence.put("mode", isFinal ? "final" : "progress");
        if (!isFinal) {
            evidence.put("status", "skipped");
            evidence.put("reason", "LTV packaging is deferred until the final signed PDF.");
            return new LtvAugmentationResult(signedPdf, false, "deferred_until_final", evidence);
        }

        try (PDDocument document = PDDocument.load(signedPdf)) {
            List<PDSignature> signatures = document.getSignatureDictionaries();
            if (signatures == null || signatures.isEmpty()) {
                evidence.put("status", "skipped");
                evidence.put("reason", "No embedded signatures found for LTV packaging.");
                return new LtvAugmentationResult(signedPdf, false, "no_embedded_signatures", evidence);
            }

            PDDocumentCatalog catalog = document.getDocumentCatalog();
            COSDictionary catalogDictionary = catalog.getCOSObject();
            catalogDictionary.setNeedToBeUpdated(true);

            COSDictionary dss = getOrCreateDictionaryEntry(COSDictionary.class, catalogDictionary, "DSS");
            addExtensions(catalog);
            COSDictionary vriBase = getOrCreateDictionaryEntry(COSDictionary.class, dss, "VRI");
            COSArray certs = getOrCreateDictionaryEntry(COSArray.class, dss, "Certs");
            COSArray crls = getOrCreateDictionaryEntry(COSArray.class, dss, "CRLs");
            COSArray ocsps = getOrCreateDictionaryEntry(COSArray.class, dss, "OCSPs");

            Map<String, COSStream> certMap = new LinkedHashMap<>();
            Map<String, COSStream> crlMap = new LinkedHashMap<>();
            Map<String, COSStream> ocspMap = new LinkedHashMap<>();
            List<Map<String, Object>> signerEvidence = new ArrayList<>();

            int count = Math.min(signatures.size(), signers == null ? 0 : signers.size());
            for (int i = 0; i < count; i++) {
                SignerPayload signer = signers.get(i);
                if (signer == null || signer.rawCmsBase64 == null || signer.rawCmsBase64.isBlank()) {
                    continue;
                }
                PDSignature signature = signatures.get(i);
                byte[] cmsBytes = decodeBase64(signer.rawCmsBase64, "CMS");
                SignerLtvEvidence signerLtv = collectSignerLtvEvidence(cmsBytes, provider);
                signerEvidence.add(signerLtv.toMap(i + 1, signature.getSubFilter()));

                COSDictionary vri = new COSDictionary();
                vri.setDirect(false);
                vriBase.setItem(computeVriKey(signature, signedPdf), vri);
                vri.setNeedToBeUpdated(true);

                COSArray correspondingCerts = new COSArray();
                correspondingCerts.setNeedToBeUpdated(true);
                for (X509Certificate certificate : signerLtv.certificates) {
                    COSStream stream = getOrCreateCertificateStream(document, certMap, certificate);
                    correspondingCerts.add(stream);
                }
                if (correspondingCerts.size() > 0) {
                    vri.setItem(COSName.CERT, correspondingCerts);
                }

                COSArray correspondingCrls = new COSArray();
                correspondingCrls.setNeedToBeUpdated(true);
                for (X509CRL crl : signerLtv.crls) {
                    COSStream stream = getOrCreateCrlStream(document, crlMap, crl);
                    correspondingCrls.add(stream);
                }
                if (correspondingCrls.size() > 0) {
                    vri.setItem(COSName.getPDFName("CRL"), correspondingCrls);
                }

                if (!signerLtv.ocspResponses.isEmpty()) {
                    COSArray correspondingOcsps = new COSArray();
                    correspondingOcsps.setNeedToBeUpdated(true);
                    for (byte[] ocsp : signerLtv.ocspResponses) {
                        COSStream stream = getOrCreateOcspStream(document, ocspMap, ocsp);
                        correspondingOcsps.add(stream);
                    }
                    vri.setItem(COSName.getPDFName("OCSP"), correspondingOcsps);
                }

                vri.setDate(COSName.TU, Calendar.getInstance());
            }

            certMap.values().forEach(certs::add);
            crlMap.values().forEach(crls::add);
            ocspMap.values().forEach(ocsps::add);

            ByteArrayOutputStream output = new ByteArrayOutputStream();
            document.saveIncremental(output);
            evidence.put("status", "applied");
            evidence.put("signers", signerEvidence);
            evidence.put("certCount", certMap.size());
            evidence.put("crlCount", crlMap.size());
            evidence.put("ocspCount", ocspMap.size());
            evidence.put("timestampTokenCount", signerEvidence.stream()
                .mapToInt(item -> ((Number)item.getOrDefault("timestampTokenCount", 0)).intValue())
                .sum());
            evidence.put("dss", true);
            evidence.put("vriCount", signerEvidence.size());
            return new LtvAugmentationResult(output.toByteArray(), true, "dss_vri_with_certs_crls_ocsp", evidence);
        } catch (Throwable e) {
            evidence.put("status", "failed");
            evidence.put("error", rootMessage(e));
            LOGGER.warn("LTV augmentation failed: {}", rootMessage(e));
            return new LtvAugmentationResult(signedPdf, false, "ltv_augmentation_failed", evidence);
        }
    }

    private PreparedSigningSession createSession(String sessionId, byte[] pdfBytes, String signerName, Date signingDate, SignatureSlot slot) {
        try {
            PDDocument document = PDDocument.load(pdfBytes);
            PDSignature signature = new PDSignature();
            signature.setFilter(PDSignature.FILTER_ADOBE_PPKLITE);
            signature.setSubFilter(PDF_SUBFILTER_CADES_DETACHED);
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

    private void verifyCmsAgainstPreparedContent(byte[] cmsBytes, PreparedSigningSession session, SignerPayload signer) {
        try {
            Provider provider = ensureKalkanProvider();
            CMSUtil.verifyCMS(cmsBytes, session.contentToSign, provider);
            LOGGER.info(
                "Kalkan CMS verification passed for signer #{} field {} session {} cmsLength={} contentSha256={}",
                signer.order,
                session.fieldName,
                session.sessionId,
                cmsBytes.length,
                sha256Hex(session.contentToSign)
            );
        } catch (Exception e) {
            throw new IllegalStateException(
                "Signer #" + signer.order + " CMS does not verify against the prepared PDF ByteRange content: "
                    + rootMessage(e),
                e
            );
        }
    }

    private void verifyEmbeddedPdfSignature(byte[] signedPdf, SignerPayload signer, PreparedSigningSession session) {
        try (PDDocument document = PDDocument.load(signedPdf)) {
            List<PDSignature> signatures = document.getSignatureDictionaries();
            if (signatures == null || signatures.isEmpty()) {
                throw new IllegalStateException("No embedded PDF signature dictionaries were found after external signing.");
            }

            PDSignature embeddedSignature = signatures.get(signatures.size() - 1);
            byte[] signedContent = embeddedSignature.getSignedContent(signedPdf);
            byte[] embeddedCms = embeddedSignature.getContents(signedPdf);
            Provider provider = ensureKalkanProvider();
            CMSUtil.verifyCMS(embeddedCms, signedContent, provider);
            LOGGER.info(
                "Embedded PDF signature verified by Kalkan for signer #{} field {} session {} embeddedCmsLength={} signedContentSha256={}",
                signer.order,
                session.fieldName,
                session.sessionId,
                embeddedCms.length,
                sha256Hex(signedContent)
            );
        } catch (Exception e) {
            throw new IllegalStateException(
                "Signer #" + signer.order + " CMS was embedded into the PDF, but the embedded PDF signature does not verify: "
                    + rootMessage(e),
                e
            );
        }
    }

    private Map<String, Object> verifyEmbeddedSignature(byte[] pdfBytes, PDSignature signature, int index, Provider provider) {
        Map<String, Object> item = new HashMap<>();
        item.put("index", index);
        item.put("fieldName", "Signature" + index);
        item.put("name", signature.getName());
        item.put("subFilter", signature.getSubFilter());
        item.put("signDate", signature.getSignDate() != null ? signature.getSignDate().getTimeInMillis() / 1000L : null);
        item.put("byteRange", signature.getByteRange());

        try {
            byte[] signedContent = signature.getSignedContent(pdfBytes);
            byte[] embeddedCms = signature.getContents(pdfBytes);
            CMSUtil.verifyCMS(embeddedCms, signedContent, provider);
            item.put("valid", true);
            item.put("signedContentSha256", sha256Hex(signedContent));
            item.put("cmsSha256", sha256Hex(embeddedCms));
            item.put("cmsLength", embeddedCms.length);

            // Keep verify endpoint focused on cryptographic signature + TSA proof.
            // Online OCSP fetching is intentionally skipped here so network/revocation
            // lookup issues cannot break the engineer verification workflow.
            SignerLtvEvidence cmsEvidence = collectSignerEvidence(embeddedCms, provider, false);
            item.putAll(cmsEvidence.toVerificationMap());
            LOGGER.info(
                "Verified embedded signature #{} field {} cmsSha256={} signedContentSha256={}",
                index,
                "Signature" + index,
                item.get("cmsSha256"),
                item.get("signedContentSha256")
            );
        } catch (Throwable e) {
            item.put("valid", false);
            item.put("error", rootMessage(e));
            LOGGER.warn(
                "Embedded signature verification failed for signature #{} field {}: {}",
                index,
                "Signature" + index,
                rootMessage(e)
            );
        }

        return item;
    }

    private SignerLtvEvidence collectSignerLtvEvidence(byte[] cmsBytes, Provider provider) throws Exception {
        return collectSignerEvidence(cmsBytes, provider, true);
    }

    private SignerLtvEvidence collectSignerEvidence(byte[] cmsBytes, Provider provider, boolean includeOnlineRevocationEvidence) throws Exception {
        SignerLtvEvidence evidence = new SignerLtvEvidence();
        CMSSignedData cms = CMSUtil.parseAsCMS(cmsBytes);
        List<X509Certificate> allCertificates = CMSUtil.getX509Certificates(cms, provider);
        if (allCertificates != null) {
            for (X509Certificate certificate : allCertificates) {
                evidence.addCertificate(certificate);
            }
        }
        List<X509Certificate> signerCertificates = CMSUtil.getSignerCertificates(cms, provider);
        if (signerCertificates != null && !signerCertificates.isEmpty()) {
            X509Certificate signerCertificate = signerCertificates.get(0);
            evidence.signerSubjectDn = signerCertificate.getSubjectX500Principal().getName();
            evidence.signerIssuerDn = signerCertificate.getIssuerX500Principal().getName();
            evidence.signerSerialNumber = signerCertificate.getSerialNumber().toString();
            evidence.signerNotBefore = signerCertificate.getNotBefore().toInstant().toString();
            evidence.signerNotAfter = signerCertificate.getNotAfter().toInstant().toString();
        }

        List<SignerInformation> signerInfos = CMSUtil.getSignerInformations(cms);
        if (signerInfos != null) {
            for (SignerInformation signerInformation : signerInfos) {
                try {
                    TimeStampToken token = CMSUtil.getTimestampToken(signerInformation, provider);
                    if (token != null) {
                        evidence.timestampTokens.add(token.getEncoded());
                        evidence.timestampPresent = true;
                        try {
                            Date genTime = token.getTimeStampInfo().getGenTime();
                            if (genTime != null) {
                                evidence.timestampGenTimes.add(genTime.toInstant().toString());
                            }
                        } catch (Exception ignored) {
                            // Keep timestamp token presence even if genTime is unavailable.
                        }
                        CMSSignedData tsCms = token.toCMSSignedData();
                        List<X509Certificate> tspSignerCerts = CMSUtil.getSignerCertificates(tsCms, provider);
                        if (tspSignerCerts != null) {
                            for (X509Certificate tspSignerCert : tspSignerCerts) {
                                evidence.timestampAuthorities.add(tspSignerCert.getSubjectX500Principal().getName());
                                evidence.addCertificate(tspSignerCert);
                            }
                        }
                        List<X509Certificate> tspCerts = CMSUtil.getX509Certificates(tsCms, provider);
                        if (tspCerts != null) {
                            for (X509Certificate tspCert : tspCerts) {
                                evidence.addCertificate(tspCert);
                            }
                        }
                    }
                } catch (Exception ex) {
                    evidence.timestampErrors.add(rootMessage(ex));
                }
            }
        }

        if (!includeOnlineRevocationEvidence) {
            return evidence;
        }

        List<X509Certificate> certificateSnapshot = new ArrayList<>(evidence.certificates);
        for (X509Certificate certificate : certificateSnapshot) {
            X509Certificate issuer = findIssuerCertificate(certificate, evidence.certificates, provider);
            if (issuer == null) {
                continue;
            }
            try {
                byte[] ocspResponse = fetchOcspResponse(certificate, issuer, provider, evidence);
                if (ocspResponse != null && ocspResponse.length > 0) {
                    evidence.ocspResponses.add(ocspResponse);
                }
            } catch (Exception ex) {
                evidence.ocspErrors.add(certificate.getSubjectX500Principal().getName() + ": " + rootMessage(ex));
            }
        }

        Set<String> seenCrlUrls = new LinkedHashSet<>();
        for (X509Certificate certificate : new ArrayList<>(evidence.certificates)) {
            try {
                List<java.net.URL> urls = X509Util.getCrlURLs(certificate, true);
                if (urls == null) {
                    continue;
                }
                for (java.net.URL url : urls) {
                    String key = url.toString();
                    if (!seenCrlUrls.add(key)) {
                        continue;
                    }
                    evidence.crlUrls.add(key);
                    try {
                        X509CRL crl = X509Util.loadX509CRL(url, provider);
                        if (crl != null) {
                            evidence.addCrl(crl);
                        }
                    } catch (Exception ex) {
                        evidence.crlErrors.add(key + ": " + rootMessage(ex));
                    }
                }
            } catch (Exception ex) {
                evidence.crlErrors.add(certificate.getSubjectX500Principal().getName() + ": " + rootMessage(ex));
            }
        }

        return evidence;
    }

    private X509Certificate findIssuerCertificate(X509Certificate certificate, List<X509Certificate> certificates, Provider provider) {
        for (X509Certificate candidate : certificates) {
            if (sameCertificate(candidate, certificate)) {
                continue;
            }
            if (!certificate.getIssuerX500Principal().equals(candidate.getSubjectX500Principal())) {
                continue;
            }
            try {
                certificate.verify(candidate.getPublicKey(), provider.getName());
                return candidate;
            } catch (Exception ignored) {
                // Keep looking for a working issuer candidate.
            }
        }
        return null;
    }

    private byte[] fetchOcspResponse(
        X509Certificate certificate,
        X509Certificate issuer,
        Provider provider,
        SignerLtvEvidence evidence
    ) throws Exception {
        String ocspUrl = extractOcspUrl(certificate);
        if (ocspUrl == null || ocspUrl.isBlank()) {
            return null;
        }

        evidence.ocspUrls.add(ocspUrl);
        OCSPReq request = buildOcspRequest(certificate, issuer);
        byte[] responseBytes = postOcspRequest(ocspUrl, request.getEncoded());
        OCSPResp response = new OCSPResp(responseBytes);
        if (response.getStatus() != OCSPRespStatus.SUCCESSFUL) {
            throw new IllegalStateException("OCSP responder returned status " + response.getStatus());
        }

        Object responseObject = response.getResponseObject();
        if (!(responseObject instanceof BasicOCSPResp basicResponse)) {
            throw new IllegalStateException("OCSP responder did not return BasicOCSPResp.");
        }

        SingleResp[] singleResponses = basicResponse.getResponses();
        boolean matched = false;
        if (singleResponses != null) {
            for (SingleResp single : singleResponses) {
                if (single.getCertID() != null && certificate.getSerialNumber().equals(single.getCertID().getSerialNumber())) {
                    matched = true;
                    break;
                }
            }
        }
        if (!matched) {
            throw new IllegalStateException("OCSP response did not include the requested certificate serial number.");
        }

        Map<String, Object> ocspDetail = new LinkedHashMap<>();
        ocspDetail.put("url", ocspUrl);
        ocspDetail.put("sha256", sha256Hex(response.getEncoded()));
        ocspDetail.put("certificateSerialNumber", certificate.getSerialNumber().toString());
        ocspDetail.put("status", "good");
        if (singleResponses != null) {
            for (SingleResp single : singleResponses) {
                if (single.getCertID() == null || !certificate.getSerialNumber().equals(single.getCertID().getSerialNumber())) {
                    continue;
                }
                if (single.getThisUpdate() != null) {
                    ocspDetail.put("thisUpdate", single.getThisUpdate().toInstant().toString());
                }
                if (single.getNextUpdate() != null) {
                    ocspDetail.put("nextUpdate", single.getNextUpdate().toInstant().toString());
                }
                break;
            }
        }
        evidence.ocspDetails.add(ocspDetail);

        try {
            X509Certificate[] ocspCertificates = basicResponse.getCerts(provider.getName());
            if (ocspCertificates != null) {
                for (X509Certificate ocspCertificate : ocspCertificates) {
                    evidence.addCertificate(ocspCertificate);
                }
            }
        } catch (Exception ex) {
            evidence.ocspErrors.add("responder certs: " + rootMessage(ex));
        }

        return response.getEncoded();
    }

    private OCSPReq buildOcspRequest(X509Certificate certificate, X509Certificate issuer) throws Exception {
        List<String> hashAlgorithms = List.of(
            CertificateID.HASH_SHA1,
            CertificateID.HASH_SHA256,
            CertificateID.HASH_GOST34311GT,
            CertificateID.HASH_GOST34311
        );

        Exception lastError = null;
        for (String hashAlgorithm : hashAlgorithms) {
            try {
                OCSPReqGenerator generator = new OCSPReqGenerator();
                generator.addRequest(new CertificateID(hashAlgorithm, issuer, certificate.getSerialNumber()));
                return generator.generate();
            } catch (Exception ex) {
                lastError = ex;
            }
        }

        throw new IllegalStateException("Unable to build OCSP request for certificate " +
            certificate.getSubjectX500Principal().getName() + ": " + rootMessage(lastError));
    }

    private byte[] postOcspRequest(String ocspUrl, byte[] requestBytes) throws IOException {
        HttpURLConnection connection = (HttpURLConnection) new URL(ocspUrl).openConnection();
        connection.setConnectTimeout(15000);
        connection.setReadTimeout(15000);
        connection.setRequestMethod("POST");
        connection.setDoOutput(true);
        connection.setRequestProperty("Content-Type", "application/ocsp-request");
        connection.setRequestProperty("Accept", "application/ocsp-response");
        connection.setRequestProperty("Content-Length", Integer.toString(requestBytes.length));
        try (OutputStream os = connection.getOutputStream()) {
            os.write(requestBytes);
        }

        int status = connection.getResponseCode();
        InputStream responseStream = status >= 200 && status < 300
            ? connection.getInputStream()
            : connection.getErrorStream();
        if (responseStream == null) {
            throw new IOException("OCSP responder returned HTTP " + status + " without a response body.");
        }
        try (InputStream is = responseStream) {
            byte[] response = is.readAllBytes();
            if (status < 200 || status >= 300) {
                throw new IOException("OCSP responder returned HTTP " + status + ".");
            }
            return response;
        } finally {
            connection.disconnect();
        }
    }

    private String extractOcspUrl(X509Certificate certificate) {
        try {
            byte[] extensionValue = certificate.getExtensionValue(X509Extensions.AuthorityInfoAccess.getId());
            if (extensionValue == null || extensionValue.length == 0) {
                return null;
            }

            try (ASN1InputStream outer = new ASN1InputStream(extensionValue)) {
                DERObject outerObject = outer.readObject();
                if (!(outerObject instanceof ASN1OctetString octetString)) {
                    return null;
                }
                try (ASN1InputStream inner = new ASN1InputStream(octetString.getOctets())) {
                    AuthorityInformationAccess aia = AuthorityInformationAccess.getInstance(inner.readObject());
                    if (aia == null || aia.getAccessDescriptions() == null) {
                        return null;
                    }
                    for (AccessDescription description : aia.getAccessDescriptions()) {
                        if (description == null || !AccessDescription.id_ad_ocsp.equals(description.getAccessMethod())) {
                            continue;
                        }
                        GeneralName location = description.getAccessLocation();
                        if (location == null) {
                            continue;
                        }
                        String uri = extractGeneralNameUri(location);
                        if (uri != null && !uri.isBlank()) {
                            return uri;
                        }
                    }
                }
            }
        } catch (Exception e) {
            LOGGER.debug("Failed to extract OCSP URL from certificate {}: {}",
                certificate.getSubjectX500Principal().getName(), rootMessage(e));
        }
        return null;
    }

    private String extractGeneralNameUri(GeneralName name) {
        if (name == null || name.getTagNo() != GeneralName.uniformResourceIdentifier) {
            return null;
        }
        Object value = name.getName();
        if (value instanceof DERIA5String ia5) {
            return ia5.getString();
        }
        String raw = value != null ? value.toString() : null;
        if (raw == null || raw.isBlank()) {
            return null;
        }
        return raw.replaceFirst("^[0-9]+:\\s*", "");
    }

    private boolean sameCertificate(X509Certificate left, X509Certificate right) {
        if (left == null || right == null) {
            return false;
        }
        try {
            return MessageDigest.isEqual(left.getEncoded(), right.getEncoded());
        } catch (Exception e) {
            return left.getSerialNumber().equals(right.getSerialNumber())
                && left.getSubjectX500Principal().equals(right.getSubjectX500Principal());
        }
    }

    private String computeVriKey(PDSignature signature, byte[] pdfBytes) throws Exception {
        byte[] contents = signature.getContents(pdfBytes);
        kz.gov.pki.kalkan.asn1.DEROctetString encodedSignature = new kz.gov.pki.kalkan.asn1.DEROctetString(contents);
        return sha1Hex(encodedSignature.getEncoded());
    }

    private COSStream getOrCreateCertificateStream(PDDocument document, Map<String, COSStream> certMap, X509Certificate certificate)
        throws Exception {
        String key = certificate.getSerialNumber().toString(16) + "|" + certificate.getSubjectX500Principal().getName();
        COSStream existing = certMap.get(key);
        if (existing != null) {
            return existing;
        }
        COSStream stream = writeDataToStream(document, certificate.getEncoded());
        certMap.put(key, stream);
        return stream;
    }

    private COSStream getOrCreateCrlStream(PDDocument document, Map<String, COSStream> crlMap, X509CRL crl)
        throws Exception {
        String key = crl.getIssuerX500Principal().getName() + "|" + crl.getThisUpdate().getTime();
        COSStream existing = crlMap.get(key);
        if (existing != null) {
            return existing;
        }
        COSStream stream = writeDataToStream(document, crl.getEncoded());
        crlMap.put(key, stream);
        return stream;
    }

    private COSStream getOrCreateOcspStream(PDDocument document, Map<String, COSStream> ocspMap, byte[] ocsp)
        throws Exception {
        String key = sha256Hex(ocsp);
        COSStream existing = ocspMap.get(key);
        if (existing != null) {
            return existing;
        }
        COSStream stream = writeDataToStream(document, ocsp);
        ocspMap.put(key, stream);
        return stream;
    }

    private COSStream writeDataToStream(PDDocument document, byte[] data) throws IOException {
        COSStream stream = document.getDocument().createCOSStream();
        try (java.io.OutputStream os = stream.createOutputStream(COSName.FLATE_DECODE)) {
            os.write(data);
        }
        stream.setNeedToBeUpdated(true);
        return stream;
    }

    private void addExtensions(PDDocumentCatalog catalog) {
        COSDictionary catalogDictionary = catalog.getCOSObject();
        COSDictionary dssExtensions = new COSDictionary();
        dssExtensions.setDirect(true);
        catalogDictionary.setItem(COSName.getPDFName("Extensions"), dssExtensions);

        COSDictionary adbeExtension = new COSDictionary();
        adbeExtension.setDirect(true);
        dssExtensions.setItem(COSName.getPDFName("ADBE"), adbeExtension);
        adbeExtension.setName(COSName.getPDFName("BaseVersion"), "1.7");
        adbeExtension.setInt(COSName.getPDFName("ExtensionLevel"), 5);
        catalog.setVersion("1.7");
    }

    private static <T extends COSBase & COSUpdateInfo> T getOrCreateDictionaryEntry(
        Class<T> clazz,
        COSDictionary parent,
        String name
    ) throws IOException {
        COSBase element = parent.getDictionaryObject(name);
        if (element != null && clazz.isInstance(element)) {
            T result = clazz.cast(element);
            result.setNeedToBeUpdated(true);
            return result;
        }
        if (element != null) {
            throw new IOException("Element " + name + " from dictionary is not of expected type.");
        }
        try {
            T result = clazz.getDeclaredConstructor().newInstance();
            result.setDirect(false);
            parent.setItem(COSName.getPDFName(name), result);
            return result;
        } catch (ReflectiveOperationException e) {
            throw new IOException("Failed to create dictionary entry " + name, e);
        }
    }

    private Provider ensureKalkanProvider() {
        Provider provider = Security.getProvider(KalkanProvider.PROVIDER_NAME);
        if (provider != null) {
            return provider;
        }
        synchronized (PdfBoxPadesEmbeddingService.class) {
            provider = Security.getProvider(KalkanProvider.PROVIDER_NAME);
            if (provider == null) {
                provider = new KalkanProvider();
                Security.addProvider(provider);
            }
            return provider;
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

    private static String sha256Hex(byte[] bytes) {
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

    private static String sha1Hex(byte[] bytes) {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-1");
            byte[] hash = digest.digest(bytes);
            StringBuilder builder = new StringBuilder(hash.length * 2);
            for (byte item : hash) {
                builder.append(String.format("%02x", item));
            }
            return builder.toString().toUpperCase();
        } catch (Exception e) {
            throw new IllegalStateException("Unable to compute SHA-1.", e);
        }
    }

    private String rootMessage(Throwable throwable) {
        Throwable current = throwable;
        while (current.getCause() != null && current.getCause() != current) {
            current = current.getCause();
        }
        String message = current.getMessage();
        if (message == null || message.isBlank()) {
            message = current.getClass().getName();
        }
        return message;
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

    private static final class LtvAugmentationResult {
        private final byte[] pdfBytes;
        private final boolean applied;
        private final String reason;
        private final Map<String, Object> evidence;

        private LtvAugmentationResult(byte[] pdfBytes, boolean applied, String reason, Map<String, Object> evidence) {
            this.pdfBytes = pdfBytes;
            this.applied = applied;
            this.reason = reason;
            this.evidence = evidence;
        }
    }

    private static final class SignerLtvEvidence {
        private final List<X509Certificate> certificates = new ArrayList<>();
        private final List<X509CRL> crls = new ArrayList<>();
        private final List<byte[]> ocspResponses = new ArrayList<>();
        private final List<byte[]> timestampTokens = new ArrayList<>();
        private final List<Map<String, Object>> ocspDetails = new ArrayList<>();
        private final List<String> ocspUrls = new ArrayList<>();
        private final List<String> ocspErrors = new ArrayList<>();
        private final List<String> crlUrls = new ArrayList<>();
        private final List<String> crlErrors = new ArrayList<>();
        private final List<String> timestampAuthorities = new ArrayList<>();
        private final List<String> timestampGenTimes = new ArrayList<>();
        private final List<String> timestampErrors = new ArrayList<>();
        private final Set<String> certificateFingerprints = new LinkedHashSet<>();
        private final Set<String> crlFingerprints = new LinkedHashSet<>();
        private boolean timestampPresent = false;
        private String signerSubjectDn;
        private String signerIssuerDn;
        private String signerSerialNumber;
        private String signerNotBefore;
        private String signerNotAfter;

        private void addCertificate(X509Certificate certificate) {
            if (certificate == null) {
                return;
            }
            try {
                String fingerprint = sha256Hex(certificate.getEncoded());
                if (certificateFingerprints.add(fingerprint)) {
                    certificates.add(certificate);
                }
            } catch (Exception ignored) {
                String fallback = certificate.getSerialNumber().toString(16) + "|" + certificate.getSubjectX500Principal().getName();
                if (certificateFingerprints.add(fallback)) {
                    certificates.add(certificate);
                }
            }
        }

        private void addCrl(X509CRL crl) {
            if (crl == null) {
                return;
            }
            try {
                String fingerprint = sha256Hex(crl.getEncoded());
                if (crlFingerprints.add(fingerprint)) {
                    crls.add(crl);
                }
            } catch (Exception ignored) {
                String fallback = crl.getIssuerX500Principal().getName() + "|" + crl.getThisUpdate().getTime();
                if (crlFingerprints.add(fallback)) {
                    crls.add(crl);
                }
            }
        }

        private Map<String, Object> toMap(int index, String subFilter) {
            Map<String, Object> item = new LinkedHashMap<>();
            item.put("index", index);
            item.put("subFilter", subFilter);
            item.putAll(toVerificationMap());
            return item;
        }

        private Map<String, Object> toVerificationMap() {
            Map<String, Object> item = new LinkedHashMap<>();
            item.put("valid", true);
            if (signerSubjectDn != null && !signerSubjectDn.isBlank()) {
                item.put("certificateSubjectDn", signerSubjectDn);
            }
            if (signerIssuerDn != null && !signerIssuerDn.isBlank()) {
                item.put("certificateIssuerDn", signerIssuerDn);
            }
            if (signerSerialNumber != null && !signerSerialNumber.isBlank()) {
                item.put("certificateSerialNumber", signerSerialNumber);
            }
            if (signerNotBefore != null && !signerNotBefore.isBlank()) {
                item.put("certificateNotBefore", signerNotBefore);
            }
            if (signerNotAfter != null && !signerNotAfter.isBlank()) {
                item.put("certificateNotAfter", signerNotAfter);
            }
            item.put("certificateCount", certificates.size());
            item.put("crlCount", crls.size());
            item.put("ocspCount", ocspResponses.size());
            item.put("timestampPresent", timestampPresent);
            item.put("timestampTokenCount", timestampTokens.size());
            item.put("ocspResponseCount", ocspResponses.size());
            if (!ocspUrls.isEmpty()) {
                item.put("ocspUrls", new ArrayList<>(ocspUrls));
            }
            if (!ocspDetails.isEmpty()) {
                item.put("ocspDetails", new ArrayList<>(ocspDetails));
            }
            if (!crlUrls.isEmpty()) {
                item.put("crlUrls", new ArrayList<>(crlUrls));
            }
            if (!ocspErrors.isEmpty()) {
                item.put("ocspErrors", new ArrayList<>(ocspErrors));
            }
            if (!crlErrors.isEmpty()) {
                item.put("crlErrors", new ArrayList<>(crlErrors));
            }
            if (!timestampAuthorities.isEmpty()) {
                item.put("timestampAuthorities", new ArrayList<>(timestampAuthorities));
                item.put("timestampAuthority", timestampAuthorities.get(0));
            }
            if (!timestampGenTimes.isEmpty()) {
                item.put("timestampGenTimes", new ArrayList<>(timestampGenTimes));
                item.put("timestampGenTime", timestampGenTimes.get(0));
            }
            if (!timestampErrors.isEmpty()) {
                item.put("timestampErrors", new ArrayList<>(timestampErrors));
            }
            if (!timestampTokens.isEmpty()) {
                List<String> tokenHashes = new ArrayList<>();
                for (byte[] token : timestampTokens) {
                    tokenHashes.add(sha256Hex(token));
                }
                item.put("timestampTokenSha256", tokenHashes);
            }
            if (!ocspResponses.isEmpty()) {
                List<String> responseHashes = new ArrayList<>();
                for (byte[] response : ocspResponses) {
                    responseHashes.add(sha256Hex(response));
                }
                item.put("ocspResponseSha256", responseHashes);
            }
            return item;
        }

    }

    private record SignatureSlot(String fieldName) { }
}
