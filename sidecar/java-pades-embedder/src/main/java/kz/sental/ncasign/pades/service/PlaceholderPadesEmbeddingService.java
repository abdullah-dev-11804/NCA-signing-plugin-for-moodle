package kz.sental.ncasign.pades.service;

import kz.sental.ncasign.pades.model.PadesFinalizeRequest;
import kz.sental.ncasign.pades.model.PadesFinalizeResponse;
import kz.sental.ncasign.pades.model.PadesPrepareRequest;
import kz.sental.ncasign.pades.model.PadesPrepareResponse;

public class PlaceholderPadesEmbeddingService implements PadesEmbeddingService {
    @Override
    public PadesPrepareResponse prepareExternalSignature(PadesPrepareRequest request) {
        throw new UnsupportedOperationException(
            "A true PAdES backend must first prepare a PDF signature revision and return the resulting DTBS/message-digest bytes. " +
            "The current Moodle signing page still signs raw PDF bytes, which is not sufficient for embedded PDF signatures."
        );
    }

    @Override
    public PadesFinalizeResponse finalizeDetachedCms(PadesFinalizeRequest request) {
        throw new UnsupportedOperationException(
            "Missing PDF embedding engine and two-phase prepare/finalize implementation. " +
            "Recommended stack: DSS PAdES external-CMS flow with PDFBox backend plus Kalkan/NCANode evidence integration."
        );
    }
}
