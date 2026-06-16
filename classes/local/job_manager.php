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
    /** @var string */
    public const JOB_ORIGIN_COURSE_COMPLETION = 'course_completion';
    /** @var string */
    public const JOB_ORIGIN_DEMO = 'demo_job';
    /** @var string */
    public const JOB_ORIGIN_CUSTOMCERT_ISSUE = 'customcert_issue';

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
        ?int $templateprofileid = null,
        ?string $documentuuid = null,
        string $origin = self::JOB_ORIGIN_COURSE_COMPLETION
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
            'origin' => $this->normalise_job_origin($origin),
            'documentuuid' => $documentuuid ?: $this->generate_document_uuid(),
            'documenttype' => $this->normalise_document_type($documenttype),
            'documenttitle' => trim($documenttitle) !== '' ? trim($documenttitle) : null,
            'drafthash' => null,
            'finalhash' => null,
            'finalizerbackend' => null,
            'finalizationmanifest' => null,
            'finalizationevidence' => null,
            'certificateurl' => $certificateurl,
            'status' => self::JOB_PENDING,
            'manualdeadline' => $now + ($manualwindowhours * HOURSECS),
            'manualcompleted' => null,
            'autosigned' => null,
            'autosignnote' => null,
        ];
        $jobid = $DB->insert_record('local_ncasign_jobs', $job);
        error_log(
            'local_ncasign: create_job inserted job ' . $jobid .
            ', userid=' . $userid .
            ', courseid=' . $courseid .
            ', templateprofileid=' . ($templateprofileid === null ? 'null' : (string)$templateprofileid) .
            ', origin=' . $job->origin .
            ', manualdeadline=' . $job->manualdeadline .
            ', input_signer_count=' . count($signers) .
            ', sendnotifications=' . ($sendnotifications ? '1' : '0')
        );

        $signorder = 1;
        $insertedsigners = 0;
        foreach ($this->order_signers_for_commission_workflow($signers) as $signer) {
            if (empty($signer['email'])) {
                error_log('local_ncasign: create_job skipped signer without email for job ' . $jobid);
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
            $insertedsigners++;
            error_log(
                'local_ncasign: create_job inserted signer order=' . $signorder .
                ' for job ' . $jobid .
                ', email=' . trim($signer['email']) .
                ', name=' . trim((string)($signer['name'] ?? $signer['email']))
            );
            $signorder++;
        }
        error_log('local_ncasign: create_job inserted ' . $insertedsigners . ' signer row(s) for job ' . $jobid);

        if ($sendnotifications) {
            $this->notify_signers_for_job($jobid);
        }

        return (int)$jobid;
    }

    /**
     * Return signers in the required email/signing workflow sequence.
     *
     * Template profiles keep the historical role order used by document rendering:
     * chair, member 1, member 2. The signing workflow must email member 1, then
     * member 2, then chair.
     *
     * @param array<int,array<string,mixed>> $signers
     * @return array<int,array<string,mixed>>
     */
    private function order_signers_for_commission_workflow(array $signers): array {
        $signers = array_values($signers);
        if (count($signers) < 3) {
            return $signers;
        }

        return array_merge(
            [$signers[1], $signers[2], $signers[0]],
            array_slice($signers, 3)
        );
    }

    /**
     * Create a document UUID before draft generation.
     *
     * @return string
     */
    public function create_document_uuid(): string {
        return $this->generate_document_uuid();
    }

    /**
     * Build public verification URL for a known document UUID.
     *
     * @param string $documentuuid
     * @return string
     */
    public function build_verification_url_for_document_uuid(string $documentuuid): string {
        global $CFG;

        $documentuuid = trim($documentuuid);
        if ($documentuuid === '') {
            return $CFG->wwwroot;
        }

        $checksum = $this->get_verification_checksum($documentuuid);
        $url = new \moodle_url('/local/ncasign/verify.php', [
            'id' => $documentuuid,
            'hash' => $checksum,
        ]);
        return $url->out(false);
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
        $job->finalizationevidence = null;
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
        if ($isfinal) {
            $job->finalhash = !empty($result['finalhash']) ? (string)$result['finalhash'] : null;
        }
        $job->finalizationevidence = !empty($result['evidence']) && is_array($result['evidence'])
            ? json_encode($result['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        $job->timemodified = time();
        $DB->update_record('local_ncasign_jobs', $job);

        try {
            $verification = $finalizer->verify_pdf($signedcontent, $filename, true);
            if (!empty($verification['signatures']) && is_array($verification['signatures'])) {
                $this->apply_sidecar_signer_evidence($jobid, $verification['signatures']);
            }
        } catch (\Throwable $e) {
            error_log('local_ncasign: failed to persist Kalkan verification evidence for signed PDF artifact: ' . $e->getMessage());
        }

        return $filename;
    }

    /**
     * Persist Kalkan-backed signer evidence returned by the Java sidecar.
     *
     * @param int $jobid
     * @param array<int, array<string,mixed>> $items
     * @return void
     */
    private function apply_sidecar_signer_evidence(int $jobid, array $items): void {
        global $DB;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $signer = null;
            if (!empty($item['signerRecordId'])) {
                $signer = $DB->get_record('local_ncasign_signers', [
                    'id' => (int)$item['signerRecordId'],
                    'jobid' => $jobid,
                ], '*', IGNORE_MISSING);
            }
            if (!$signer && !empty($item['order'])) {
                $signer = $DB->get_record('local_ncasign_signers', [
                    'jobid' => $jobid,
                    'signorder' => (int)$item['order'],
                ], '*', IGNORE_MISSING);
            }
            if (!$signer && !empty($item['index'])) {
                $signer = $DB->get_record('local_ncasign_signers', [
                    'jobid' => $jobid,
                    'signorder' => (int)$item['index'],
                ], '*', IGNORE_MISSING);
            }
            if (!$signer) {
                continue;
            }

            $verification = [];
            if (!empty($signer->verificationinfo) && is_string($signer->verificationinfo)) {
                $decoded = json_decode($signer->verificationinfo, true);
                if (is_array($decoded)) {
                    $verification = $decoded;
                }
            }
            $validation = !empty($verification['validation']) && is_array($verification['validation'])
                ? $verification['validation']
                : [];
            $signmeta = [];
            if (!empty($signer->signmeta) && is_string($signer->signmeta)) {
                $decoded = json_decode($signer->signmeta, true);
                if (is_array($decoded)) {
                    $signmeta = $decoded;
                }
            }

            $tsa = [
                'present' => !empty($item['timestampPresent']),
                'tokenCount' => (int)($item['timestampTokenCount'] ?? 0),
                'genTime' => !empty($item['timestampGenTime']) ? (string)$item['timestampGenTime'] : null,
                'authority' => !empty($item['timestampAuthority']) ? (string)$item['timestampAuthority'] : null,
                'tokenSha256' => !empty($item['timestampTokenSha256']) && is_array($item['timestampTokenSha256'])
                    ? array_values($item['timestampTokenSha256'])
                    : [],
                'verifiedBy' => 'Kalkan',
            ];
            if (!empty($item['timestampGenTimes']) && is_array($item['timestampGenTimes'])) {
                $tsa['genTimes'] = array_values($item['timestampGenTimes']);
            }
            if (!empty($item['timestampAuthorities']) && is_array($item['timestampAuthorities'])) {
                $tsa['authorities'] = array_values($item['timestampAuthorities']);
            }
            if (!empty($item['timestampErrors']) && is_array($item['timestampErrors'])) {
                $tsa['errors'] = array_values($item['timestampErrors']);
            }

            $ocsp = [
                'count' => (int)($item['ocspCount'] ?? 0),
                'urls' => !empty($item['ocspUrls']) && is_array($item['ocspUrls']) ? array_values($item['ocspUrls']) : [],
                'details' => !empty($item['ocspDetails']) && is_array($item['ocspDetails']) ? array_values($item['ocspDetails']) : [],
                'responseSha256' => !empty($item['ocspResponseSha256']) && is_array($item['ocspResponseSha256'])
                    ? array_values($item['ocspResponseSha256'])
                    : [],
                'verifiedBy' => 'Kalkan',
            ];
            if (!empty($item['ocspErrors']) && is_array($item['ocspErrors'])) {
                $ocsp['errors'] = array_values($item['ocspErrors']);
            }

            $verification['verifyinfo'] = 'Embedded PDF signature verified by Kalkan after PDF finalization.';
            $verification['proofsource'] = 'java_sidecar_kalkan';
            $verification['tsa'] = $tsa;
            $verification['ocsp'] = $ocsp;
            if (array_key_exists('valid', $item)) {
                $verification['valid'] = !empty($item['valid']);
            }
            if (!empty($item['error'])) {
                $verification['prooferror'] = (string)$item['error'];
            }
            if (!empty($item['timestampGenTime'])) {
                $verification['signingtime'] = (string)$item['timestampGenTime'];
            }
            $validation['embeddedpdfverified'] = !empty($item['valid']);
            $validation['proofsource'] = 'java_sidecar_kalkan';
            $validation['tsapresent'] = !empty($item['timestampPresent']);
            $validation['timestampverified'] = !empty($item['timestampPresent']);
            $validation['ocsppresent'] = !empty($item['ocspCount']);
            $verification['validation'] = $validation;

            $signmeta['finalization_proof'] = [
                'provider' => 'Kalkan',
                'timestamp' => $tsa,
                'ocsp' => [
                    'count' => $ocsp['count'],
                    'responseSha256' => $ocsp['responseSha256'],
                    'urls' => $ocsp['urls'],
                ],
                'cms_sha256' => !empty($item['cmsSha256']) ? (string)$item['cmsSha256'] : null,
            ];

            $certificateiin = preg_replace('/\D+/', '', (string)($item['certificateIin'] ?? ''));
            if (!empty($item['certificateSubjectDn']) || !empty($item['certificateSerialNumber'])) {
                $certificate = [
                    'subject' => ['dn' => (string)($item['certificateSubjectDn'] ?? '')],
                    'issuer' => ['dn' => (string)($item['certificateIssuerDn'] ?? '')],
                    'serialNumber' => (string)($item['certificateSerialNumber'] ?? ''),
                    'notBefore' => (string)($item['certificateNotBefore'] ?? ''),
                    'notAfter' => (string)($item['certificateNotAfter'] ?? ''),
                ];
                if ($certificateiin !== '') {
                    $certificate['subject']['iin'] = $certificateiin;
                }
                $signer->signercertificate = json_encode($certificate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($certificateiin !== '') {
                $signer->signeriin = $certificateiin;
                $verification['signeriin'] = $certificateiin;
            }

            $signer->verificationstatus = !empty($item['valid']) ? 'verified' : (string)($signer->verificationstatus ?? 'pades_deferred');
            $signer->verificationinfo = json_encode($verification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $signer->signmeta = json_encode($signmeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $signer->ocspresponse = $ocsp['count'] > 0
                ? json_encode($ocsp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
            $signer->timemodified = time();
            $DB->update_record('local_ncasign_signers', $signer);
        }
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
     * Return decoded job finalization evidence.
     *
     * @param \stdClass $job
     * @return array<string,mixed>
     */
    public function get_job_finalization_evidence(\stdClass $job): array {
        if (empty($job->finalizationevidence) || !is_string($job->finalizationevidence)) {
            return [];
        }
        $decoded = json_decode($job->finalizationevidence, true);
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
            error_log('local_ncasign: notify_signers_for_job could not find job ' . $jobid);
            return;
        }

        $signer = $this->get_active_pending_signer($jobid);
        if (!$signer || !empty($signer->notifiedat)) {
            if (!$signer) {
                $pendingcount = $DB->count_records('local_ncasign_signers', ['jobid' => $jobid, 'status' => self::SIGNER_PENDING]);
                error_log(
                    'local_ncasign: notify_signers_for_job found no active pending signer for job ' . $jobid .
                    ', pending_count=' . $pendingcount
                );
            } else {
                error_log(
                    'local_ncasign: notify_signers_for_job active signer already notified for job ' . $jobid .
                    ', signer_id=' . (int)$signer->id .
                    ', email=' . (string)$signer->signeremail .
                    ', notifiedat=' . (string)$signer->notifiedat
                );
            }
            return;
        }

        error_log(
            'local_ncasign: notify_signers_for_job sending email for job ' . $jobid .
            ', signer_id=' . (int)$signer->id .
            ', order=' . (int)$signer->signorder .
            ', email=' . (string)$signer->signeremail
        );
        $this->send_signer_email($signer, $job, (string)($signer->signername ?? $signer->signeremail));
        $signer->notifiedat = time();
        $signer->timemodified = time();
        $DB->update_record('local_ncasign_signers', $signer);
        error_log(
            'local_ncasign: notify_signers_for_job marked signer notified for job ' . $jobid .
            ', signer_id=' . (int)$signer->id .
            ', notifiedat=' . (string)$signer->notifiedat
        );
    }

    /**
     * Mark signer as manually signed.
     *
     * @param string $token
     * @param string $signedby
     * @param array $meta
     * @return bool
     */
    public function mark_signer_signed(
        string $token,
        string $signedby = 'manual',
        array $meta = [],
        array $evidence = [],
        bool $notifynextsigner = true,
        bool $autocompletion = false
    ): bool {
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
            if ($notifynextsigner) {
                $this->notify_signers_for_job((int)$signer->jobid);
            }
            return true;
        }

        $this->complete_job_if_fully_signed((int)$signer->jobid, $autocompletion);
        return true;
    }

    /**
     * Complete job manually if all signers have signed.
     *
     * @param int $jobid
     * @return void
     */
    public function complete_job_if_fully_signed(int $jobid, bool $auto = false): void {
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
                $job->finalizationevidence = json_encode([
                    'status' => 'failed',
                    'reason' => 'Final PDF was not generated after all signers completed.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $DB->update_record('local_ncasign_jobs', $job);
                error_log('local_ncasign: final signed PDF was not generated for job ' . $jobid);
                return;
            }
        } catch (\Throwable $e) {
            error_log('local_ncasign: failed to generate signed PDF artifact: ' . $e->getMessage());
            $job->status = self::JOB_FINALIZE_FAILED;
            $job->timemodified = time();
            $job->autosignnote = 'Final PDF generation failed: ' . $e->getMessage();
            $job->finalizationevidence = json_encode([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $DB->update_record('local_ncasign_jobs', $job);
            return;
        }

        // Re-read the job after finalization because generate_signed_pdf_artifact()
        // persists finalhash/finalizationevidence and the pre-finalization object is stale.
        $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', MUST_EXIST);
        if ($auto) {
            $job->status = self::JOB_COMPLETED_AUTO;
            $job->autosigned = time();
            $job->manualcompleted = null;
            $job->autosignnote = (string)get_config('local_ncasign', 'autosignnote');
        } else {
            $job->status = self::JOB_COMPLETED_MANUAL;
            $job->manualcompleted = time();
            $job->autosigned = null;
            $job->autosignnote = null;
        }
        $job->timemodified = time();
        $DB->update_record('local_ncasign_jobs', $job);

        $this->send_student_completion_email($job, $auto);
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
            if ($this->can_server_autosign()) {
                if ($this->try_server_autosign_job((int)$job->id)) {
                    $count++;
                }
                continue;
            }

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
     * Whether a configured server-held PKCS#12 signer is available.
     *
     * @return bool
     */
    public function can_server_autosign(): bool {
        $config = $this->get_server_autosign_config();
        if (!$config['enabled']) {
            return false;
        }
        if ($config['pkcs12path'] === '') {
            return false;
        }
        if (!is_readable($config['pkcs12path'])) {
            return false;
        }
        return trim((string)get_config('local_ncasign', 'padesfinalizerbackend')) === 'java_sidecar';
    }

    /**
     * Attempt to server-sign all currently pending signers for a job.
     *
     * @param int $jobid
     * @return bool
     */
    public function try_server_autosign_job(int $jobid): bool {
        global $DB;

        if (!$this->can_server_autosign()) {
            return false;
        }

        $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', IGNORE_MISSING);
        if (!$job || $job->status !== self::JOB_PENDING) {
            return false;
        }

        try {
            $this->ensure_original_pdf_for_job($jobid);
            $config = $this->get_server_autosign_config();
            while ($signer = $this->get_active_pending_signer($jobid)) {
                $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', MUST_EXIST);
                $this->server_sign_active_signer($job, $signer, $config);
            }
            $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', MUST_EXIST);
            return in_array((string)$job->status, [self::JOB_COMPLETED_AUTO, self::JOB_COMPLETED_MANUAL], true);
        } catch (\Throwable $e) {
            error_log('local_ncasign: server auto-sign failed for job ' . $jobid . ': ' . $e->getMessage());
            $this->record_finalization_note($jobid, 'Server auto-sign failed: ' . $e->getMessage());
            return false;
        }
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
     * Return server auto-signer configuration.
     *
     * @return array<string,mixed>
     */
    private function get_server_autosign_config(): array {
        return [
            'enabled' => (bool)((int)get_config('local_ncasign', 'serversigningenabled')),
            'pkcs12path' => trim((string)get_config('local_ncasign', 'serversigningpkcs12path')),
            'pkcs12password' => (string)get_config('local_ncasign', 'serversigningpkcs12password'),
            'pkcs12alias' => trim((string)get_config('local_ncasign', 'serversigningpkcs12alias')),
        ];
    }

    /**
     * Server-sign the current active signer using the Java sidecar and configured PKCS#12 container.
     *
     * @param \stdClass $job
     * @param \stdClass $signer
     * @param array<string,mixed> $config
     * @return void
     */
    private function server_sign_active_signer(\stdClass $job, \stdClass $signer, array $config): void {
        $signingpayload = $this->get_job_signing_payload_binary((int)$job->id);
        if (!$signingpayload) {
            throw new \RuntimeException('No document payload is available for server-side signing.');
        }

        $finalizer = pades_finalizer_factory::create();
        if (!($finalizer instanceof java_sidecar_pades_finalizer) || !$finalizer->supports_prepare_phase()) {
            throw new \RuntimeException('Server-side auto-signing requires the Java sidecar PAdES finalizer backend.');
        }

        $prepared = $finalizer->prepare([
            'job' => $job,
            'originalpdf' => $signingpayload['content'],
            'originalfilename' => $signingpayload['filename'],
            'originalsha256' => $signingpayload['sha256'],
            'manifest' => $this->get_job_finalization_manifest($job),
            'signer' => $signer,
            'signers' => $this->get_signer_records((int)$job->id),
        ]);

        $payloadmode = (string)($prepared['payloadmode'] ?? 'prepared_pdf_dtbs');
        $payloadb64 = (string)($prepared['signablepayloadb64'] ?? '');
        $this->store_pending_prepare_state((int)$signer->id, [
            'sessionid' => (string)($prepared['sessionid'] ?? ''),
            'fieldname' => (string)($prepared['fieldname'] ?? ''),
            'payloadmode' => $payloadmode,
            'signablepayloadb64' => $payloadb64,
            'signablepayloadsha256' => (string)($prepared['signablepayloadsha256'] ?? ''),
            'signingtime' => (string)($prepared['signingtime'] ?? ''),
            'backend' => (string)($prepared['backend'] ?? ''),
            'documentsha256' => (string)$signingpayload['sha256'],
            'documentfilename' => (string)$signingpayload['filename'],
        ]);

        $serverresult = $finalizer->server_sign_prepared_payload([
            'sessionid' => (string)($prepared['sessionid'] ?? ''),
            'fieldname' => (string)($prepared['fieldname'] ?? ''),
        ], $config);

        $cmsbase64 = trim((string)($serverresult['cmsbase64'] ?? ''));
        if ($cmsbase64 === '') {
            throw new \RuntimeException('Server-side signing did not return a CMS payload.');
        }

        $certificateinfo = is_array($serverresult['certificateinfo'] ?? null) ? $serverresult['certificateinfo'] : [];
        $signeriin = preg_replace('/\D+/', '', (string)($certificateinfo['iin'] ?? ''));
        $verification = [
            'cms_base64' => $cmsbase64,
            'certificate' => !empty($certificateinfo)
                ? json_encode($certificateinfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'signeriin' => $signeriin !== '' ? $signeriin : null,
            'signingtime' => (string)($serverresult['signingtime'] ?? ($prepared['signingtime'] ?? '')),
            'verifyinfo' => 'Server-side PKCS#12 CMS accepted. Certificate/content validation is deferred to PDF finalization because DSS-prepared PAdES payload validation happens after embedding.',
            'certificateinfo' => $certificateinfo,
            'validation' => [
                'contentcheckskipped' => true,
                'deferred_to_pades_finalizer' => true,
                'server_signing' => true,
                'backend' => 'java_sidecar_pkcs12',
            ],
        ];

        $signaturefilename = $this->store_signer_cms_signature((int)$job->id, (int)$signer->id, $cmsbase64);
        $meta = [
            'mode' => 'server_pkcs12_real_cms_detached_verified',
            'signer_order' => (int)$signer->signorder,
            'signer_name' => (string)($signer->signername ?? $signer->signeremail),
            'signer_position' => (string)($signer->signerposition ?? ''),
            'expected_iin' => preg_replace('/\D+/', '', (string)($signer->expectediin ?? '')),
            'verified_signer_iin' => $signeriin !== '' ? $signeriin : null,
            'payload_mode' => $payloadmode,
            'storage' => 'SERVER_PKCS12',
            'module' => 'java_sidecar_server_sign',
            'ip' => null,
            'nca_response_code' => '200',
            'nca_message' => 'Server-side signing completed.',
            'payload_sha256' => (string)($prepared['signablepayloadsha256'] ?? ''),
            'payload_meta' => [
                'filename' => (string)$signingpayload['filename'],
                'sha256' => (string)$signingpayload['sha256'],
                'filesize' => (int)($signingpayload['filesize'] ?? 0),
                'sourcearea' => (string)($signingpayload['sourcearea'] ?? ''),
                'prepare' => [
                    'sessionid' => (string)($prepared['sessionid'] ?? ''),
                    'fieldname' => (string)($prepared['fieldname'] ?? ''),
                    'payloadsha256' => (string)($prepared['signablepayloadsha256'] ?? ''),
                    'signingtime' => (string)($prepared['signingtime'] ?? ''),
                    'backend' => (string)($prepared['backend'] ?? ''),
                ],
                'server_sign' => [
                    'fieldname' => (string)($serverresult['fieldname'] ?? ''),
                    'signingtime' => (string)($serverresult['signingtime'] ?? ''),
                    'evidence' => $serverresult['evidence'] ?? [],
                ],
            ],
            'cms_sha256' => (string)($serverresult['cmssha256'] ?? hash('sha256', $cmsbase64)),
            'cms_length' => \core_text::strlen($cmsbase64),
            'cms_preview' => \core_text::substr($cmsbase64, 0, 120),
            'signature_filename' => $signaturefilename,
            'verification_info' => (string)$verification['verifyinfo'],
            'certificate_info' => $verification['certificateinfo'],
            'certificate_validation' => $verification['validation'],
            'signing_time' => $verification['signingtime'],
            'server_received_at' => time(),
        ];

        if (!$this->mark_signer_signed(
            (string)$signer->token,
            'server_pkcs12',
            $meta,
            [
                'rawcms' => $cmsbase64,
                'signercertificate' => $verification['certificate'],
                'signeriin' => $verification['signeriin'],
                'ocspresponse' => null,
                'signingmethod' => 'server_pkcs12_kalkan_detached_hash_for_pades+deferred_pdf_finalization',
                'verificationstatus' => 'pades_deferred',
                'verificationinfo' => json_encode([
                    'verifyinfo' => $verification['verifyinfo'],
                    'certificateinfo' => $verification['certificateinfo'],
                    'validation' => $verification['validation'],
                    'signingtime' => $verification['signingtime'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            false,
            true
        )) {
            throw new \RuntimeException('The signer was no longer active when server-side signing completed.');
        }
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
            error_log('local_ncasign: send_signer_email skipped empty signer email for job ' . (int)$job->id);
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
        $sent = email_to_user($to, $from, $subject, $message);
        error_log(
            'local_ncasign: send_signer_email result for job ' . (int)$job->id .
            ', signer_id=' . (int)$signer->id .
            ', email=' . (string)$signer->signeremail .
            ', result=' . var_export($sent, true)
        );
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
            $restoredelements = $this->temporarily_apply_customcert_user_full_name((int)$customcert->templateid, $targetuserid);
            $restoremiddlename = $this->temporarily_apply_user_table_middlename_from_profile($targetuserid);

            try {
                if (method_exists($template, 'generate_pdf')) {
                    $content = $template->generate_pdf(false, $targetuserid, true);
                } else if (class_exists('\mod_customcert\service\pdf_generation_service')) {
                    $pdfservice = \mod_customcert\service\pdf_generation_service::create();
                    $content = $pdfservice->generate_pdf($template, false, $targetuserid, true);
                }
            } finally {
                $this->restore_customcert_text_overrides($restoredelements);
                $this->restore_user_table_middlename($restoremiddlename);
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
     * Load mod_customcert template object directly from the template record.
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
            $templaterecord = $DB->get_record('customcert_templates', ['id' => $templateid], '*', IGNORE_MISSING);
            if (!$templaterecord) {
                error_log('local_ncasign: customcert template record not found id=' . $templateid);
                return null;
            }
            $template = new \mod_customcert\template($templaterecord);
            $generatepdffile = '-';
            if (method_exists($template, 'generate_pdf')) {
                $method = new \ReflectionMethod($template, 'generate_pdf');
                $generatepdffile = (string)$method->getFileName();
            }
            error_log(
                'NCASIGN_CANARY get_customcert_template_instance direct' .
                ' templateid=' . $templateid .
                ' class=' . get_class($template) .
                ' generate_pdf_file=' . $generatepdffile
            );
            return $template;
        } catch (\Throwable $e) {
            error_log('local_ncasign: failed to load customcert template ' . $templateid . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Temporarily set the customcert text element named user_full_name for direct issue rendering.
     *
     * @param int $templateid
     * @param int $userid
     * @return array<int,string>
     */
    private function temporarily_apply_customcert_user_full_name(int $templateid, int $userid): array {
        global $DB;

        $value = $this->resolve_customcert_user_full_name($userid);
        if ($templateid <= 0 || $value === '') {
            return [];
        }

        $manager = $DB->get_manager();
        if (!$manager->table_exists(new \xmldb_table('customcert_pages'))
            || !$manager->table_exists(new \xmldb_table('customcert_elements'))) {
            return [];
        }

        $sql = "SELECT e.id, e.name, e.data
                  FROM {customcert_elements} e
                  JOIN {customcert_pages} p ON p.id = e.pageid
                 WHERE p.templateid = :templateid
                   AND " . $DB->sql_compare_text('e.element') . " = :element";
        $elements = $DB->get_records_sql($sql, [
            'templateid' => $templateid,
            'element' => 'text',
        ]);

        $restore = [];
        $scanned = [];
        foreach ($elements as $element) {
            $rawname = (string)($element->name ?? '');
            $name = $this->normalise_customcert_element_name($rawname);
            $scanned[] = trim($rawname) . ':' . $name;
            if ($name !== 'user_full_name') {
                continue;
            }
            $restore[(int)$element->id] = (string)($element->data ?? '');
            $DB->set_field('customcert_elements', 'data', $value, ['id' => (int)$element->id]);
        }

        error_log(
            'NCASIGN_CANARY customcert_issue_user_full_name_temp_override' .
            ' templateid=' . $templateid .
            ' userid=' . $userid .
            ' matched_count=' . count($restore) .
            ' scanned=' . implode('|', $scanned) .
            ' value_length=' . \core_text::strlen($value) .
            ' value_hash=' . hash('sha256', $value)
        );

        return $restore;
    }

    /**
     * Normalise customcert element names for matching saved template names to overrides.
     *
     * @param string $name
     * @return string
     */
    private function normalise_customcert_element_name(string $name): string {
        $name = \core_text::strtolower(trim($name));
        $name = preg_replace('/[\s\-]+/u', '_', $name) ?? $name;
        return trim($name, '_');
    }

    /**
     * Temporarily put the custom profile middle name into mdl_user.middlename for Customcert rendering.
     *
     * @param int $userid
     * @return array{userid:int,original:string}|null
     */
    private function temporarily_apply_user_table_middlename_from_profile(int $userid): ?array {
        global $DB;

        if ($userid <= 0) {
            return null;
        }

        $current = $DB->get_field('user', 'middlename', ['id' => $userid], IGNORE_MISSING);
        if ($current === false) {
            return null;
        }

        $profilemiddlename = $this->resolve_custom_profile_middlename($userid);
        $DB->set_field('user', 'middlename', $profilemiddlename, ['id' => $userid]);

        error_log(
            'NCASIGN_CANARY user_table_middlename_temp_override' .
            ' userid=' . $userid .
            ' original_present=' . (trim((string)$current) !== '' ? '1' : '0') .
            ' profile_present=' . ($profilemiddlename !== '' ? '1' : '0') .
            ' profile_length=' . \core_text::strlen($profilemiddlename) .
            ' profile_hash=' . ($profilemiddlename !== '' ? hash('sha256', $profilemiddlename) : '-')
        );

        return [
            'userid' => $userid,
            'original' => (string)$current,
        ];
    }

    /**
     * Restore mdl_user.middlename after temporary PDF rendering override.
     *
     * @param array{userid:int,original:string}|null $restore
     * @return void
     */
    private function restore_user_table_middlename(?array $restore): void {
        global $DB;

        if (empty($restore['userid'])) {
            return;
        }

        $DB->set_field('user', 'middlename', (string)($restore['original'] ?? ''), ['id' => (int)$restore['userid']]);
        error_log('NCASIGN_CANARY user_table_middlename_temp_override_restored userid=' . (int)$restore['userid']);
    }

    /**
     * Restore temporarily changed customcert text element values.
     *
     * @param array<int,string> $restoredelements
     * @return void
     */
    private function restore_customcert_text_overrides(array $restoredelements): void {
        global $DB;

        foreach ($restoredelements as $elementid => $data) {
            $DB->set_field('customcert_elements', 'data', (string)$data, ['id' => (int)$elementid]);
        }
    }

    /**
     * Resolve the certificate owner full name using custom profile field shortname middlename.
     *
     * @param int $userid
     * @return string
     */
    private function resolve_customcert_user_full_name(int $userid): string {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,middlename', IGNORE_MISSING);
        if (!$user) {
            return '';
        }

        $middlename = $this->resolve_custom_profile_middlename($userid);

        $parts = [];
        foreach (['lastname', 'firstname'] as $field) {
            $value = trim((string)($user->{$field} ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        if ($middlename !== '') {
            $parts[] = $middlename;
        }

        return trim((string)preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * Resolve the custom profile field shortname middlename.
     *
     * @param int $userid
     * @return string
     */
    private function resolve_custom_profile_middlename(int $userid): string {
        global $DB;

        $sql = "SELECT d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE d.userid = :userid
                   AND " . $DB->sql_compare_text('f.shortname') . " = :shortname";
        $record = $DB->get_record_sql($sql, ['userid' => $userid, 'shortname' => 'middlename'], IGNORE_MISSING);

        return $record ? trim((string)($record->data ?? '')) : '';
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
     * Normalise stored job origin.
     *
     * @param string $origin
     * @return string
     */
    private function normalise_job_origin(string $origin): string {
        $origin = strtolower(trim($origin));
        $allowed = [
            self::JOB_ORIGIN_COURSE_COMPLETION,
            self::JOB_ORIGIN_DEMO,
            self::JOB_ORIGIN_CUSTOMCERT_ISSUE,
        ];

        return in_array($origin, $allowed, true) ? $origin : self::JOB_ORIGIN_COURSE_COMPLETION;
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
