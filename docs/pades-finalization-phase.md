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

## New finalizer abstraction

The workflow now uses:

- `local_ncasign\local\pades_finalizer_interface`
- `local_ncasign\local\pades_finalizer_factory`

Current active backend:

- `local_ncasign\local\artifact_pdf_finalizer`

This backend does **not** produce embedded PAdES signatures. It produces the current progress/final artifact PDF and is only the first implementation behind the new contract.

## Detached CMS -> final PDF contract

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

1. accept the draft PDF plus reserved signature-slot manifest
2. accept detached CMS for signer 1/2/3
3. embed each detached CMS into a named PDF signature field as an incremental update
4. preserve previous signatures while applying later signatures
5. embed TSA, certificate chain, and OCSP/CRL evidence into PDF LTV structures
6. output a final PDF verifiable as signed in standard PDF validators

Possible implementation targets:

- NCANode extension that supports detached CMS -> PDF field embedding
- dedicated Kalkan-based PDF signing service
- another PDF-signing backend that supports external CMS embedding and PAdES-LT packaging

## Important boundary

This phase prepares the plugin architecture for PAdES finalization.

It does **not** mean PAdES-LT is complete.

Do not represent the plugin as PAdES-LT complete until the final PDF can be opened and validated as signed in standard PDF validators.
