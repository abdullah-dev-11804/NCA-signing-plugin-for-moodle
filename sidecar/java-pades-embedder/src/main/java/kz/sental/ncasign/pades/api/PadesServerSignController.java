package kz.sental.ncasign.pades.api;

import kz.sental.ncasign.pades.model.PadesServerSignRequest;
import kz.sental.ncasign.pades.model.PadesServerSignResponse;
import kz.sental.ncasign.pades.service.PadesEmbeddingService;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/api/v1/pades")
public class PadesServerSignController {
    private final PadesEmbeddingService embeddingService;

    public PadesServerSignController(PadesEmbeddingService embeddingService) {
        this.embeddingService = embeddingService;
    }

    @PostMapping("/sign-server")
    public ResponseEntity<PadesServerSignResponse> signServer(@RequestBody PadesServerSignRequest request) {
        try {
            return ResponseEntity.ok(embeddingService.serverSignPreparedPayload(request));
        } catch (UnsupportedOperationException e) {
            return ResponseEntity.status(HttpStatus.NOT_IMPLEMENTED)
                .body(PadesServerSignResponse.error("PAdES server signing backend is scaffolded but not implemented: " + e.getMessage()));
        } catch (Throwable e) {
            return ResponseEntity.status(HttpStatus.INTERNAL_SERVER_ERROR)
                .body(PadesServerSignResponse.error(buildErrorMessage(e)));
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
        return "Server sign failed: " + message;
    }
}
