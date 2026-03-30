package kz.sental.ncasign.pades.model;

import java.util.List;
import java.util.Map;

public class PadesPrepareRequest {
    public JobPayload job;
    public String draftPdfBase64;
    public String draftFileName;
    public String draftSha256;
    public Map<String, Object> manifest;
    public Map<String, Object> activeSigner;
    public List<Map<String, Object>> signedSigners;
}
