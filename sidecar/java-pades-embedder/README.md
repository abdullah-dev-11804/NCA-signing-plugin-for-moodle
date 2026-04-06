# Java PAdES Sidecar

This service is the active detached-CMS -> embedded-PDF backend for `local_ncasign`.

Current endpoints:
- `GET /health`
- `POST /api/v1/pades/prepare`
- `POST /api/v1/pades/finalize`
- `POST /api/v1/pades/verify`

Current stack:
- Spring Boot
- Apache PDFBox external signing
- Kalkan SDK for GOST CMS verification

What the sidecar does now:
1. prepares a PDF external-signing session and returns the exact PDF ByteRange content to sign
2. accepts the CMS produced by NCALayer and embeds it into the PDF without trying to reinterpret the signer algorithm
3. verifies the CMS against the prepared content with Kalkan before embedding
4. verifies the embedded PDF signature again with Kalkan after embedding
5. keeps the embedded PDF-signing flow focused on stable PDFBox external signing and Kalkan verification
6. exposes a verifier endpoint for already signed PDFs

Important scope boundary:
- timestamp tokens are preserved inside the signer CMS if NCALayer produced them
- LT/LTV packaging is not active in this rollback state
- long-term validator acceptance still depends on the target validator ecosystem

Build prerequisites:
1. Java 17 or newer
2. Maven 3.9+
3. Kalkan SDK jars placed in `lib/`

Required local jars:
- `lib/knca_provider_jce_kalkan-0.7.5.jar`
- `lib/knca_provider_util-0.8.5.jar`

Build:
```bash
mvn clean package
```

Run:
```bash
java -jar target/ncasign-pades-sidecar-0.1.0-SNAPSHOT.jar --server.port=8080
```

Health check:
```bash
curl http://127.0.0.1:8080/health
```

What is implemented:
- prepare -> NCALayer sign -> finalize flow
- multi-signer incremental embedding
- Kalkan-based signed-PDF verification

What is still not a blanket claim:
- automatic acceptance by Adobe Acrobat or `pdfsig` for KNCA GOST algorithms
- SIGEX/NCA verifier integration
- a final legal claim of full production acceptance without running the target validators
- LT/LTV packaging is a separate follow-up phase
