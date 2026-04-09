package kz.sental.ncasign.pades.api;

import kz.sental.ncasign.pades.model.PadesVerifyRequest;
import kz.sental.ncasign.pades.model.PadesVerifyResponse;
import kz.sental.ncasign.pades.service.PadesEmbeddingService;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/api/v1/pades")
public class PadesVerifyController {
    private final PadesEmbeddingService embeddingService;

    public PadesVerifyController(PadesEmbeddingService embeddingService) {
        this.embeddingService = embeddingService;
    }

    @PostMapping("/verify")
    public ResponseEntity<PadesVerifyResponse> verify(@RequestBody PadesVerifyRequest request) {
        try {
            return ResponseEntity.ok(embeddingService.verifySignedPdf(request));
        } catch (UnsupportedOperationException e) {
            return ResponseEntity.status(HttpStatus.NOT_IMPLEMENTED)
                .body(PadesVerifyResponse.error("PAdES verify backend is scaffolded but not implemented: " + e.getMessage()));
        } catch (Throwable e) {
            return ResponseEntity.status(HttpStatus.INTERNAL_SERVER_ERROR)
                .body(PadesVerifyResponse.error(buildErrorMessage(e)));
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
        return "Verify failed: " + message;
    }
}
