package kz.sental.ncasign.pades.model;

import java.util.HashMap;
import java.util.Map;

public class PadesPrepareResponse {
    public String status;
    public String message;
    public String sessionId;
    public String fieldName;
    public String payloadMode;
    public String signablePayloadBase64;
    public String signablePayloadSha256;
    public String signingTime;
    public Map<String, Object> evidence = new HashMap<>();

    public static PadesPrepareResponse error(String message) {
        PadesPrepareResponse response = new PadesPrepareResponse();
        response.status = "error";
        response.message = message;
        return response;
    }
}
