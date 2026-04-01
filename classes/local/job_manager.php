<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ncasign\local;

defined('MOODLE_INTERNAL') || die();

use core_user;

/**
 * Signing job orchestration.
 */
class job_manager {
    /** @var string */
    public const FILEAREA_ORIGINALPDF = 'originalpdf';
    /** @var string */
    public const FILEAREA_SIGNATURES = 'signatures';
    /** @var string */
    public const FILEAREA_SIGNEDPDF = 'signedpdf';
    /** @var string */
    public const JOB_PENDING = 'pending_manual';
    /** @var string */
    public const JOB_COMPLETED_MANUAL = 'completed_manual';
    /** @var string */
    public const JOB_COMPLETED_AUTO = 'completed_auto';
    /** @var string */
    public const JOB_FINALIZE_FAILED = 'finalize_failed';

    /** @var string */
    public const SIGNER_PENDING = 'pending';
    /** @var string */
    public const SIGNER_SIGNED = 'signed_manual';
    /** @var string */
    public const SIGNER_SKIPPED = 'skipped_auto';

    /**
     * Create a signing job and notify signers.
     *
     * $signers format:
     * [
     *   ['id' => 123, 'email' => 'a@b.com', 'name' => 'Jane'],
     *   ['email' => 'x@y.com', 'name' => 'External']
     * ]
     *
     * @param int $userid
     * @param int $courseid
     * @param string $certificateurl
     * @param array $signers
     * @param int|null $manualwindowhours
     * @return int
     */
    public function create_job(
        int $userid,
        int $courseid,
        string $certificateurl,
        array $signers,
        ?int $manualwindowhours = null,
        string $documenttype = 'certificate',
        string $documenttitle = '',
        bool $sendnotifications = true,
        ?int $templateprofileid = null
    ): int {
        global $DB;

        $now = time();
        $manualwindowhours = $manualwindowhours ?? (int)get_config('local_ncasign', 'manualwindowhours');
        if ($manualwindowhours <= 0) {
            $manualwindowhours = 24;
        }

        $job = (object)[
            'timecreated' => $now,
            'timemodified' => $now,
            'userid' => $userid,
            'courseid' => $courseid,
            'templateprofileid' => $templateprofileid,
            'documentuuid' => $this->generate_document_uuid(),
            'documenttype' => $this->normalise_document_type($documenttype),
            'documenttitle' => trim($documenttitle) !== '' ? trim($documenttitle) : null,
            'drafthash' => null,
            'finalhash' => null,
            'finalizerbackend' => null,
            'finalizationmanifest' => null,
            'certificateurl' => $certificateurl,
            'status' => self::JOB_PENDING,
            'manualdeadline' => $now + ($manualwindowhours * HOURSECS),
            'manualcompleted' => null,
            'autosigned' => null,
            'autosignnote' => null,
        ];
        $jobid = $DB->insert_record('local_ncasign_jobs', $job);

        $signorder = 1;
        foreach ($signers as $signer) {
            if (empty($signer['email'])) {
                continue;
            }
            $token = $this->generate_unique_token();
            $record = (object)[
                'jobid' => $jobid,
                'signerid' => $signer['id'] ?? null,
                'signeremail' => trim($signer['email']),
                'signername' => trim((string)($signer['name'] ?? $signer['email'])),
                'signerposition' => trim((string)($signer['position'] ?? ('Commission member ' . $signorder))),
                'expectediin' => ($expectediin = preg_replace('/\D+/', '', (string)($signer['expectediin'] ?? ''))) !== '' ? $expectediin : null,
                'signorder' => $signorder,
                'token' => $token,
                'status' => self::SIGNER_PENDING,
                'timecreated' => $now,
                'timemodified' => $now,
                'notifiedat' => null,
                'signedat' => null,
                'signedby' => null,
                'rawcms' => null,
                'signercertificate' => null,
                'signeriin' => null,
                'ocspresponse' => null,
                'signingmethod' => null,
                'verificationstatus' => null,
                'verificationinfo' => null,
                'signmeta' => null,
            ];
            $DB->insert_record('local_ncasign_signers', $record);
            $signorder++;
        }

        if ($sendnotifications) {
            $this->notify_signers_for_job($jobid);
        }

        return (int)$jobid;
    }

