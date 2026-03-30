package kz.sental.ncasign.pades.model;

import java.util.HashMap;
import java.util.Map;

public class PadesFinalizeResponse {
    public String status;
    public String message;
    public String filename;
    public String pdfBase64;
    public String finalHash;
    public String mode;
    public String source;
    public Map<String, Object> evidence = new HashMap<>();

    public static PadesFinalizeResponse error(String message) {
        PadesFinalizeResponse response = new PadesFinalizeResponse();
        response.status = "error";
        response.message = message;
        return response;
    }
}