# Manual Signing Deployment Notes

## Important

**All final document templates are required before the full rollout can continue.**

**Full PAdES/TSA/OCSP compliance hardening is not part of the current delivered manual-signing build and remains a separate implementation phase.**

Current status:
- Manual signing workflow can be deployed and tested now.
- Template-profile architecture is in place.
- Only the current protocol template/renderer is implemented.
- Certificate, credential, and other final client templates still need to be provided before the full document set can be completed.

## Scope of this deployment

This document covers **manual signing only**:
- document generation
- email notification to signers
- sequential signer workflow
- NCALayer desktop signing
- final signed PDF artifact and verification page

It does **not** cover:
- automatic signing
- eGov Mobile signing
- full PAdES/TSA/OCSP compliance hardening

## Security and compliance status

### Delivered in the current manual-signing build

- Sequential three-signer manual workflow
- Tokenized signer links
- NCALayer desktop signing on signer workstations
- Server-side detached CMS verification through NCANode
- Signer certificate extraction and expected-IIN matching
- Stored signature artifacts per signer
- Stored signer certificate, signer IIN, verification info, and revocation evidence returned by NCANode
- Signed PDF artifact generation
- Public verification page
- Hash-based integrity check of the stored signed artifact
- Template-profile based signer and course mapping

### Not yet delivered in the current build

- True PAdES multi-signature embedding inside the final PDF
- Production-proven timestamp evidence flow through TSA
- Production-proven OCSP/CRL evidence flow and legal packaging
- Final compliance validation against the accepted RK/NCA production trust chain
- Long-term validation profile requirements (PAdES-LT/LTA)
- Formal compliance hardening expected for production legal acceptance

### Important security note for stakeholders

The current build is suitable for:
- workflow validation
- document generation validation
- manual signer process validation
- template/profile mapping validation

The current build should **not** be represented as the final legally hardened production implementation for regulated signature validation until the PAdES/TSA/OCSP work is completed.

## Server prerequisites

1. Moodle server with the plugin deployed to:
   - `<moodle_root>/local/ncasign`
2. PHP available on the server
3. Moodle cron configured
4. NCALayer installed on each **signer workstation**
   - NCALayer is not installed on the server for manual signing
5. FPDI installed inside the plugin directory on the server:

```bash
cd /path/to/moodle/local/ncasign
composer require setasign/fpdi-tcpdf:^2.3 --no-dev
```
6. NCANode running and reachable from the Moodle server
7. NCANode base URL configured in plugin settings

## Deployment steps

1. Copy the plugin to the Moodle server:
   - source: `moodle_plugin/local/ncasign`
   - destination: `<moodle_root>/local/ncasign`

2. Run Moodle upgrade:
   - open `Site administration -> Notifications`

3. Purge caches:
   - `Site administration -> Development -> Purge all caches`

4. Confirm the protocol PDF template exists on the server in a readable location.
5. Configure server-side verification settings:
   - `Site administration -> Plugins -> Local plugins -> NCA Signing`
   - set `NCANode base URL`
   - set `NCANode request timeout`
   - set `Check OCSP in NCANode`
   - set `Check CRL in NCANode`

## Template profile setup

1. Open:
   - `/local/ncasign/templates.php`

2. Create a template profile.

3. Fill these fields:
   - `Name`: descriptive profile name
   - `Renderer`: current implemented renderer (`Engineer protocol`)
   - `Document type`: `Protocol`
   - `Document title`: desired public title
   - `Template PDF path`: absolute server path to the PDF
   - `Mapped course IDs`: comma-separated Moodle course IDs
   - `Signer sequence`: one signer per line using:
     - `email|display name|position|expected IIN`
   - `Active`: enabled

4. `Layout config (JSON)` may be left empty for now.
   - It is reserved for future profile-driven coordinate configuration.
   - The current engineer protocol renderer still uses code-defined coordinates.

## Manual signing test flow

1. Student completes a mapped course.
2. Plugin generates the draft document.
3. Signer 1 receives the email.
4. Signer 1 opens the link and signs through NCALayer.
5. Signer 2 then receives the next email.
6. Signer 3 receives the final signer email after signer 2 signs.
7. After signer 3 signs, the student receives the completion email with the signed artifact link.

## Operational notes

- Signers must have NCALayer running on their own machine before opening the signing page.
- The signing request now uses `kz.gov.pki.knca.basics` with detached CMS signing and requests TSA embedding on the NCALayer side.
- The server now verifies each received CMS through NCANode before advancing the signer workflow.
- The plugin now uses **template-profile signers**, not the removed global signer settings.
- The plugin now uses **template-profile PDF paths**, not the removed global template path setting.

## Production hardening required after manual deployment

Before this can be presented as a production-grade legally hardened implementation, the following work is still required:

1. Embed signatures into the final PDF using a true PAdES-capable signing flow.
2. Add trusted timestamping (TSA).
3. Add OCSP/CRL validation and store validation evidence.
4. Validate signer certificate chain against the approved trust chain.
5. Add long-term validation packaging if required by the final acceptance criteria.
6. Replace the current detached-CMS evidence flow with real incremental PDF signature embedding.

## Current limitation

The deployment is suitable for the current manual-signing workflow and template-profile testing, but the full client rollout still depends on receiving:
- all final PDF templates
- the remaining document families
- final field placements for those templates

Without the final templates, the document generation layer cannot be completed for the full production scope.