    /**
     * Attach original certificate PDF content to a job.
     *
     * @param int $jobid
     * @param string $filename
     * @param string $content
     * @param string $source
     * @param array<string,mixed>|null $finalizationmanifest
     * @return void
     */
    public function attach_certificate_binary_to_job(
        int $jobid,
        string $filename,
        string $content,
        string $source = 'manual',
        ?array $finalizationmanifest = null
    ): void {
        global $DB;

        if ($content === '') {
            return;
        }

        $context = \context_system::instance();
        $fs = get_file_storage();

        // Replace existing original certificate for this job.
        $existing = $fs->get_area_files(
            $context->id,
            'local_ncasign',
            self::FILEAREA_ORIGINALPDF,
            $jobid,
            'id',
            false
        );
        foreach ($existing as $file) {
            $file->delete();
        }

        $filename = trim($filename);
        if ($filename === '' || $filename === '.') {
            $filename = "certificate_job_{$jobid}.pdf";
        }
        if (!preg_match('/\.pdf$/i', $filename)) {
            $filename .= '.pdf';
        }

        $record = (object)[
            'contextid' => $context->id,
            'component' => 'local_ncasign',
            'filearea' => self::FILEAREA_ORIGINALPDF,
            'itemid' => $jobid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => 0,
            'author' => 'local_ncasign',
            'license' => 'allrightsreserved',
            'source' => $source,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $fs->create_file_from_string($record, $content);

        $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', MUST_EXIST);
        if (empty($job->documenttitle)) {
            $job->documenttitle = pathinfo($filename, PATHINFO_FILENAME);
        }
        if (empty($job->documenttype) || $job->documenttype === 'certificate') {
            $job->documenttype = $this->infer_document_type((string)$job->documenttitle, (string)$filename);
        }
        $job->drafthash = hash('sha256', $content);
        $job->finalhash = null;
        if ($finalizationmanifest !== null) {
            $job->finalizationmanifest = json_encode($finalizationmanifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (strpos((string)$job->certificateurl, 'stored://') !== 0) {
            $job->certificateurl = "stored://local_ncasign/" . self::FILEAREA_ORIGINALPDF . "/{$jobid}/{$filename}";
        }
        $job->timemodified = time();
        $DB->update_record('local_ncasign_jobs', $job);
    }

    /**
     * Get stored original certificate PDF for a job.
     *
     * @param int $jobid
     * @return array|null
     */
    public function get_job_certificate_binary(int $jobid): ?array {
        $context = \context_system::instance();
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            'local_ncasign',
            self::FILEAREA_ORIGINALPDF,
            $jobid,
            'id DESC',
            false
        );

        if (!$files) {
            return null;
        }
        $file = reset($files);
        $content = $file->get_content();
        return [
            'filename' => $file->get_filename(),
            'mimetype' => $file->get_mimetype(),
            'content' => $content,
            'sha256' => hash('sha256', $content),
            'filesize' => $file->get_filesize(),
        ];
    }

    /**
     * Return the currently signable PDF bytes for a job.
     *
     * If signer progress PDF exists, later signers sign that version.
     * Otherwise the original draft/original PDF is used.
     *
     * @param int $jobid
     * @return array|null
     */
    public function get_job_signing_payload_binary(int $jobid): ?array {
        $signed = $this->get_job_signed_pdf_binary($jobid);
        if ($signed) {
            $signed['sourcearea'] = self::FILEAREA_SIGNEDPDF;
            return $signed;
        }

        $original = $this->get_job_certificate_binary($jobid);
        if ($original) {
            $original['sourcearea'] = self::FILEAREA_ORIGINALPDF;
        }
        return $original;
    }

    /**
     * Get the original stored file object for a job.
     *
     * @param int $jobid
     * @return \stored_file|null
     */
    public function get_job_original_file(int $jobid): ?\stored_file {
        return $this->get_latest_file_from_area(self::FILEAREA_ORIGINALPDF, $jobid);
    }

    /**
     * Check whether original PDF exists for a job.
     *
     * @param int $jobid
     * @return bool
     */
    public function has_job_original_pdf(int $jobid): bool {
        return $this->get_latest_file_from_area(self::FILEAREA_ORIGINALPDF, $jobid) !== null;
    }

    /**
     * Check whether signed PDF exists for a job.
     *
     * @param int $jobid
     * @return bool
     */
    public function has_job_signed_pdf(int $jobid): bool {
        return $this->get_latest_file_from_area(self::FILEAREA_SIGNEDPDF, $jobid) !== null;
    }

    /**
     * Get signed PDF binary payload for a job.
     *
     * @param int $jobid
     * @return array|null
     */
    public function get_job_signed_pdf_binary(int $jobid): ?array {
        $file = $this->get_latest_file_from_area(self::FILEAREA_SIGNEDPDF, $jobid);
        if (!$file) {
            return null;
        }
        $content = $file->get_content();
        return [
            'filename' => $file->get_filename(),
            'mimetype' => $file->get_mimetype(),
            'content' => $content,
            'sha256' => hash('sha256', $content),
            'filesize' => $file->get_filesize(),
        ];
    }

    /**
     * Generate and store signed PDF artifact containing QR block.
     *
     * @param int $jobid
     * @return string|null stored filename
     */
    public function generate_signed_pdf_artifact(int $jobid): ?string {
        global $DB;

        $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', IGNORE_MISSING);
        if (!$job) {
            return null;
        }

        $finalizer = pades_finalizer_factory::create();
        $payload = $finalizer->supports_embedded_pades()
            ? $this->get_job_signing_payload_binary($jobid)
            : $this->get_job_certificate_binary($jobid);
        if (!$payload) {
            return null;
        }

        $verificationurl = $this->get_verification_url_for_job((int)$job->id);
        $isfinal = !$this->has_pending_signers($jobid) || $job->status !== self::JOB_PENDING;
        $result = $finalizer->finalize([
            'job' => $job,
            'originalpdf' => $payload['content'],
            'originalfilename' => $payload['filename'],
            'originalsha256' => hash('sha256', $payload['content']),
            'verifyurl' => $verificationurl,
            'signers' => $this->get_signer_records($jobid),
            'completedsignerblocks' => $this->get_completed_signer_blocks($jobid),
            'manifest' => $this->get_job_finalization_manifest($job),
            'isfinal' => $isfinal,
        ]);
        $signedcontent = (string)($result['content'] ?? '');
        if ($signedcontent === '') {
            return null;
        }

        $context = \context_system::instance();
        $fs = get_file_storage();
        $filename = (string)($result['filename'] ?? ($isfinal ? "signed_final_job_{$jobid}.pdf" : "signed_progress_job_{$jobid}.pdf"));

        $existing = $fs->get_area_files(
            $context->id,
            'local_ncasign',
            self::FILEAREA_SIGNEDPDF,
            $jobid,
            'id',
            false
        );
        foreach ($existing as $file) {
            $file->delete();
        }

        $record = (object)[
            'contextid' => $context->id,
            'component' => 'local_ncasign',
            'filearea' => self::FILEAREA_SIGNEDPDF,
            'itemid' => $jobid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => 0,
            'author' => 'local_ncasign',
            'license' => 'allrightsreserved',
            'source' => (string)($result['source'] ?? ($isfinal ? 'ncasign_qr_overlay_final' : 'ncasign_qr_overlay_progress')),
            'mimetype' => 'application/pdf',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $fs->create_file_from_string($record, $signedcontent);

        $job->finalhash = null;
        $job->finalizerbackend = (string)($result['backend'] ?? $finalizer->get_backend_name());
        $job->finalhash = !empty($result['finalhash']) ? (string)$result['finalhash'] : null;
        $job->timemodified = time();
        $DB->update_record('local_ncasign_jobs', $job);
        return $filename;
    }

    /**
     * Return decoded job finalization manifest.
     *
     * @param \stdClass $job
     * @return array<string,mixed>
     */
    public function get_job_finalization_manifest(\stdClass $job): array {
        if (empty($job->finalizationmanifest) || !is_string($job->finalizationmanifest)) {
            return [];
        }
        $decoded = json_decode($job->finalizationmanifest, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Store signer CMS signature artifact.
     *
     * @param int $jobid
     * @param int $signerrecordid
     * @param string $cms
     * @return string stored filename
     */
    public function store_signer_cms_signature(int $jobid, int $signerrecordid, string $cms): string {
        $context = \context_system::instance();
        $fs = get_file_storage();

        $filename = "signer_{$signerrecordid}.p7s";
        $cms = trim($cms);
        $cms = preg_replace('/-----BEGIN CMS-----|-----END CMS-----/u', '', $cms) ?? $cms;
        $cmscompact = preg_replace('/\s+/', '', $cms);
        $binarycms = base64_decode((string)$cmscompact, true);
        if ($binarycms === false || $binarycms === '') {
            $binarycms = $cms;
        }
        $existing = $fs->get_file(
            $context->id,
            'local_ncasign',
            self::FILEAREA_SIGNATURES,
            $jobid,
            '/',
            $filename
        );
        if ($existing) {
            $existing->delete();
        }

        $record = (object)[
            'contextid' => $context->id,
            'component' => 'local_ncasign',
            'filearea' => self::FILEAREA_SIGNATURES,
            'itemid' => $jobid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => 0,
            'author' => 'local_ncasign',
            'license' => 'allrightsreserved',
            'source' => 'ncalayer',
            'mimetype' => 'application/pkcs7-signature',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $fs->create_file_from_string($record, $binarycms);
        return $filename;
    }

    /**
     * Get signer+job by token.
     *
     * @param string $token
     * @return array|null
     */
    public function get_signer_by_token(string $token): ?array {
        global $DB;

        $signer = $DB->get_record('local_ncasign_signers', ['token' => $token]);
        if (!$signer) {
            return null;
        }
        $job = $DB->get_record('local_ncasign_jobs', ['id' => $signer->jobid], '*', MUST_EXIST);
        return ['signer' => $signer, 'job' => $job];
    }

    /**
     * Send email notifications to all signers on a job.
     *
     * @param int $jobid
     * @return void
     */
    public function notify_signers_for_job(int $jobid): void {
        global $DB;

        $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', IGNORE_MISSING);
        if (!$job) {
            return;
        }

        $signer = $this->get_active_pending_signer($jobid);
        if (!$signer || !empty($signer->notifiedat)) {
            return;
        }

        $this->send_signer_email($signer, $job, (string)($signer->signername ?? $signer->signeremail));
        $signer->notifiedat = time();
        $signer->timemodified = time();
        $DB->update_record('local_ncasign_signers', $signer);
    }

    /**
     * Mark signer as manually signed.
     *
     * @param string $token
     * @param string $signedby
     * @param array $meta
     * @return bool
     */
    public function mark_signer_signed(string $token, string $signedby = 'manual', array $meta = [], array $evidence = []): bool {
        global $DB;

        $row = $this->get_signer_by_token($token);
        if (!$row) {
            return false;
        }

        /** @var \stdClass $signer */
        $signer = $row['signer'];
        if ($signer->status === self::SIGNER_SIGNED) {
            return true;
        }
        if (!$this->is_signer_active($signer)) {
            return false;
        }

        $now = time();
        $signer->status = self::SIGNER_SIGNED;
        $signer->signedat = $now;
        $signer->signedby = $signedby;
        if (array_key_exists('rawcms', $evidence)) {
            $signer->rawcms = $evidence['rawcms'];
        }
        if (array_key_exists('signercertificate', $evidence)) {
            $signer->signercertificate = $evidence['signercertificate'];
        }
        if (array_key_exists('signeriin', $evidence)) {
            $signer->signeriin = $evidence['signeriin'];
        }
        if (array_key_exists('ocspresponse', $evidence)) {
            $signer->ocspresponse = $evidence['ocspresponse'];
        }
        if (array_key_exists('signingmethod', $evidence)) {
            $signer->signingmethod = $evidence['signingmethod'];
        }
        if (array_key_exists('verificationstatus', $evidence)) {
            $signer->verificationstatus = $evidence['verificationstatus'];
        }
        if (array_key_exists('verificationinfo', $evidence)) {
            $signer->verificationinfo = $evidence['verificationinfo'];
        }
        $signer->signmeta = json_encode($meta);
        $signer->timemodified = $now;
        $DB->update_record('local_ncasign_signers', $signer);

        if ($this->has_pending_signers((int)$signer->jobid)) {
            try {
                $this->ensure_original_pdf_for_job((int)$signer->jobid);
                $this->generate_signed_pdf_artifact((int)$signer->jobid);
                $this->record_finalization_note((int)$signer->jobid, null);
            } catch (\Throwable $e) {
                error_log('local_ncasign: failed to refresh signed PDF artifact after signer update: ' . $e->getMessage());
                $this->record_finalization_note((int)$signer->jobid, 'Progress/final PDF refresh failed: ' . $e->getMessage());
            }
            $this->notify_signers_for_job((int)$signer->jobid);
            return true;
        }

        $this->complete_job_if_fully_signed((int)$signer->jobid);
        return true;
    }

    /**
     * Complete job manually if all signers have signed.
     *
     * @param int $jobid
     * @return void
     */
    public function complete_job_if_fully_signed(int $jobid): void {
        global $DB;

        $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', MUST_EXIST);
        if ($job->status !== self::JOB_PENDING) {
            return;
        }

        $pending = $DB->count_records('local_ncasign_signers', [
            'jobid' => $jobid,
            'status' => self::SIGNER_PENDING,
        ]);
        if ($pending > 0) {
            return;
        }

        try {
            $this->ensure_original_pdf_for_job($jobid);
            $this->generate_signed_pdf_artifact($jobid);
            if (!$this->has_job_signed_pdf($jobid) || empty($DB->get_field('local_ncasign_jobs', 'finalhash', ['id' => $jobid]))) {
                $job->status = self::JOB_FINALIZE_FAILED;
                $job->timemodified = time();
                $job->autosignnote = 'Final PDF was not generated after all signers completed.';
                $DB->update_record('local_ncasign_jobs', $job);
                error_log('local_ncasign: final signed PDF was not generated for job ' . $jobid);
                return;
            }
        } catch (\Throwable $e) {
            error_log('local_ncasign: failed to generate signed PDF artifact: ' . $e->getMessage());
            $job->status = self::JOB_FINALIZE_FAILED;
            $job->timemodified = time();
            $job->autosignnote = 'Final PDF generation failed: ' . $e->getMessage();
            $DB->update_record('local_ncasign_jobs', $job);
            return;
        }

        $job->status = self::JOB_COMPLETED_MANUAL;
        $job->manualcompleted = time();
        $job->autosignnote = null;
        $job->timemodified = time();
        $DB->update_record('local_ncasign_jobs', $job);

        $this->send_student_completion_email($job, false);
    }

    /**
     * Store a job-level finalization note without changing workflow ownership.
     *
     * @param int $jobid
     * @param string|null $message
     * @return void
     */
    private function record_finalization_note(int $jobid, ?string $message): void {
        global $DB;

        $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', IGNORE_MISSING);
        if (!$job) {
            return;
        }
        $job->autosignnote = $message;
        $job->timemodified = time();
        $DB->update_record('local_ncasign_jobs', $job);
    }

    /**
     * Auto-sign overdue jobs.
     *
     * @param int $limit
     * @return int number of jobs processed
     */
    public function process_due_jobs(int $limit = 100): int {
        global $DB;

        $autosignenabled = (int)get_config('local_ncasign', 'autosignenabled');
        if (!$autosignenabled) {
            return 0;
        }

        $now = time();
        $jobs = $DB->get_records_select(
            'local_ncasign_jobs',
            'status = :status AND manualdeadline <= :now',
            ['status' => self::JOB_PENDING, 'now' => $now],
            'manualdeadline ASC',
            '*',
            0,
            $limit
        );

        $count = 0;
        foreach ($jobs as $job) {
            $signers = $DB->get_records('local_ncasign_signers', ['jobid' => $job->id]);
            foreach ($signers as $signer) {
                if ($signer->status === self::SIGNER_PENDING) {
                    $signer->status = self::SIGNER_SKIPPED;
                    $signer->timemodified = $now;
                    $signer->signmeta = json_encode(['reason' => 'manual_deadline_passed']);
                    $DB->update_record('local_ncasign_signers', $signer);
                }
            }

            $job->status = self::JOB_COMPLETED_AUTO;
            $job->autosigned = $now;
            $job->autosignnote = get_config('local_ncasign', 'autosignnote');
            $job->timemodified = $now;
            $DB->update_record('local_ncasign_jobs', $job);
            try {
                $this->ensure_original_pdf_for_job((int)$job->id);
                $this->generate_signed_pdf_artifact((int)$job->id);
            } catch (\Throwable $e) {
                error_log('local_ncasign: failed to generate auto-sign PDF artifact for job ' . (int)$job->id . ': ' . $e->getMessage());
            }
            $this->send_student_completion_email($job, true);
            $count++;
        }

        return $count;
    }

    /**
     * Build default certificate URL from template.
     *
     * @param int $courseid
     * @param int $userid
     * @return string
     */
    public function build_certificate_url(int $courseid, int $userid): string {
        $template = (string)get_config('local_ncasign', 'certurltemplate');
        if ($template === '') {
            $template = '/mock/certificate.php?course={courseid}&user={userid}';
        }

        return str_replace(
            ['{courseid}', '{userid}'],
            [(string)$courseid, (string)$userid],
            $template
        );
    }

    /**
     * Return ordered signer records for a job.
     *
     * @param int $jobid
     * @return array<int, \stdClass>
     */
    public function get_signer_records(int $jobid): array {
        global $DB;

        return array_values($DB->get_records('local_ncasign_signers', ['jobid' => $jobid], 'signorder ASC, id ASC'));
    }

    /**
     * Return the current active pending signer.
     *
     * @param int $jobid
     * @return \stdClass|null
     */
    public function get_active_pending_signer(int $jobid): ?\stdClass {
        foreach ($this->get_signer_records($jobid) as $signer) {
            if ($signer->status === self::SIGNER_PENDING) {
                return $signer;
            }
        }

        return null;
    }

    /**
     * Whether a signer record is the currently active signer.
     *
     * @param \stdClass $signer
     * @return bool
     */
    public function is_signer_active(\stdClass $signer): bool {
        $active = $this->get_active_pending_signer((int)$signer->jobid);
        return $active && (int)$active->id === (int)$signer->id;
    }

    /**
     * Persist prepared signing payload state for an active pending signer.
     *
     * @param int $signerid
     * @param array<string,mixed> $state
     * @return void
     */
    public function store_pending_prepare_state(int $signerid, array $state): void {
        global $DB;

        $signer = $DB->get_record('local_ncasign_signers', ['id' => $signerid], '*', IGNORE_MISSING);
        if (!$signer || (string)$signer->status !== self::SIGNER_PENDING) {
            return;
        }

        $meta = [];
        if (!empty($signer->signmeta) && is_string($signer->signmeta)) {
            $decoded = json_decode($signer->signmeta, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $meta['prepare_state'] = $state;
        $signer->signmeta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signer->timemodified = time();
        $DB->update_record('local_ncasign_signers', $signer);
    }

    /**
     * Return persisted prepared signing payload state for a signer.
     *
     * @param \stdClass $signer
     * @return array<string,mixed>
     */
    public function get_pending_prepare_state(\stdClass $signer): array {
        if (empty($signer->signmeta) || !is_string($signer->signmeta)) {
            return [];
        }
        $decoded = json_decode($signer->signmeta, true);
        if (!is_array($decoded)) {
            return [];
        }
        $state = $decoded['prepare_state'] ?? [];
        return is_array($state) ? $state : [];
    }

    /**
     * Whether a job still has pending signers.
     *
     * @param int $jobid
     * @return bool
     */
    public function has_pending_signers(int $jobid): bool {
        return $this->get_active_pending_signer($jobid) !== null;
    }

    /**
     * Build QR/signature block metadata for completed signers.
     *
     * @param int $jobid
     * @return array<int, array<string, string>>
     */
    private function get_completed_signer_blocks(int $jobid): array {
        $verifyurl = $this->get_verification_url_for_job($jobid);
        $blocks = [];
        foreach ($this->get_signer_records($jobid) as $signer) {
            if ($signer->status !== self::SIGNER_SIGNED) {
                continue;
            }

            $blocks[] = [
                'label' => trim((string)($signer->signername ?? 'Signer ' . (int)$signer->signorder)),
                'payload' => $verifyurl . '&signer=' . (int)$signer->signorder,
                'signedat' => !empty($signer->signedat) ? userdate((int)$signer->signedat) : '',
            ];
        }

        return $blocks;
    }

    /**
     * Resolve signers from the first mapped template profile for a course.
     *
     * @param \context_course $context
     * @return array
     */
    public function get_signers_from_configured_roles(\context_course $context): array {
        $profiles = (new template_manager())->get_course_template_profiles((int)$context->instanceid);
        if (!$profiles) {
            return [];
        }

        return $profiles[0]['signers'] ?? [];
    }

    /**
     * Send email to signer.
     *
     * @param \stdClass $signer
     * @param \stdClass $job
     * @param string $name
     * @return void
     */
    private function send_signer_email(\stdClass $signer, \stdClass $job, string $name): void {
        global $CFG;

        if (empty($signer->signeremail)) {
            return;
        }

        $link = $CFG->wwwroot . '/local/ncasign/sign.php?token=' . urlencode($signer->token);
        $draftlink = $CFG->wwwroot . '/local/ncasign/draft.php?token=' . urlencode($signer->token);
        $progresslink = $CFG->wwwroot . '/local/ncasign/draft.php?token=' . urlencode($signer->token) . '&type=signedpdf';
        $deadline = userdate($job->manualdeadline);
        $documenttitle = trim((string)($job->documenttitle ?? ''));
        if ($documenttitle === '') {
            $documenttitle = 'Course document';
        }
        $totalcount = max(1, count($this->get_signer_records((int)$job->id)));
        $subject = 'Signature required (' . (int)$signer->signorder . ' of ' . $totalcount . '): ' . $documenttitle;
        $message = "Hello {$name},\n\n" .
            "A document is ready for your signature in the commission sequence.\n" .
            "Signing order: " . (int)$signer->signorder . " of {$totalcount}\n" .
            "Document: {$documenttitle}\n" .
            "Document type: " . ucfirst((string)$job->documenttype) . "\n" .
            "Student user ID: {$job->userid}\n" .
            "Course ID: {$job->courseid}\n" .
            "Position: " . (string)($signer->signerposition ?? 'Commission member') . "\n";
        if ($this->has_job_original_pdf((int)$job->id)) {
            $message .= "Draft PDF: {$draftlink}\n";
        }
        if ($this->has_job_signed_pdf((int)$job->id)) {
            $message .= "Current signed PDF progress: {$progresslink}\n";
        }
        $message .= "Stored source: {$job->certificateurl}\n\n" .
            "Sign link: {$link}\n" .
            "Deadline: {$deadline}\n\n" .
            "If no manual action is taken, the configured server fallback will be used if enabled.";

        $to = (object)[
            'id' => -1,
            'email' => $signer->signeremail,
            'firstname' => $name !== '' ? $name : 'Signer',
            'lastname' => '',
            'maildisplay' => 1,
            'mailformat' => 1,
            'maildigest' => 0,
            'suspended' => 0,
            'deleted' => 0,
        ];
        $from = core_user::get_support_user();
        email_to_user($to, $from, $subject, $message);
    }

    /**
     * Notify student that certificate is signed.
     *
     * @param \stdClass $job
     * @param bool $auto
     * @return void
     */
    private function send_student_completion_email(\stdClass $job, bool $auto): void {
        global $CFG, $DB;
        $student = $DB->get_record('user', ['id' => $job->userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$student || empty($student->email)) {
            return;
        }
        $from = core_user::get_support_user();
        $mode = $auto ? 'automatic server fallback' : 'manual signer approvals';
        $signedpdflink = $CFG->wwwroot . '/local/ncasign/download_artifact.php?jobid=' . (int)$job->id . '&type=signedpdf';
        $verifylink = $this->get_verification_url_for_job((int)$job->id);
        $subject = 'Your course document has been signed';
        $message = "Your document is now signed ({$mode}).\n\n" .
            "Document: " . (string)$job->documenttitle . "\n" .
            "Course ID: {$job->courseid}\n" .
            "Certificate URL: {$job->certificateurl}\n" .
            "Signed PDF (with signer QR blocks): {$signedpdflink}\n" .
            "Public verification page: {$verifylink}\n";
        email_to_user($student, $from, $subject, $message);
    }

    /**
     * Ensure job has an original PDF, resolving from mod_customcert issue if needed.
     *
     * @param int $jobid
     * @return bool
     */
    public function ensure_original_pdf_for_job(int $jobid): bool {
        global $DB;

        if ($this->has_job_original_pdf($jobid)) {
            return true;
        }

        $job = $this->ensure_job_verification_metadata($jobid);
        if (!$job) {
            return false;
        }

        $sql = "SELECT ci.id, ci.userid
                  FROM {customcert_issues} ci
                  JOIN {customcert} c ON c.id = ci.customcertid
                 WHERE c.course = :courseid
                   AND ci.userid = :userid
              ORDER BY ci.timecreated DESC, ci.id DESC";
        $issue = $DB->get_record_sql($sql, ['courseid' => (int)$job->courseid, 'userid' => (int)$job->userid], IGNORE_MULTIPLE);
        if (!$issue) {
            error_log('local_ncasign: no customcert issue found for job ' . $jobid . ' (course ' . (int)$job->courseid . ', user ' . (int)$job->userid . ')');
            return false;
        }

        $pdf = $this->generate_customcert_pdf_from_issue((int)$issue->id, (int)$issue->userid);
        if (!$pdf) {
            error_log('local_ncasign: failed to generate PDF from customcert issue ' . (int)$issue->id . ' for job ' . $jobid);
            return false;
        }

        $this->attach_certificate_binary_to_job(
            $jobid,
            $pdf['filename'],
            $pdf['content'],
            'mod_customcert_late_resolve'
        );
        return true;
    }

    /**
     * Fetch latest stored file from an area.
     *
     * @param string $filearea
     * @param int $itemid
     * @return \stored_file|null
     */
    private function get_latest_file_from_area(string $filearea, int $itemid): ?\stored_file {
        $context = \context_system::instance();
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            'local_ncasign',
            $filearea,
            $itemid,
            'id DESC',
            false
        );

        if (!$files) {
            return null;
        }

        $file = reset($files);
        return $file ?: null;
    }

    /**
     * Return the public verification URL used in the embedded QR.
     *
     * @param int $jobid
     * @return string
     */
    public function get_verification_url_for_job(int $jobid): string {
        global $CFG;

        $job = $this->ensure_job_verification_metadata($jobid);
        if (!$job || empty($job->documentuuid)) {
            return $CFG->wwwroot;
        }

        $checksum = $this->get_verification_checksum((string)$job->documentuuid);
        $url = new \moodle_url('/local/ncasign/verify.php', [
            'id' => (string)$job->documentuuid,
            'hash' => $checksum,
        ]);
        return $url->out(false);
    }

    /**
     * Resolve a signing job by public document UUID.
     *
     * @param string $documentuuid
     * @return \stdClass|null
     */
    public function get_job_by_documentuuid(string $documentuuid): ?\stdClass {
        global $DB;

        return $DB->get_record('local_ncasign_jobs', ['documentuuid' => $documentuuid]) ?: null;
    }

    /**
     * Return the deterministic QR checksum for a document UUID.
     *
     * @param string $documentuuid
     * @return string
     */
    public function get_verification_checksum(string $documentuuid): string {
        return substr(hash('sha256', $documentuuid), 0, 16);
    }

    /**
     * Ensure a job has verification metadata populated.
     *
     * @param int $jobid
     * @return \stdClass|null
     */
    public function ensure_job_verification_metadata(int $jobid): ?\stdClass {
        global $DB;

        $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', IGNORE_MISSING);
        if (!$job) {
            return null;
        }

        $updated = false;
        if (empty($job->documentuuid)) {
            $job->documentuuid = $this->generate_document_uuid();
            $updated = true;
        }
        if (empty($job->documenttype)) {
            $job->documenttype = 'certificate';
            $updated = true;
        }
        if (empty($job->documenttitle)) {
            $course = $DB->get_record('course', ['id' => (int)$job->courseid], 'fullname', IGNORE_MISSING);
            $job->documenttitle = $course ? $course->fullname : 'Signed document';
            $updated = true;
        }
        $normalisedtype = $this->normalise_document_type((string)$job->documenttype);
        $inferredtype = $this->infer_document_type((string)$job->documenttitle);
        if ($normalisedtype !== (string)$job->documenttype) {
            $job->documenttype = $normalisedtype;
            $updated = true;
        } else if ($normalisedtype === 'certificate' && $inferredtype !== 'certificate') {
            $job->documenttype = $inferredtype;
            $updated = true;
        }

        if ($updated) {
            $job->timemodified = time();
            $DB->update_record('local_ncasign_jobs', $job);
        }

        return $job;
    }

    /**
     * Generate certificate PDF bytes directly from mod_customcert issue data.
     *
     * @param int $issueid
     * @param int $userid
     * @return array|null ['filename' => string, 'content' => string]
     */
    private function generate_customcert_pdf_from_issue(int $issueid, int $userid): ?array {
        global $DB;

        if ($issueid <= 0 || !class_exists('\mod_customcert\template')) {
            return null;
        }

        $issue = $DB->get_record('customcert_issues', ['id' => $issueid], 'id,customcertid,userid', IGNORE_MISSING);
        if (!$issue) {
            return null;
        }

        $customcert = $DB->get_record('customcert', ['id' => (int)$issue->customcertid], 'id,templateid', IGNORE_MISSING);
        if (!$customcert || empty($customcert->templateid)) {
            return null;
        }

        try {
            $template = $this->get_customcert_template_instance((int)$customcert->templateid);
            if (!$template) {
                return null;
            }
            $targetuserid = (int)($issue->userid ?? $userid);
            $content = null;

            if (class_exists('\mod_customcert\service\pdf_generation_service')) {
                $pdfservice = \mod_customcert\service\pdf_generation_service::create();
                $content = $pdfservice->generate_pdf($template, false, $targetuserid, true);
            } else if (method_exists($template, 'generate_pdf')) {
                $content = $template->generate_pdf(false, $targetuserid, true);
            }

            if (!is_string($content) || $content === '') {
                return null;
            }

            return [
                'filename' => "customcert_issue_{$issueid}.pdf",
                'content' => $content,
            ];
        } catch (\Throwable $e) {
            error_log('local_ncasign: customcert PDF generation failed for issue ' . $issueid . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Load mod_customcert template object across plugin versions.
     *
     * @param int $templateid
     * @return object|null
     */
    private function get_customcert_template_instance(int $templateid): ?object {
        global $DB;

        if (!class_exists('\mod_customcert\template')) {
            return null;
        }

        try {
            if (method_exists('\mod_customcert\template', 'load')) {
                return \mod_customcert\template::load($templateid);
            }
            if (method_exists('\mod_customcert\template', 'instance')) {
                return \mod_customcert\template::instance($templateid);
            }
            $templaterecord = $DB->get_record('customcert_templates', ['id' => $templateid], '*', IGNORE_MISSING);
            if (!$templaterecord) {
                error_log('local_ncasign: customcert template record not found id=' . $templateid);
                return null;
            }
            return new \mod_customcert\template($templaterecord);
        } catch (\Throwable $e) {
            error_log('local_ncasign: failed to load customcert template ' . $templateid . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build signed PDF with QR stamp. Uses FPDI overlay if available, else fallback summary page.
     *
     * @param string $originalpdf
     * @param string $qrpayload
     * @param int $jobid
     * @return string|null
     */
    private function build_qr_stamped_pdf(string $originalpdf, string $qrpayload, int $jobid): ?string {
        global $CFG, $DB;

        $this->maybe_load_plugin_vendor_autoload();
        $completedsigners = $this->get_completed_signer_blocks($jobid);
        $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', IGNORE_MISSING);
        if (!$job) {
            return null;
        }
        $originalsha256 = hash('sha256', $originalpdf);
        if (class_exists('\setasign\Fpdi\Tcpdf\Fpdi')) {
            try {
                $tmpdir = make_request_directory();
                if (!$tmpdir) {
                    return $this->build_qr_fallback_pdf($jobid, $qrpayload, $originalsha256);
                }

                $sourcepath = $tmpdir . DIRECTORY_SEPARATOR . "ncasign_job_{$jobid}_source.pdf";
                file_put_contents($sourcepath, $originalpdf);

                $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
                $pdf->SetAutoPageBreak(false, 0);

                $pagecount = $pdf->setSourceFile($sourcepath);
                $style = [
                    'border' => 0,
                    'padding' => 0,
                    'fgcolor' => [0, 0, 0],
                    'bgcolor' => false,
                ];

                for ($pageno = 1; $pageno <= $pagecount; $pageno++) {
                    $templateid = $pdf->importPage($pageno);
                    $size = $pdf->getTemplateSize($templateid);
                    $w = (float)($size['width'] ?? $size['w'] ?? 210.0);
                    $h = (float)($size['height'] ?? $size['h'] ?? 297.0);
                    $orientation = ($w > $h) ? 'L' : 'P';

                    $pdf->AddPage($orientation, [$w, $h]);
                    $pdf->useTemplate($templateid, 0, 0, $w, $h, true);

                    $margin = 6.0;
                    $availableblocks = $completedsigners ?: [[
                        'label' => 'Verification',
                        'payload' => $qrpayload,
                        'signedat' => '',
                    ]];
                    $blockcount = min(3, count($availableblocks));
                    $blockwidth = min(40.0, max(26.0, ($w - (($blockcount + 1) * $margin)) / max(1, $blockcount)));
                    $qrsize = min(18.0, max(12.0, $blockwidth * 0.45));
                    $blockheight = max(18.0, $qrsize + 10.0);
                    $y = max($margin, $h - $blockheight - $margin);

                    for ($i = 0; $i < $blockcount; $i++) {
                        $block = $availableblocks[$i];
                        $x = $margin + ($i * ($blockwidth + $margin));
                        $pdf->write2DBarcode((string)$block['payload'], 'QRCODE,H', $x, $y + 4, $qrsize, $qrsize, $style, 'N');
                        $pdf->SetFont('helvetica', 'B', 6);
                        $pdf->SetXY($x + $qrsize + 2, $y + 3);
                        $pdf->MultiCell(
                            max(8.0, $blockwidth - $qrsize - 2),
                            4,
                            (string)$block['label'],
                            0,
                            'L',
                            false,
                            1
                        );
                        if (!empty($block['signedat'])) {
                            $pdf->SetFont('helvetica', '', 5);
                            $pdf->SetX($x + $qrsize + 2);
                            $pdf->MultiCell(
                                max(8.0, $blockwidth - $qrsize - 2),
                                3,
                                (string)$block['signedat'],
                                0,
                                'L',
                                false,
                                1
                            );
                        }
                    }
                }

                $this->append_signing_evidence_page($pdf, $job, $qrpayload, $originalsha256);

                return $pdf->Output('', 'S');
            } catch (\Throwable $e) {
                error_log('local_ncasign: QR overlay failed, using fallback signed PDF. ' . $e->getMessage());
            }
        }

        return $this->build_qr_fallback_pdf($jobid, $qrpayload, $originalsha256);
    }

    /**
     * Build fallback one-page signed PDF with QR and audit details.
     *
     * @param int $jobid
     * @param string $qrpayload
     * @param string $originalsha256
     * @return string|null
     */
    private function build_qr_fallback_pdf(int $jobid, string $qrpayload, string $originalsha256): ?string {
        global $CFG, $DB;

        $job = $this->ensure_job_verification_metadata($jobid);
        if (!$job) {
            return null;
        }

        require_once($CFG->libdir . '/pdflib.php');
        $pdf = new \pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Write(0, 'NCA Signed Document Verification', '', 0, 'L', true, 0, false, false, 0);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Ln(2);
        $pdf->Write(0, 'Document: ' . (string)$job->documenttitle, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Document type: ' . ucfirst((string)$job->documenttype), '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Job ID: ' . (int)$job->id, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Student User ID: ' . (int)$job->userid, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Course ID: ' . (int)$job->courseid, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Signed at: ' . userdate((int)($job->manualcompleted ?? time())), '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Original PDF SHA256: ' . $originalsha256, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Verify URL: ' . $qrpayload, '', 0, 'L', true, 0, false, false, 0);

        $style = [
            'border' => 0,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ];
        $completedsigners = $this->get_completed_signer_blocks($jobid);
        $blocks = $completedsigners ?: [[
            'label' => 'Verification',
            'payload' => $qrpayload,
            'signedat' => '',
        ]];
        $pdf->Ln(5);
        $startx = 15;
        $starty = 70;
        $blockwidth = 58;
        $blockheight = 45;
        foreach (array_slice($blocks, 0, 3) as $index => $block) {
            $blockx = $startx + ($index * 62);
            $pdf->write2DBarcode((string)$block['payload'], 'QRCODE,H', $blockx, $starty, 22, 22, $style, 'N');
            $pdf->SetXY($blockx + 24, $starty);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->MultiCell($blockwidth - 24, 4, (string)$block['label'], 0, 'L', false, 1);
            $pdf->SetX($blockx + 24);
            $pdf->SetFont('helvetica', '', 7);
            $pdf->MultiCell($blockwidth - 24, 4, (string)($block['signedat'] ?: 'Pending verification'), 0, 'L', false, 1);
        }
        $pdf->SetXY(15, 120);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(180, 20, "Scan any QR code or open the verification URL to check document authenticity, signer details, and integrity status.");

        $this->append_signing_evidence_page($pdf, $job, $qrpayload, $originalsha256);

        return $pdf->Output('', 'S');
    }

    /**
     * Append signer evidence page to current PDF artifact.
     *
     * @param \TCPDF $pdf
     * @param \stdClass $job
     * @param string $verifyurl
     * @param string $originalsha256
     * @return void
     */
    private function append_signing_evidence_page(\TCPDF $pdf, \stdClass $job, string $verifyurl, string $originalsha256): void {
        $signedcount = 0;
        $totalcount = 0;
        $signers = $this->get_signer_records((int)$job->id);
        foreach ($signers as $signer) {
            $totalcount++;
            if ($signer->status === self::SIGNER_SIGNED) {
                $signedcount++;
            }
        }
        $stage = ($job->status === self::JOB_COMPLETED_MANUAL || $job->status === self::JOB_COMPLETED_AUTO || $signedcount === $totalcount)
            ? 'Final signed artifact'
            : 'Signed PDF progress';

        $pdf->AddPage();
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Write(0, 'Signature Evidence', '', 0, 'L', true, 0, false, false, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Write(0, 'Stage: ' . $stage, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Signer progress: ' . $signedcount . '/' . max(1, $totalcount), '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Document: ' . (string)$job->documenttitle, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Document type: ' . ucfirst((string)$job->documenttype), '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Job ID: ' . (int)$job->id, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Original PDF SHA256: ' . $originalsha256, '', 0, 'L', true, 0, false, false, 0);
        if (!empty($job->drafthash)) {
            $pdf->Write(0, 'Draft artifact SHA256: ' . (string)$job->drafthash, '', 0, 'L', true, 0, false, false, 0);
        }
        if (!empty($job->finalhash)) {
            $pdf->Write(0, 'Final artifact SHA256: ' . (string)$job->finalhash, '', 0, 'L', true, 0, false, false, 0);
        }
        $pdf->Write(0, 'Verify URL: ' . $verifyurl, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Ln(3);

        foreach ($signers as $signer) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Write(
                0,
                'Signer #' . (int)$signer->signorder . ': ' . trim((string)($signer->signername ?? $signer->signeremail)),
                '',
                0,
                'L',
                true,
                0,
                false,
                false,
                0
            );
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Write(0, 'Email: ' . (string)$signer->signeremail, '', 0, 'L', true, 0, false, false, 0);
            $pdf->Write(0, 'Position: ' . (string)($signer->signerposition ?? ''), '', 0, 'L', true, 0, false, false, 0);
            $pdf->Write(0, 'Workflow status: ' . (string)$signer->status, '', 0, 'L', true, 0, false, false, 0);
            if (!empty($signer->signedat)) {
                $pdf->Write(0, 'Signed at: ' . userdate((int)$signer->signedat), '', 0, 'L', true, 0, false, false, 0);
            }
            if (!empty($signer->expectediin) || !empty($signer->signeriin)) {
                $pdf->Write(
                    0,
                    'IIN expected/verified: ' . (string)($signer->expectediin ?: '-') . ' / ' . (string)($signer->signeriin ?: '-'),
                    '',
                    0,
                    'L',
                    true,
                    0,
                    false,
                    false,
                    0
                );
            }
            if (!empty($signer->verificationstatus)) {
                $pdf->Write(0, 'Verification status: ' . (string)$signer->verificationstatus, '', 0, 'L', true, 0, false, false, 0);
            }
            if (!empty($signer->signingmethod)) {
                $pdf->Write(0, 'Signing method: ' . (string)$signer->signingmethod, '', 0, 'L', true, 0, false, false, 0);
            }

            $signmeta = json_decode((string)($signer->signmeta ?? ''), true);
            if (is_array($signmeta)) {
                if (!empty($signmeta['payload_sha256'])) {
                    $pdf->Write(0, 'Payload SHA256: ' . (string)$signmeta['payload_sha256'], '', 0, 'L', true, 0, false, false, 0);
                }
                if (!empty($signmeta['cms_sha256'])) {
                    $pdf->Write(0, 'CMS SHA256: ' . (string)$signmeta['cms_sha256'], '', 0, 'L', true, 0, false, false, 0);
                }
            }

            $verification = json_decode((string)($signer->verificationinfo ?? ''), true);
            if (is_array($verification)) {
                if (!empty($verification['verifyinfo'])) {
                    $pdf->Write(0, 'Verifier message: ' . (string)$verification['verifyinfo'], '', 0, 'L', true, 0, false, false, 0);
                }
                if (!empty($verification['signingtime'])) {
                    $pdf->Write(0, 'Verifier signing time: ' . (string)$verification['signingtime'], '', 0, 'L', true, 0, false, false, 0);
                }
                if (!empty($verification['certificateinfo']['subject']['dn'])) {
                    $pdf->Write(
                        0,
                        'Certificate subject: ' . (string)$verification['certificateinfo']['subject']['dn'],
                        '',
                        0,
                        'L',
                        true,
                        0,
                        false,
                        false,
                        0
                    );
                }
            }
            $pdf->Ln(2);
        }
    }

    /**
     * Load plugin-local composer autoload if present.
     *
     * @return void
     */
    private function maybe_load_plugin_vendor_autoload(): void {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_readable($autoload)) {
            require_once($autoload);
        }

        $loaded = true;
    }

    /**
     * Generate a random public document UUID.
     *
     * @return string
     */
    private function generate_document_uuid(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        $hex = bin2hex($data);
        return substr($hex, 0, 8) . '-' .
            substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' .
            substr($hex, 20, 12);
    }

    /**
     * Normalise document type into supported public values.
     *
     * @param string $type
     * @return string
     */
    private function normalise_document_type(string $type): string {
        $type = \core_text::strtolower(trim($type));
        if ($type === '') {
            return 'certificate';
        }

        if (preg_match('/protocol|protocols|protokol/u', $type)) {
            return 'protocol';
        }
        if (preg_match('/credential|licen[cs]e|card|udostov/u', $type)) {
            return 'credential';
        }
        if (preg_match('/certificate|cert|sertif/u', $type)) {
            return 'certificate';
        }

        return in_array($type, ['certificate', 'protocol', 'credential'], true) ? $type : 'certificate';
    }

    /**
     * Infer document type from one or more human-readable labels.
     *
     * @param string ...$texts
     * @return string
     */
    private function infer_document_type(string ...$texts): string {
        $haystack = \core_text::strtolower(trim(implode(' ', $texts)));
        if ($haystack === '') {
            return 'certificate';
        }

        if (preg_match('/protocol|protocols|protokol/u', $haystack)) {
            return 'protocol';
        }
        if (preg_match('/credential|licen[cs]e|card|udostov/u', $haystack)) {
            return 'credential';
        }
        if (preg_match('/certificate|cert|sertif/u', $haystack)) {
            return 'certificate';
        }

        return 'certificate';
    }

    /**
     * Build a safe display name without calling fullname() on partial records.
     *
     * @param \stdClass $user
     * @return string
     */
    private function format_person_name(\stdClass $user): string {
        $parts = [];
        foreach (['firstname', 'middlename', 'lastname'] as $field) {
            if (!empty($user->{$field})) {
                $parts[] = trim((string)$user->{$field});
            }
        }

        if (!$parts && !empty($user->alternatename)) {
            $parts[] = trim((string)$user->alternatename);
        }

        return $parts ? implode(' ', $parts) : 'User';
    }

    /**
     * Generate token unique in local_ncasign_signers table.
     *
     * @return string
     */
    private function generate_unique_token(): string {
        global $DB;

        do {
            $token = bin2hex(random_bytes(16));
        } while ($DB->record_exists('local_ncasign_signers', ['token' => $token]));

        return $token;
    }
}


