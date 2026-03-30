# Java PAdES Sidecar

## Role

This sidecar is the planned **detached CMS -> embedded PDF** backend.

Current architecture split:

- `NCALayer` creates detached CMS on the signer workstation
- `NCANode` verifies detached CMS and certificate status
- `Java sidecar` is responsible for PDF embedding and PAdES finalization

## Why the sidecar exists

The current artifacts in this workspace do **not** provide a complete PDF embedding implementation:

1. `NCANode` exposes CMS verification and PDF signing with server-held keys, but not an endpoint that takes:
   - draft PDF
   - reserved signature field
   - externally produced detached CMS
   - OCSP/TSP evidence
   and returns an incrementally updated PDF.

2. The Kalkan SDK examples in this workspace show CMS signing and verification, including signing PDF bytes into CMS:
   - `PHP_Linux/example/example 1/index.php:198`
   - `PHP_Linux/example/example 1/index.php:214`
   but they do **not** show embedding detached CMS back into a PDF signature field.

3. The Java jars shipped here contain CMS/X509 helpers, not PDF/PAdES helpers:
   - `Java/provider/knca_provider_jce_kalkan-0.7.5.jar`
   - `Java/utils/knca_provider_util-0.8.5.jar`

## Explicit answer on Kalkan SDK alone

Based on the SDK artifacts available in this workspace: **deny**.

There is no evidence here that Kalkan SDK **alone** provides a ready detached-CMS-to-PDF embedding API.
The available examples and jars cover:

- CMS creation
- CMS verification
- certificate inspection
- OCSP/TSP utilities

They do **not** provide a documented PDF signature field embedding implementation.

## Plugin integration

The Moodle plugin now supports selecting:

- `artifact` finalizer
- `java_sidecar` finalizer

Relevant settings:

- `PAdES finalizer backend`
- `Java sidecar base URL`
- `Java sidecar timeout (seconds)`

CLI health test:

```bash
php local/ncasign/cli/test_pades_sidecar.php
```

## REST contract

Health:

- `GET /health`

Prepare:

- `POST /api/v1/pades/prepare`

Finalize:

- `POST /api/v1/pades/finalize`

Prepare request body:

- `job`
- `draftPdfBase64`
- `draftFileName`
- `draftSha256`
- `manifest`
- `activeSigner`
- `signedSigners[]`

Prepare response body:

- `status`
- `message`
- `sessionId`
- `fieldName`
- `payloadMode`
- `signablePayloadBase64`
- `signablePayloadSha256`
- `signingTime`
- `evidence`

Finalize request body:

- `job`
- `draftPdfBase64`
- `draftFileName`
- `draftSha256`
- `verifyUrl`
- `isFinal`
- `manifest`
- `signers[]`

Each signer includes:

- `rawCmsBase64`
- `expectedIin`
- `verifiedIin`
- `signerCertificateJson`
- `ocspResponseJson`
- `verificationInfo`
- `signMeta`

Response body:

- `status`
- `message`
- `filename`
- `pdfBase64`
- `finalHash`
- `mode`
- `source`
- `evidence`

## What the sidecar must do

1. Prepare a specific signature field/revision from the draft PDF.
2. Return the exact DTBS/message-digest payload that the desktop signer must sign.
3. Receive detached CMS for signer 1 and embed it as an incremental update.
4. Prepare signer 2 on the updated PDF without invalidating signer 1.
5. Prepare signer 3 on the updated PDF without invalidating signer 1 and 2.
6. Package OCSP/CRL/TSP evidence into PDF LTV structures.
7. Return a final PDF verifiable in Adobe Acrobat or another standard PDF validator.

## External dependency still required

The missing implementation dependency is not only the PDF library. It is:

1. a **PAdES-capable PDF embedding engine**
2. a **prepare/finalize implementation** that signs prepared PDF revisions instead of raw PDF bytes

Recommended stack:

- Java 17
- Spring Boot sidecar
- Kalkan SDK jars for crypto/certificate utilities
- DSS `PAdESWithExternalCMSService` with `dss-pades-pdfbox`
- or another PDF library that supports:
  - reserved signature fields
  - incremental signing
  - external signature containers
  - LTV/DSS packaging

Why DSS is preferred over iText 7 here:

- iText's official licensing model is AGPL-or-commercial
- DSS provides an official external-CMS PAdES flow and is distributed under LGPL 2.1

Without that prepare/finalize PDF engine, the detached CMS can be verified, but it cannot be turned into a standards-valid embedded PAdES PDF.
