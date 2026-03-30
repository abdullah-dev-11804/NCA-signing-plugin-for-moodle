# PAdES Finalization Layer

## Current state

The plugin now separates:

- signing and CMS verification
- PDF finalization

Manual signing still works as:

1. NCALayer creates detached CMS on the signer workstation.
2. Moodle verifies detached CMS with NCANode.
3. The plugin stores raw CMS, signer certificate details, signer IIN, revocation evidence, and hashes.
4. A PDF finalizer backend produces the current signed PDF artifact.

Important correction:

- the current signer page signs the raw current PDF bytes
- a true embedded PDF signature backend cannot finalize that directly
- it must first prepare a PDF signature revision/field and then have the signer sign the prepared DTBS/message-digest payload
- therefore a real PAdES backend for this project requires a two-phase `prepare -> sign -> finalize` flow

## New finalizer abstraction

The workflow now uses:

- `local_ncasign\local\pades_finalizer_interface`
- `local_ncasign\local\pades_finalizer_factory`

Current active backends:

- `local_ncasign\local\artifact_pdf_finalizer`
- `local_ncasign\local\java_sidecar_pades_finalizer`

`artifact_pdf_finalizer` does **not** produce embedded PAdES signatures. It produces the current progress/final artifact PDF and is only the fallback implementation behind the new contract.

`java_sidecar_pades_finalizer` is now wired into the plugin as the intended detached-CMS-to-PDF backend. It calls an external Java service, but the actual embedding implementation still depends on a PDF library/service capable of external CMS embedding and LTV packaging.

## Detached CMS -> final PDF contract

True embedded PAdES needs two contracts, not one.

### Prepare contract

Input context passed to the finalizer:

- job record
- current signable PDF bytes
- current filename
- current SHA-256
- finalization manifest
- active signer record
- already signed signer records/evidence

Output returned by the finalizer:

- sidecar session ID
- signature field/slot name
- payload mode
- signable payload or digest for NCALayer
- signable payload SHA-256
- backend-controlled signing time
- backend evidence payload

### Finalize contract

Input context passed to the finalizer:

- job record
- original draft PDF bytes
- original filename
- original SHA-256
- verification URL
- signer job records including stored CMS/evidence
- completed signer QR blocks
- finalization manifest
- final/progress flag

Output returned by the finalizer:

- output filename
- output PDF bytes
- source label
- backend name
- mode (`artifact_pdf` now, `embedded_pades` later)
- whether backend supports embedded PAdES
- final hash if final output
- backend evidence payload

## Draft reservation manifest

Locally generated drafts now include a stored `finalizationmanifest` on the job.

Current manifest contains:

- reservation mode
- renderer name
- three reserved signature slots with:
  - field/slot name
  - page
  - x/y/w/h
  - placeholder type

Important:

- these are currently **visual placeholders plus metadata only**
- they are **not yet real PDF ByteRange signature fields**

They exist so a future true PAdES backend can consume the manifest and map detached CMS into actual PDF signature fields without changing the workflow layer.

## Exact backend capability still required

To complete real PAdES-LT embedding, the missing backend must:

1. expose a prepare endpoint that returns the exact signable DTBS/message-digest payload for a named slot
2. accept the draft PDF plus reserved signature-slot manifest
3. accept detached CMS for signer 1/2/3
4. embed each detached CMS into a named PDF signature field as an incremental update
5. preserve previous signatures while applying later signatures
6. embed TSA, certificate chain, and OCSP/CRL evidence into PDF LTV structures
7. output a final PDF verifiable as signed in standard PDF validators

Implementation targets:

- NCANode extension that supports detached CMS -> PDF field embedding
- dedicated Java sidecar using DSS `PAdESWithExternalCMSService` + PDFBox backend
- another PDF-signing backend that supports external CMS embedding and PAdES-LT packaging

## Important boundary

This phase prepares the plugin architecture for PAdES finalization.

It does **not** mean PAdES-LT is complete.

Do not represent the plugin as PAdES-LT complete until the final PDF can be opened and validated as signed in standard PDF validators.
