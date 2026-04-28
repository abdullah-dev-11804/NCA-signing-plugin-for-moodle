package kz.sental.ncasign.pades.model;

import java.util.HashMap;
import java.util.Map;

public class PadesServerSignResponse {
    public String status;
    public String message;
    public String sessionId;
    public String fieldName;
    public String cmsBase64;
    public String cmsSha256;
    public String signingTime;
    public Map<String, Object> certificateInfo = new HashMap<>();
    public Map<String, Object> evidence = new HashMap<>();

    public static PadesServerSignResponse error(String message) {
        PadesServerSignResponse response = new PadesServerSignResponse();
        response.status = "error";
        response.message = message;
        return response;
    }
}
