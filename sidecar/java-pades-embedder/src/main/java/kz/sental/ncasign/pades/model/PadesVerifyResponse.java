package kz.sental.ncasign.pades.model;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

public class PadesVerifyResponse {
    public String status;
    public String message;
    public String filename;
    public String pdfSha256;
    public int signatureCount;
    public boolean allValid;
    public List<Map<String, Object>> signatures = new ArrayList<>();
    public Map<String, Object> evidence = new HashMap<>();

    public static PadesVerifyResponse error(String message) {
        PadesVerifyResponse response = new PadesVerifyResponse();
        response.status = "error";
        response.message = message;
        response.allValid = false;
        return response;
    }
}
