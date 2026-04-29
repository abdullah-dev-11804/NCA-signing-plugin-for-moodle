package kz.sental.ncasign.pades.service;

import kz.gov.pki.kalkan.jce.provider.KalkanProvider;
import kz.gov.pki.kalkan.asn1.ASN1InputStream;
import kz.gov.pki.kalkan.asn1.ASN1OctetString;
import kz.gov.pki.kalkan.asn1.DERIA5String;
import kz.gov.pki.kalkan.asn1.DERObject;
import kz.gov.pki.kalkan.asn1.DERSet;
import kz.gov.pki.kalkan.asn1.cms.Attribute;
import kz.gov.pki.kalkan.asn1.cms.AttributeTable;
import kz.gov.pki.kalkan.asn1.pkcs.PKCSObjectIdentifiers;
import kz.gov.pki.kalkan.asn1.x509.AccessDescription;
import kz.gov.pki.kalkan.asn1.x509.AuthorityInformationAccess;
import kz.gov.pki.kalkan.asn1.x509.GeneralName;
import kz.gov.pki.kalkan.asn1.x509.X509Extensions;
import kz.gov.pki.kalkan.jce.provider.cms.CMSSignedData;
import kz.gov.pki.kalkan.jce.provider.cms.SignerInformation;
import kz.gov.pki.kalkan.jce.provider.cms.SignerInformationStore;
import kz.gov.pki.kalkan.ocsp.BasicOCSPResp;
import kz.gov.pki.kalkan.ocsp.CertificateID;
import kz.gov.pki.kalkan.ocsp.OCSPReq;
import kz.gov.pki.kalkan.ocsp.OCSPReqGenerator;
import kz.gov.pki.kalkan.ocsp.OCSPResp;
import kz.gov.pki.kalkan.ocsp.OCSPRespStatus;
import kz.gov.pki.kalkan.ocsp.SingleResp;
import kz.gov.pki.kalkan.tsp.TimeStampRequest;
import kz.gov.pki.kalkan.tsp.TimeStampRequestGenerator;
import kz.gov.pki.kalkan.tsp.TimeStampResponse;
import kz.gov.pki.kalkan.tsp.TimeStampToken;
import kz.gov.pki.provider.utils.TSPUtil;
import kz.gov.pki.provider.utils.X509Util;
import kz.gov.pki.provider.utils.CMSUtil;
import kz.gov.pki.provider.utils.KeyStoreUtil;
import kz.gov.pki.provider.utils.model.SigningEntity;
import kz.gov.pki.provider.utils.model.TSAProfile;
import kz.gov.pki.kalkan.Storage;
import kz.gov.pki.reference.TSAPolicy;
import kz.gov.pki.reference.KNCAServiceRequestMethod;
import kz.gov.pki.reference.KalkanHashAlgorithm;
import kz.sental.ncasign.pades.model.PadesFinalizeRequest;
import kz.sental.ncasign.pades.model.PadesFinalizeResponse;
import kz.sental.ncasign.pades.model.PadesPrepareRequest;
import kz.sental.ncasign.pades.model.PadesPrepareResponse;
import kz.sental.ncasign.pades.model.SignerPayload;
import kz.sental.ncasign.pades.model.PadesVerifyRequest;
import kz.sental.ncasign.pades.model.PadesVerifyResponse;
import kz.sental.ncasign.pades.model.PadesServerSignRequest;
import kz.sental.ncasign.pades.model.PadesServerSignResponse;
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
import java.math.BigInteger;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;
import java.security.cert.X509CRL;
import java.security.KeyStore;
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
import java.util.Hashtable;
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
        boolean includeOnlineRevocationEvidence = Boolean.TRUE.equals(request.includeOnlineRevocationEvidence);
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
                Map<String, Object> item = verifyEmbeddedSignature(
                    pdfBytes,
                    signature,
                    index,
                    provider,
                    includeOnlineRevocationEvidence
                );
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
        response.evidence.put("includeOnlineRevocationEvidence", includeOnlineRevocationEvidence);
        response.evidence.put("ocspCount", response.signatures.stream()
            .mapToInt(item -> ((Number)item.getOrDefault("ocspCount", 0)).intValue())
            .sum());
        if (!response.allValid) {
            response.message = "One or more embedded PDF signatures failed Kalkan verification.";
        }
        return response;
    }

    @Override
    public PadesServerSignResponse serverSignPreparedPayload(PadesServerSignRequest request) {
        if (request == null || request.sessionId == null || request.sessionId.isBlank()) {
            return PadesServerSignResponse.error("Server signing sessionId is required.");
        }

        PreparedSigningSession session = sessions.get(request.sessionId);
        if (session == null) {
            return PadesServerSignResponse.error("Prepared signing session was not found or has expired.");
        }

        Provider provider = ensureKalkanProvider();
        try {
            KeyStore keyStore = loadPkcs12KeyStore(request.pkcs12Path, request.pkcs12Password, provider);
            char[] password = request.pkcs12Password == null ? new char[0] : request.pkcs12Password.toCharArray();
            String alias = resolvePkcs12Alias(keyStore, request.pkcs12Alias);
            SigningEntity signingEntity;
            try {
                signingEntity = KeyStoreUtil.getSigningEntityDefaultChained(keyStore, alias, password);
            } catch (Throwable chainedError) {
                LOGGER.warn(
                    "Falling back to non-chained PKCS#12 signer for alias {} because issuer chain resolution failed: {}",
                    alias,
                    rootMessage(chainedError)
                );
                signingEntity = KeyStoreUtil.getSigningEntity(keyStore, alias, password);
            }
            X509Certificate signerCertificate = signingEntity.getCertificateChain().isEmpty()
                ? null
                : signingEntity.getCertificateChain().get(0);
            CMSSignedData cms = CMSUtil.createCAdES(signingEntity, session.contentToSign, false, provider);
            TSAProfile tsaProfile = buildTsaProfile(signerCertificate);
            cms = applyCadesTWithoutForcedPolicy(cms, tsaProfile, provider);
            byte[] cmsBytes = cms.getEncoded();
            TimestampProbe timestampProbe = probeTimestampTokens(cms, provider);

            PadesServerSignResponse response = new PadesServerSignResponse();
            response.status = "ok";
            response.message = "Prepared payload signed with server-side PKCS#12 key";
            response.sessionId = request.sessionId;
            response.fieldName = session.fieldName;
            response.cmsBase64 = Base64.getEncoder().encodeToString(cmsBytes);
            response.cmsSha256 = sha256Hex(cmsBytes);
            response.signingTime = DateTimeFormatter.ISO_INSTANT.format(Instant.now());
            response.certificateInfo = buildCertificateInfo(signerCertificate);
            response.evidence.put("sessionId", request.sessionId);
            response.evidence.put("fieldName", session.fieldName);
            response.evidence.put("pkcs12Path", request.pkcs12Path);
            response.evidence.put("pkcs12Alias", alias);
            response.evidence.put("cmsLength", cmsBytes.length);
            response.evidence.put("payloadSha256", sha256Hex(session.contentToSign));
            response.evidence.put("tsaApplied", timestampProbe.tokenCount > 0);
            response.evidence.put("tsaTokenCount", timestampProbe.tokenCount);
            if (!timestampProbe.genTimes.isEmpty()) {
                response.evidence.put("tsaGenTimes", timestampProbe.genTimes);
            }
            if (!timestampProbe.authorities.isEmpty()) {
                response.evidence.put("tsaAuthorities", timestampProbe.authorities);
            }
            if (!timestampProbe.errors.isEmpty()) {
                response.evidence.put("tsaErrors", timestampProbe.errors);
            }
            return response;
        } catch (Throwable e) {
            throw new IllegalStateException("Unable to sign prepared payload with server PKCS#12: " + rootMessage(e), e);
        }
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

    private KeyStore loadPkcs12KeyStore(String pkcs12Path, String pkcs12Password, Provider provider) throws Exception {
        if (pkcs12Path == null || pkcs12Path.isBlank()) {
            throw new IllegalArgumentException("PKCS#12 path is empty.");
        }
        Path path = Path.of(pkcs12Path);
        if (!Files.isRegularFile(path) || !Files.isReadable(path)) {
            throw new IllegalArgumentException("PKCS#12 file is not readable: " + pkcs12Path);
        }
        char[] password = pkcs12Password == null ? new char[0] : pkcs12Password.toCharArray();
        return KeyStoreUtil.getKeyStore(Storage.PKCS12, pkcs12Path, password, provider);
    }

    private String resolvePkcs12Alias(KeyStore keyStore, String preferredAlias) throws Exception {
        if (preferredAlias != null && !preferredAlias.isBlank() && keyStore.containsAlias(preferredAlias)) {
            return preferredAlias;
        }
        java.util.Enumeration<String> aliases = keyStore.aliases();
        while (aliases.hasMoreElements()) {
            String alias = aliases.nextElement();
            if (keyStore.isKeyEntry(alias)) {
                return alias;
            }
        }
        throw new IllegalStateException("No private key entry was found in the PKCS#12 container.");
    }

    private Map<String, Object> buildCertificateInfo(X509Certificate certificate) {
        Map<String, Object> info = new LinkedHashMap<>();
        if (certificate == null) {
            return info;
        }
        info.put("subjectDn", certificate.getSubjectX500Principal().getName());
        info.put("issuerDn", certificate.getIssuerX500Principal().getName());
        info.put("serialNumber", certificate.getSerialNumber().toString());
        String iin = extractIinFromCertificate(certificate);
        if (!iin.isBlank()) {
            info.put("iin", iin);
        }
        info.put("notBefore", DateTimeFormatter.ISO_INSTANT.format(certificate.getNotBefore().toInstant()));
        info.put("notAfter", DateTimeFormatter.ISO_INSTANT.format(certificate.getNotAfter().toInstant()));
        info.put("sha256", certificateSha256(certificate));
        return info;
    }

    private TSAProfile buildTsaProfile(X509Certificate certificate) {
        TSAProfile profile = new TSAProfile();
        profile.setTsaURL(resolveTsaUrl(certificate));
        profile.setRequestMethod(KNCAServiceRequestMethod.POST);
        profile.setHashAlgorithm(resolveTsaHashAlgorithm(certificate));
        return profile;
    }

    private KalkanHashAlgorithm resolveTsaHashAlgorithm(X509Certificate certificate) {
        String algorithm = certificateAlgorithmMarker(certificate);
        if (algorithm.contains("RSA")) {
            return KalkanHashAlgorithm.HASH_SHA256;
        }
        if (algorithm.contains("2015") || algorithm.contains("3411-2015") || algorithm.contains("GOST34311GT")) {
            return KalkanHashAlgorithm.HASH_GOST34311GT;
        }
        return KalkanHashAlgorithm.HASH_GOST34311;
    }

    private TSAPolicy resolveTsaPolicy(X509Certificate certificate) {
        String algorithm = certificateAlgorithmMarker(certificate);
        if (algorithm.contains("RSA")) {
            return TSAPolicy.TSA_RSA;
        }
        if (algorithm.contains("2015") || algorithm.contains("3411-2015") || algorithm.contains("GOST34311GT")) {
            return TSAPolicy.TSA_GOST_GT;
        }
        return TSAPolicy.TSA_GOST;
    }

    private String certificateAlgorithmMarker(X509Certificate certificate) {
        if (certificate == null) {
            return "";
        }
        StringBuilder marker = new StringBuilder();
        marker.append(certificate.getSigAlgName() == null ? "" : certificate.getSigAlgName()).append('|');
        marker.append(certificate.getPublicKey() == null ? "" : certificate.getPublicKey().getAlgorithm()).append('|');
        marker.append(certificate.getSigAlgOID() == null ? "" : certificate.getSigAlgOID());
        return marker.toString().toUpperCase();
    }

    private String resolveTsaUrl(X509Certificate certificate) {
        String marker = certificateTextMarker(certificate);
        if (marker.contains("TEST")) {
            return "http://test.pki.gov.kz/tsp/";
        }
        return "http://tsp.pki.gov.kz";
    }

    private CMSSignedData applyCadesTWithoutForcedPolicy(
        CMSSignedData cms,
        TSAProfile tsaProfile,
        Provider provider
    ) throws Exception {
        List<SignerInformation> signerInfos = new ArrayList<>(CMSUtil.getSignerInformations(cms));
        List<SignerInformation> timestampedSigners = new ArrayList<>(signerInfos.size());
        for (SignerInformation signerInformation : signerInfos) {
            timestampedSigners.add(addTimestampTokenWithoutForcedPolicy(signerInformation, tsaProfile, provider));
        }
        return CMSSignedData.replaceSigners(cms, new SignerInformationStore(timestampedSigners));
    }

    private SignerInformation addTimestampTokenWithoutForcedPolicy(
        SignerInformation signerInformation,
        TSAProfile tsaProfile,
        Provider provider
    ) throws Exception {
        byte[] signatureBytes = signerInformation.getSignature();
        TimeStampResponse response = requestTimestampWithoutPolicy(signatureBytes, tsaProfile, provider);
        TimeStampToken token = response.getTimeStampToken();
        if (token == null) {
            throw new IllegalStateException(
                "TSA response did not contain a timestamp token. status=" +
                    response.getStatus() +
                    " failInfo=" + response.getFailInfo() +
                    " statusString=" + response.getStatusString()
            );
        }
        TSPUtil.validateTimeStampToken(token, signatureBytes, provider);

        Hashtable<Object, Object> attributes = new Hashtable<>();
        AttributeTable existingUnsignedAttributes = signerInformation.getUnsignedAttributes();
        if (existingUnsignedAttributes != null) {
            Hashtable<?, ?> existingTable = existingUnsignedAttributes.toHashtable();
            if (existingTable != null) {
                attributes.putAll(existingTable);
            }
        }

        try (ASN1InputStream tokenStream = new ASN1InputStream(token.getEncoded())) {
            Attribute timeStampAttribute = new Attribute(
                PKCSObjectIdentifiers.id_aa_signatureTimeStampToken,
                new DERSet(tokenStream.readObject())
            );
            attributes.put(timeStampAttribute.getAttrType(), timeStampAttribute);
        }

        return SignerInformation.replaceUnsignedAttributes(
            signerInformation,
            new AttributeTable(attributes)
        );
    }

    private TimeStampResponse requestTimestampWithoutPolicy(
        byte[] data,
        TSAProfile tsaProfile,
        Provider provider
    ) throws Exception {
        String hashOid = tsaProfile.getHashAlgorithm().getId();
        MessageDigest digest = MessageDigest.getInstance(hashOid, provider.getName());
        byte[] hashedData = digest.digest(data);

        TimeStampRequestGenerator requestGenerator = new TimeStampRequestGenerator();
        requestGenerator.setCertReq(true);
        BigInteger nonce = BigInteger.valueOf(System.currentTimeMillis());
        TimeStampRequest request = requestGenerator.generate(hashOid, hashedData, nonce);
        byte[] requestBytes = request.getEncoded();

        HttpURLConnection connection;
        String url = tsaProfile.getTsaURL();
        if (KNCAServiceRequestMethod.POST.equals(tsaProfile.getRequestMethod())) {
            connection = (HttpURLConnection) new URL(url).openConnection();
            connection.setRequestMethod("POST");
            connection.setDoOutput(true);
            connection.setRequestProperty("Content-Type", "application/timestamp-query");
            try (OutputStream output = connection.getOutputStream()) {
                output.write(requestBytes);
            }
        } else {
            String encodedRequest = URLEncoder.encode(
                kz.gov.pki.kalkan.util.encoders.Base64.encodeStr(requestBytes),
                "UTF-8"
            );
            String separator = url.endsWith("/") ? "" : "/";
            connection = (HttpURLConnection) new URL(url + separator + encodedRequest).openConnection();
        }

        int status = connection.getResponseCode();
        if (status != 200) {
            throw new IOException("TSA responder returned HTTP " + status + ".");
        }
        String contentType = connection.getContentType();
        if (!"application/timestamp-reply".equals(contentType)) {
            throw new IOException("Unexpected TSA content-type: " + contentType);
        }

        try (InputStream input = connection.getInputStream()) {
            TimeStampResponse response = new TimeStampResponse(input);
            response.validate(request);
            return response;
        } finally {
            connection.disconnect();
        }
    }

    private TimestampProbe probeTimestampTokens(CMSSignedData cms, Provider provider) {
        TimestampProbe probe = new TimestampProbe();
        try {
            List<SignerInformation> signerInfos = CMSUtil.getSignerInformations(cms);
            if (signerInfos == null) {
                return probe;
            }
            for (SignerInformation signerInformation : signerInfos) {
                try {
                    TimeStampToken token = CMSUtil.getTimestampToken(signerInformation, provider);
                    if (token == null) {
                        continue;
                    }
                    probe.tokenCount++;
                    try {
                        Date genTime = token.getTimeStampInfo().getGenTime();
                        if (genTime != null) {
                            probe.genTimes.add(genTime.toInstant().toString());
                        }
                    } catch (Throwable ex) {
                        probe.errors.add("genTime: " + rootMessage(ex));
                    }
                    try {
                        X509Certificate tsaCertificate = TSPUtil.getTSPCertificate(token, provider);
                        if (tsaCertificate != null) {
                            probe.authorities.add(tsaCertificate.getSubjectX500Principal().getName());
                        }
                    } catch (Throwable ex) {
                        probe.errors.add("authority: " + rootMessage(ex));
                    }
                } catch (Throwable ex) {
                    probe.errors.add(rootMessage(ex));
                }
            }
        } catch (Throwable ex) {
            probe.errors.add("signer infos: " + rootMessage(ex));
        }
        return probe;
    }

    private String certificateTextMarker(X509Certificate certificate) {
        if (certificate == null) {
            return "";
        }
        StringBuilder marker = new StringBuilder();
        if (certificate.getSubjectX500Principal() != null) {
            marker.append(certificate.getSubjectX500Principal().getName()).append('|');
        }
        if (certificate.getIssuerX500Principal() != null) {
            marker.append(certificate.getIssuerX500Principal().getName()).append('|');
        }
        marker.append(certificateAlgorithmMarker(certificate));
        return marker.toString().toUpperCase();
    }

    private String certificateSha256(X509Certificate certificate) {
        try {
            return sha256Hex(certificate.getEncoded());
        } catch (Exception e) {
            return null;
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

    private Map<String, Object> verifyEmbeddedSignature(
        byte[] pdfBytes,
        PDSignature signature,
        int index,
        Provider provider,
        boolean includeOnlineRevocationEvidence
    ) {
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

            try {
                Map<String, Object> signerEvidence = extractSignerVerificationEvidence(
                    embeddedCms,
                    provider,
                    includeOnlineRevocationEvidence
                );
                item.putAll(signerEvidence);
            } catch (Throwable e) {
                item.put("timestampPresent", false);
                item.put("timestampTokenCount", 0);
                item.put("ocspCount", 0);
                item.put("signerEvidenceError", rootMessage(e));
                LOGGER.warn(
                    "Signer evidence extraction failed for signature #{} field {}: {}",
                    index,
                    "Signature" + index,
                    rootMessage(e)
                );
            }
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

    private Map<String, Object> extractSignerVerificationEvidence(
        byte[] cmsBytes,
        Provider provider,
        boolean includeOnlineRevocationEvidence
    ) throws Exception {
        Map<String, Object> item = new LinkedHashMap<>();
        item.put("timestampPresent", false);
        item.put("timestampTokenCount", 0);
        item.put("ocspCount", 0);
        item.put("ocspResponseCount", 0);
        item.put("certificateCount", 0);
        item.put("crlCount", 0);

        CMSSignedData cms = CMSUtil.parseAsCMS(cmsBytes);

        X509Certificate signerCertificate = null;
        try {
            List<X509Certificate> signerCertificates = CMSUtil.getSignerCertificates(cms, provider);
            if (signerCertificates != null && !signerCertificates.isEmpty()) {
                signerCertificate = signerCertificates.get(0);
                item.put("certificateCount", signerCertificates.size());
                item.put("certificateSubjectDn", signerCertificate.getSubjectX500Principal().getName());
                item.put("certificateIssuerDn", signerCertificate.getIssuerX500Principal().getName());
                item.put("certificateSerialNumber", signerCertificate.getSerialNumber().toString());
                String iin = extractIinFromCertificate(signerCertificate);
                if (!iin.isBlank()) {
                    item.put("certificateIin", iin);
                }
                item.put("certificateNotBefore", signerCertificate.getNotBefore().toInstant().toString());
                item.put("certificateNotAfter", signerCertificate.getNotAfter().toInstant().toString());
            }
        } catch (Throwable ex) {
            item.put("certificateInfoError", rootMessage(ex));
        }

        List<String> timestampErrors = new ArrayList<>();
        try {
            List<SignerInformation> signerInfos = CMSUtil.getSignerInformations(cms);
            if (signerInfos != null) {
                List<String> tokenHashes = new ArrayList<>();
                List<String> genTimes = new ArrayList<>();
                List<String> authorities = new ArrayList<>();
                for (SignerInformation signerInformation : signerInfos) {
                    try {
                        TimeStampToken token = CMSUtil.getTimestampToken(signerInformation, provider);
                        if (token == null) {
                            continue;
                        }
                        tokenHashes.add(sha256Hex(token.getEncoded()));
                        try {
                            Date genTime = token.getTimeStampInfo().getGenTime();
                            if (genTime != null) {
                                genTimes.add(genTime.toInstant().toString());
                            }
                        } catch (Throwable ex) {
                            timestampErrors.add("genTime: " + rootMessage(ex));
                        }
                        try {
                            CMSSignedData tsCms = token.toCMSSignedData();
                            List<X509Certificate> tspSignerCerts = CMSUtil.getSignerCertificates(tsCms, provider);
                            if (tspSignerCerts != null) {
                                for (X509Certificate tspSignerCert : tspSignerCerts) {
                                    authorities.add(tspSignerCert.getSubjectX500Principal().getName());
                                }
                            }
                        } catch (Throwable ex) {
                            timestampErrors.add("timestamp authority: " + rootMessage(ex));
                        }
                    } catch (Throwable ex) {
                        timestampErrors.add(rootMessage(ex));
                    }
                }

                item.put("timestampPresent", !tokenHashes.isEmpty());
                item.put("timestampTokenCount", tokenHashes.size());
                if (!tokenHashes.isEmpty()) {
                    item.put("timestampTokenSha256", tokenHashes);
                }
                if (!genTimes.isEmpty()) {
                    item.put("timestampGenTimes", genTimes);
                    item.put("timestampGenTime", genTimes.get(0));
                }
                if (!authorities.isEmpty()) {
                    item.put("timestampAuthorities", authorities);
                    item.put("timestampAuthority", authorities.get(0));
                }
            }
        } catch (Throwable ex) {
            timestampErrors.add("signer infos: " + rootMessage(ex));
        }
        if (!timestampErrors.isEmpty()) {
            item.put("timestampErrors", timestampErrors);
        }

        if (!includeOnlineRevocationEvidence || signerCertificate == null) {
            return item;
        }

        List<String> ocspErrors = new ArrayList<>();
        try {
            X509Certificate issuer = resolveIssuerCertificateForOcsp(signerCertificate, provider, ocspErrors);
            if (issuer == null) {
                ocspErrors.add(signerCertificate.getSubjectX500Principal().getName() + ": issuer certificate could not be resolved for OCSP.");
                item.put("ocspCount", 0);
                item.put("ocspResponseCount", 0);
            } else {
                SignerLtvEvidence evidence = new SignerLtvEvidence();
                byte[] ocspResponse = fetchOcspResponse(signerCertificate, issuer, provider, evidence);
                if (ocspResponse != null && ocspResponse.length > 0) {
                    item.put("ocspCount", 1);
                    item.put("ocspResponseCount", 1);
                    item.put("ocspUrls", new ArrayList<>(evidence.ocspUrls));
                    item.put("ocspDetails", new ArrayList<>(evidence.ocspDetails));
                    item.put("ocspResponseSha256", List.of(sha256Hex(ocspResponse)));
                }
                if (!evidence.ocspErrors.isEmpty()) {
                    ocspErrors.addAll(evidence.ocspErrors);
                }
            }
        } catch (Throwable ex) {
            ocspErrors.add(rootMessage(ex));
        }
        if (!ocspErrors.isEmpty()) {
            item.put("ocspErrors", ocspErrors);
        }

        return item;
    }

    private X509Certificate resolveIssuerCertificateForOcsp(
        X509Certificate certificate,
        Provider provider,
        List<String> errors
    ) {
        try {
            String issuerUrl = extractCaIssuersUrl(certificate);
            if (issuerUrl != null && !issuerUrl.isBlank()) {
                X509Certificate issuer = fetchCertificateFromUrl(issuerUrl);
                if (issuer != null) {
                    return issuer;
                }
                errors.add("AIA caIssuers fetch failed: " + issuerUrl);
            } else {
                errors.add("AIA caIssuers URL not present.");
            }
        } catch (Throwable ex) {
            errors.add("AIA caIssuers resolution failed: " + rootMessage(ex));
        }

        for (String fallbackUrl : guessIssuerFallbackUrls(certificate)) {
            try {
                X509Certificate issuer = fetchCertificateFromUrl(fallbackUrl);
                if (issuer != null) {
                    errors.add("Issuer certificate resolved via fallback URL: " + fallbackUrl);
                    return issuer;
                }
            } catch (Throwable ex) {
                errors.add("Issuer fallback failed for " + fallbackUrl + ": " + rootMessage(ex));
            }
        }
        return null;
    }

    private List<String> guessIssuerFallbackUrls(X509Certificate certificate) {
        List<String> urls = new ArrayList<>();
        String issuerDn = certificate.getIssuerX500Principal().getName();
        if (issuerDn.contains("GOST") && issuerDn.contains("TEST 2022")) {
            urls.add("http://test.pki.gov.kz/cert/nca_gost2022_test.cer");
        }
        if (issuerDn.contains("GOST") && issuerDn.contains("2022") && !issuerDn.contains("TEST")) {
            urls.add("http://pki.gov.kz/cert/nca_gost2022.cer");
        }
        return urls;
    }

    private X509Certificate fetchCertificateFromUrl(String certificateUrl) throws Exception {
        HttpURLConnection connection = (HttpURLConnection) new URL(certificateUrl).openConnection();
        connection.setConnectTimeout(15000);
        connection.setReadTimeout(15000);
        connection.setRequestMethod("GET");
        connection.setRequestProperty("Accept", "application/pkix-cert, application/x-x509-ca-cert, application/octet-stream");
        int status = connection.getResponseCode();
        InputStream responseStream = status >= 200 && status < 300
            ? connection.getInputStream()
            : connection.getErrorStream();
        if (responseStream == null) {
            throw new IOException("Certificate URL returned HTTP " + status + " without a response body.");
        }
        try (InputStream is = responseStream) {
            if (status < 200 || status >= 300) {
                throw new IOException("Certificate URL returned HTTP " + status + ".");
            }
            CertificateFactory factory = CertificateFactory.getInstance("X.509");
            Object generated = factory.generateCertificate(is);
            if (generated instanceof X509Certificate issuerCertificate) {
                return issuerCertificate;
            }
            return null;
        } finally {
            connection.disconnect();
        }
    }

    private SignerLtvEvidence collectSignerLtvEvidence(byte[] cmsBytes, Provider provider) throws Exception {
        return collectSignerEvidence(cmsBytes, provider, true);
    }

    private SignerLtvEvidence collectSignerEvidence(byte[] cmsBytes, Provider provider, boolean includeOnlineRevocationEvidence) throws Exception {
        SignerLtvEvidence evidence = new SignerLtvEvidence();
        CMSSignedData cms;
        try {
            cms = CMSUtil.parseAsCMS(cmsBytes);
        } catch (Throwable ex) {
            throw new IllegalStateException("Unable to parse CMS for signer evidence: " + rootMessage(ex), ex);
        }

        List<X509Certificate> allCertificates = null;
        try {
            allCertificates = CMSUtil.getX509Certificates(cms, provider);
            if (allCertificates != null) {
                for (X509Certificate certificate : allCertificates) {
                    evidence.addCertificate(certificate);
                }
            }
        } catch (Throwable ex) {
            evidence.ocspErrors.add("certificate bundle: " + rootMessage(ex));
        }

        List<X509Certificate> signerCertificates = null;
        try {
            signerCertificates = CMSUtil.getSignerCertificates(cms, provider);
            if (signerCertificates != null && !signerCertificates.isEmpty()) {
                X509Certificate signerCertificate = signerCertificates.get(0);
                evidence.signerSubjectDn = signerCertificate.getSubjectX500Principal().getName();
                evidence.signerIssuerDn = signerCertificate.getIssuerX500Principal().getName();
                evidence.signerSerialNumber = signerCertificate.getSerialNumber().toString();
                evidence.signerIin = extractIinFromCertificate(signerCertificate);
                evidence.signerNotBefore = signerCertificate.getNotBefore().toInstant().toString();
                evidence.signerNotAfter = signerCertificate.getNotAfter().toInstant().toString();
            }
        } catch (Throwable ex) {
            evidence.ocspErrors.add("signer certificate: " + rootMessage(ex));
        }

        List<SignerInformation> signerInfos = null;
        try {
            signerInfos = CMSUtil.getSignerInformations(cms);
        } catch (Throwable ex) {
            evidence.timestampErrors.add("signer infos: " + rootMessage(ex));
        }
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
                        } catch (Throwable ignored) {
                            // Keep timestamp token presence even if genTime is unavailable.
                        }
                        try {
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
                        } catch (Throwable ex) {
                            evidence.timestampErrors.add("timestamp authority: " + rootMessage(ex));
                        }
                    }
                } catch (Throwable ex) {
                    evidence.timestampErrors.add(rootMessage(ex));
                }
            }
        }

        if (!includeOnlineRevocationEvidence) {
            return evidence;
        }

        // OCSP evidence is required for the signer certificate at signing time.
        // Do not recursively chase every cert bundled in CMS/TSA material here.
        List<X509Certificate> ocspTargets = signerCertificates != null && !signerCertificates.isEmpty()
            ? new ArrayList<>(signerCertificates)
            : new ArrayList<>();

        for (X509Certificate certificate : ocspTargets) {
            X509Certificate issuer;
            try {
                issuer = findIssuerCertificate(certificate, evidence.certificates, provider);
                if (issuer == null) {
                    issuer = resolveIssuerCertificateForOcsp(certificate, provider, evidence.ocspErrors);
                    if (issuer != null) {
                        evidence.addCertificate(issuer);
                    }
                }
            } catch (Throwable ex) {
                evidence.ocspErrors.add(certificate.getSubjectX500Principal().getName() + ": issuer lookup failed: " + rootMessage(ex));
                continue;
            }
            if (issuer == null) {
                evidence.ocspErrors.add(certificate.getSubjectX500Principal().getName() + ": issuer certificate could not be resolved for OCSP.");
                continue;
            }
            try {
                byte[] ocspResponse = fetchOcspResponse(certificate, issuer, provider, evidence);
                if (ocspResponse != null && ocspResponse.length > 0) {
                    evidence.ocspResponses.add(ocspResponse);
                }
            } catch (Throwable ex) {
                evidence.ocspErrors.add(certificate.getSubjectX500Principal().getName() + ": " + rootMessage(ex));
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
            } catch (Throwable ignored) {
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
        } catch (Throwable ex) {
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
            return extractAiaUrl(certificate, AccessDescription.id_ad_ocsp);
        } catch (Exception e) {
            LOGGER.debug("Failed to extract OCSP URL from certificate {}: {}",
                certificate.getSubjectX500Principal().getName(), rootMessage(e));
        }
        return null;
    }

    private String extractCaIssuersUrl(X509Certificate certificate) {
        try {
            return extractAiaUrl(certificate, AccessDescription.id_ad_caIssuers);
        } catch (Exception e) {
            LOGGER.debug("Failed to extract CA Issuers URL from certificate {}: {}",
                certificate.getSubjectX500Principal().getName(), rootMessage(e));
        }
        return null;
    }

    private String extractAiaUrl(X509Certificate certificate, Object accessMethod) throws Exception {
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
                    if (description == null || !accessMethod.equals(description.getAccessMethod())) {
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
        return null;
    }

    private X509Certificate fetchIssuerCertificate(X509Certificate certificate, Provider provider) {
        List<String> errors = new ArrayList<>();
        X509Certificate issuer = resolveIssuerCertificateForOcsp(certificate, provider, errors);
        if (issuer == null && !errors.isEmpty()) {
            LOGGER.debug(
                "Failed to resolve issuer certificate for {}: {}",
                certificate.getSubjectX500Principal().getName(),
                String.join(" | ", errors)
            );
        }
        return issuer;
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

    private String extractIinFromCertificate(X509Certificate certificate) {
        if (certificate == null || certificate.getSubjectX500Principal() == null) {
            return "";
        }
        String subjectDn = certificate.getSubjectX500Principal().getName();
        String serialNumberValue = extractDnAttribute(subjectDn, "2.5.4.5");
        if (serialNumberValue.isBlank()) {
            serialNumberValue = extractDnAttribute(subjectDn, "SERIALNUMBER");
        }
        return serialNumberValue.replaceAll("\\D+", "");
    }

    private String extractDnAttribute(String dn, String attributeName) {
        if (dn == null || dn.isBlank() || attributeName == null || attributeName.isBlank()) {
            return "";
        }
        String prefix = attributeName + "=";
        for (String part : dn.split(",")) {
            String trimmed = part.trim();
            if (!trimmed.regionMatches(true, 0, prefix, 0, prefix.length())) {
                continue;
            }
            String value = trimmed.substring(prefix.length()).trim();
            if (value.startsWith("#")) {
                return decodeDerStringHex(value.substring(1));
            }
            return value;
        }
        return "";
    }

    private String decodeDerStringHex(String hex) {
        byte[] der = hexToBytes(hex);
        if (der.length < 2) {
            return "";
        }
        int length = der[1] & 0xff;
        int offset = 2;
        if ((length & 0x80) != 0) {
            int lengthBytes = length & 0x7f;
            if (der.length < 2 + lengthBytes) {
                return "";
            }
            length = 0;
            for (int i = 0; i < lengthBytes; i++) {
                length = (length << 8) | (der[2 + i] & 0xff);
            }
            offset = 2 + lengthBytes;
        }
        if (length <= 0 || der.length < offset + length) {
            return "";
        }
        return new String(der, offset, length, java.nio.charset.StandardCharsets.UTF_8);
    }

    private byte[] hexToBytes(String hex) {
        if (hex == null) {
            return new byte[0];
        }
        String normalized = hex.replaceAll("[^0-9A-Fa-f]", "");
        if ((normalized.length() % 2) != 0) {
            return new byte[0];
        }
        byte[] bytes = new byte[normalized.length() / 2];
        for (int i = 0; i < normalized.length(); i += 2) {
            bytes[i / 2] = (byte)Integer.parseInt(normalized.substring(i, i + 2), 16);
        }
        return bytes;
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

    private static final class TimestampProbe {
        private int tokenCount = 0;
        private final List<String> genTimes = new ArrayList<>();
        private final List<String> authorities = new ArrayList<>();
        private final List<String> errors = new ArrayList<>();
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
        private String signerIin;
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
            if (signerIin != null && !signerIin.isBlank()) {
                item.put("certificateIin", signerIin);
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
