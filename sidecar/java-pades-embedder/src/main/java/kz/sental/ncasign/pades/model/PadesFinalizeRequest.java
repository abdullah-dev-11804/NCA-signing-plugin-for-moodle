package kz.sental.ncasign.pades.model;

import java.util.List;
import java.util.Map;

public class PadesFinalizeRequest {
    public JobPayload job;
    public String draftPdfBase64;
    public String draftFileName;
    public String draftSha256;
    public String verifyUrl;
    public boolean isFinal;
    public Map<String, Object> manifest;
    public List<SignerPayload> signers;
}