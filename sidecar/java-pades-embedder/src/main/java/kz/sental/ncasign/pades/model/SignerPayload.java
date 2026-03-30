package kz.sental.ncasign.pades.model;

import java.util.Map;

public class SignerPayload {
    public int signerRecordId;
    public int order;
    public String name;
    public String email;
    public String position;
    public String workflowStatus;
    public String expectedIin;
    public String verifiedIin;
    public Long signedAt;
    public String rawCmsBase64;
    public String signerCertificateJson;
    public String ocspResponseJson;
    public String signingMethod;
    public String verificationStatus;
    public Map<String, Object> verificationInfo;
    public Map<String, Object> signMeta;
}