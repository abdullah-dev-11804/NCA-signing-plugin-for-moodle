# local_ncasign (Demo)

This is a Moodle demo plugin for your workflow:

1. Student completes a course.
2. Signing requests are emailed to associated members (signers).
3. If signers do not act before the deadline, server fallback auto-signs.
4. Student gets the final signed-certificate notification email.

This plugin performs a **real NCALayer CMS signing action** on signer machines and stores signature artifacts in Moodle:

- Original certificate PDF (`local_ncasign` file area: `originalpdf`)
- Signer CMS signature (`local_ncasign` file area: `signatures`, `.p7s`)

## What This Plugin Includes

- `course_completed` observer to create signing jobs automatically.
- `mod_customcert\event\certificate_issued` observer to create jobs from real issued certificates.
- Admin settings for window/deadline and role-based signers.
- Manual signer page with secure token links (`/local/ncasign/sign.php?token=...`) and NCALayer CMS signing call.
- Scheduled task every 15 minutes for auto-sign fallback.
- Admin UI to list jobs, create demo jobs, and download stored artifacts.
- CLI helper to create demo jobs quickly.

## Install Steps

1. Copy plugin folder into Moodle:
   - Source folder in this workspace: `moodle_plugin/local/ncasign`
   - Destination in Moodle: `<moodle_root>/local/ncasign`
2. Go to Moodle admin page to trigger installation/upgrade.
3. Configure plugin:
   - `Site administration -> Plugins -> Local plugins -> NCA Signing (demo)`

## Required Settings

- `Enable plugin`: `Yes`
- `Manual signing window (hours)`: e.g. `24`
- `Enable auto-sign fallback`: `Yes`
- `Notify role IDs (comma separated)`: role IDs to notify in the completed course context
  - Example: `3,4`
- `Certificate URL template`: default is demo-safe
  - `/mock/certificate.php?course={courseid}&user={userid}`

## Demo Test Flow (UI)

1. Open:
   - `/local/ncasign/create_demo_job.php`
2. Enter:
   - student `userid`
   - `courseid`
   - signer emails (comma separated), or leave empty to use configured role IDs
   - optional certificate PDF upload (recommended for real file-signing tests)
3. Submit.
4. Check:
   - `/local/ncasign/index.php`
5. Open signer link from email.
6. Start NCALayer on the signer machine.
7. Click **Load available tokens**, choose storage, then click **Sign with NCALayer**.
8. NCALayer signs payload with a real key and result is sent back to Moodle.
   - If a PDF is attached to the job, NCALayer signs actual PDF bytes.
   - If no PDF is attached, plugin falls back to metadata payload signing.
9. Verify job becomes `completed_manual` when all signers act.

## Demo Test Flow (Auto-sign)

1. Create job.
2. Do not sign manually.
3. Set a short manual window (e.g. `1` hour) or edit deadline directly in DB for testing.
4. Run Moodle cron:
   - `php admin/cli/cron.php`
5. Verify job moves to `completed_auto`.

## CLI Demo Job

From Moodle root:

```bash
php local/ncasign/cli/create_demo_job.php --userid=5 --courseid=2 --emails=approver1@example.com,approver2@example.com
```

If `--emails` is omitted, plugin uses configured role IDs in the course context.

## Notes

- Job artifacts can be downloaded from `/local/ncasign/index.php`:
  - `PDF` = original stored certificate
  - `CMS signer #X` = signer detached/attached CMS signature file (`.p7s`)
- Current implementation stores CMS signature hash/length/preview and payload hash in audit metadata.
- If you need PDF-embedded visual signature (PAdES), add a PDF post-processing stage. Current stage is CMS artifact generation and workflow orchestration.

## customcert Template Design Guidance

Your client slide shows official protocol/certificate forms with fixed layout and signature blocks. In `mod_customcert`, create template versions that preserve that exact visual layout and add:

1. Dynamic student fields (name, course, dates, IDs).
2. Reserved signature block area for each signer role.
3. Certificate identifier/QR area.
4. Optional "digitally signed" metadata line (signer, timestamp, hash).

The plugin signs issued PDF bytes; template design remains in `mod_customcert`.
