# local_ncasign (Demo)

This is a Moodle demo plugin for your workflow:

1. Student completes a course.
2. Signing requests are emailed to associated members (signers).
3. If signers do not act before the deadline, server fallback auto-signs.
4. Student gets the final signed-certificate notification email.

This demo records signing state and emails. It does **not** perform real NCALayer cryptographic signing yet.

## What This Plugin Includes

- `course_completed` observer to create signing jobs automatically.
- Admin settings for window/deadline and role-based signers.
- Manual signer page with secure token links (`/local/ncasign/sign.php?token=...`).
- Scheduled task every 15 minutes for auto-sign fallback.
- Admin UI to list jobs and create demo jobs.
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
3. Submit.
4. Check:
   - `/local/ncasign/index.php`
5. Open signer link from email and click **Sign certificate**.
6. Verify job becomes `completed_manual` when all signers act.

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

## Next Step To Make It Real

Replace the manual-sign click handler in `sign.php` with real NCALayer integration and a server endpoint that verifies/stores real signatures.
