package kz.sental.ncasign.pades.api;

import kz.sental.ncasign.pades.model.PadesFinalizeRequest;
import kz.sental.ncasign.pades.model.PadesFinalizeResponse;
import kz.sental.ncasign.pades.service.PadesEmbeddingService;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/api/v1/pades")
public class PadesFinalizeController {
    private final PadesEmbeddingService embeddingService;

    public PadesFinalizeController(PadesEmbeddingService embeddingService) {
        this.embeddingService = embeddingService;
    }

    @PostMapping("/finalize")
    public ResponseEntity<PadesFinalizeResponse> finalize(@RequestBody PadesFinalizeRequest request) {
        try {
            return ResponseEntity.ok(embeddingService.finalizeDetachedCms(request));
        } catch (UnsupportedOperationException e) {
            return ResponseEntity.status(HttpStatus.NOT_IMPLEMENTED)
                .body(PadesFinalizeResponse.error("PAdES embedding backend is scaffolded but not implemented: " + e.getMessage()));
        } catch (Exception e) {
            return ResponseEntity.status(HttpStatus.INTERNAL_SERVER_ERROR)
                .body(PadesFinalizeResponse.error(buildErrorMessage(e)));
        }
    }

    private String buildErrorMessage(Throwable throwable) {
        Throwable current = throwable;
        while (current.getCause() != null && current.getCause() != current) {
            current = current.getCause();
        }
        String message = current.getMessage();
        if (message == null || message.isBlank()) {
            message = current.getClass().getName();
        }
        return "Finalize failed: " + message;
    }
}
