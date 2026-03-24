# Manual Signing Deployment Notes

## Important

**All final document templates are required before the full rollout can continue.**

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

## Deployment steps

1. Copy the plugin to the Moodle server:
   - source: `moodle_plugin/local/ncasign`
   - destination: `<moodle_root>/local/ncasign`

2. Run Moodle upgrade:
   - open `Site administration -> Notifications`

3. Purge caches:
   - `Site administration -> Development -> Purge all caches`

4. Confirm the protocol PDF template exists on the server in a readable location.

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
     - `email|display name|position`
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
- The plugin now uses **template-profile signers**, not the removed global signer settings.
- The plugin now uses **template-profile PDF paths**, not the removed global template path setting.

## Current limitation

The deployment is suitable for the current manual-signing workflow and template-profile testing, but the full client rollout still depends on receiving:
- all final PDF templates
- the remaining document families
- final field placements for those templates

Without the final templates, the document generation layer cannot be completed for the full production scope.
