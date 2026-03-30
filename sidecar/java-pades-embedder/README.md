# Java PAdES Sidecar

This sidecar is the planned backend for true detached-CMS -> embedded-PDF PAdES finalization.

Endpoints:
- GET `/health`
- POST `/api/v1/pades/prepare`
- POST `/api/v1/pades/finalize`

Current state:
- request/response contracts for prepare/finalize implemented
- Spring Boot service builds successfully
- DSS `PAdESWithExternalCMSService` is wired for:
  - `prepare` -> return the real PDF byte-range digest to sign
  - `finalize` -> embed externally produced CMS into the PDF
- current scope is the minimal one-document / one-signature milestone

Why the implementation is still incomplete:
- the Moodle signer page must use the prepared digest payload instead of raw PDF bytes
- multi-signer preservation and LT/LTV packaging are still follow-up work
- NCANode remains the CMS verification backend
- Kalkan is not required for the minimal one-signature PAdES milestone

Build:
```bash
mvn clean package
```

Run:
```bash
java -jar target/ncasign-pades-sidecar-0.1.0-SNAPSHOT.jar
```

Recommended library direction:
1. DSS `PAdESWithExternalCMSService` with `dss-pades-pdfbox`
2. `dss-utils-apache-commons`
3. NCANode remains the CMS verification backend

Minimal server prerequisites:
1. Java 17 or newer
2. Maven 3.9+
3. Open port `8080` for the sidecar if it will be called remotely
4. No Kalkan jars are required for the first one-signature milestone

Why DSS is preferred over iText here:
- DSS has an official external-CMS PAdES flow
- DSS is LGPL 2.1, which is generally more practical for commercial deployment than iText AGPL unless you purchase an iText commercial license

What still remains after the minimal milestone:
1. prove finalize with a real CMS generated from the prepared digest via NCALayer
2. preserve signer 1 while applying signer 2
3. preserve signer 1 and 2 while applying signer 3
4. add LT/LTV packaging for OCSP/CRL/TSP evidence
