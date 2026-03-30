package kz.sental.ncasign.pades.service;

import kz.sental.ncasign.pades.model.PadesPrepareRequest;
import kz.sental.ncasign.pades.model.PadesPrepareResponse;
import kz.sental.ncasign.pades.model.PadesFinalizeRequest;
import kz.sental.ncasign.pades.model.PadesFinalizeResponse;

public interface PadesEmbeddingService {
    PadesPrepareResponse prepareExternalSignature(PadesPrepareRequest request);

    PadesFinalizeResponse finalizeDetachedCms(PadesFinalizeRequest request);
}
