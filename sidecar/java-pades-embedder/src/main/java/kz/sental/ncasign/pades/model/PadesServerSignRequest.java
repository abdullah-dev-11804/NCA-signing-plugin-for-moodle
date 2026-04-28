package kz.sental.ncasign.pades.model;

import java.util.Map;

public class PadesServerSignRequest {
    public String sessionId;
    public String pkcs12Path;
    public String pkcs12Password;
    public String pkcs12Alias;
    public Map<String, Object> activeSigner;
}
