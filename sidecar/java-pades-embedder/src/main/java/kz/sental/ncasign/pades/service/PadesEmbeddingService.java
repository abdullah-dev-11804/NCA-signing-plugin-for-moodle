package kz.sental.ncasign.pades.service;

import kz.sental.ncasign.pades.model.PadesPrepareRequest;
import kz.sental.ncasign.pades.model.PadesPrepareResponse;
import kz.sental.ncasign.pades.model.PadesFinalizeRequest;
import kz.sental.ncasign.pades.model.PadesFinalizeResponse;
import kz.sental.ncasign.pades.model.PadesVerifyRequest;
import kz.sental.ncasign.pades.model.PadesVerifyResponse;
import kz.sental.ncasign.pades.model.PadesServerSignRequest;
import kz.sental.ncasign.pades.model.PadesServerSignResponse;

public interface PadesEmbeddingService {
    PadesPrepareResponse prepareExternalSignature(PadesPrepareRequest request);

    PadesFinalizeResponse finalizeDetachedCms(PadesFinalizeRequest request);

    PadesVerifyResponse verifySignedPdf(PadesVerifyRequest request);

    PadesServerSignResponse serverSignPreparedPayload(PadesServerSignRequest request);
}
