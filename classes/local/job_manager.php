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
    public const JOB_PENDING = 'pending_manual';
    /** @var string */
    public const JOB_COMPLETED_MANUAL = 'completed_manual';
    /** @var string */
    public const JOB_COMPLETED_AUTO = 'completed_auto';

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
        ?int $manualwindowhours = null
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
            'certificateurl' => $certificateurl,
            'status' => self::JOB_PENDING,
            'manualdeadline' => $now + ($manualwindowhours * HOURSECS),
            'manualcompleted' => null,
            'autosigned' => null,
            'autosignnote' => null,
        ];
        $jobid = $DB->insert_record('local_ncasign_jobs', $job);

        foreach ($signers as $signer) {
            if (empty($signer['email'])) {
                continue;
            }
            $token = $this->generate_unique_token();
            $record = (object)[
                'jobid' => $jobid,
                'signerid' => $signer['id'] ?? null,
                'signeremail' => trim($signer['email']),
                'token' => $token,
                'status' => self::SIGNER_PENDING,
                'timecreated' => $now,
                'timemodified' => $now,
                'signedat' => null,
                'signedby' => null,
                'signmeta' => null,
            ];
            $DB->insert_record('local_ncasign_signers', $record);

            $this->send_signer_email($record, $job, $signer['name'] ?? '');
        }

        return (int)$jobid;
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
     * Mark signer as manually signed.
     *
     * @param string $token
     * @param string $signedby
     * @param array $meta
     * @return bool
     */
    public function mark_signer_signed(string $token, string $signedby = 'manual', array $meta = []): bool {
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

        $now = time();
        $signer->status = self::SIGNER_SIGNED;
        $signer->signedat = $now;
        $signer->signedby = $signedby;
        $signer->signmeta = json_encode($meta);
        $signer->timemodified = $now;
        $DB->update_record('local_ncasign_signers', $signer);

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

        $job->status = self::JOB_COMPLETED_MANUAL;
        $job->manualcompleted = time();
        $job->timemodified = time();
        $DB->update_record('local_ncasign_jobs', $job);
        $this->send_student_completion_email($job, false);
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
     * Generate signers from role ids in course context.
     *
     * @param \context_course $context
     * @return array
     */
    public function get_signers_from_configured_roles(\context_course $context): array {
        $raw = trim((string)get_config('local_ncasign', 'notifyroleids'));
        if ($raw === '') {
            return [];
        }

        $roleids = array_filter(array_map('intval', explode(',', $raw)));
        if (!$roleids) {
            return [];
        }

        $seen = [];
        $result = [];
        foreach ($roleids as $roleid) {
            $users = get_role_users($roleid, $context, false, 'u.id,u.firstname,u.lastname,u.email');
            foreach ($users as $u) {
                if (empty($u->email) || isset($seen[$u->id])) {
                    continue;
                }
                $seen[$u->id] = true;
                $result[] = [
                    'id' => (int)$u->id,
                    'email' => (string)$u->email,
                    'name' => fullname($u),
                ];
            }
        }
        return $result;
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
        $deadline = userdate($job->manualdeadline);
        $subject = 'Signature required: course certificate';
        $message = "Hello {$name},\n\n" .
            "A certificate needs your signature.\n" .
            "Student user ID: {$job->userid}\n" .
            "Course ID: {$job->courseid}\n" .
            "Certificate URL: {$job->certificateurl}\n\n" .
            "Sign link: {$link}\n" .
            "Deadline: {$deadline}\n\n" .
            "If no manual action is taken, it will be auto-signed by server fallback (demo).";

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
        global $DB;
        $student = $DB->get_record('user', ['id' => $job->userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$student || empty($student->email)) {
            return;
        }
        $from = core_user::get_support_user();
        $mode = $auto ? 'automatic server fallback' : 'manual signer approvals';
        $subject = 'Your course certificate has been signed';
        $message = "Your certificate is now signed ({$mode}).\n\n" .
            "Course ID: {$job->courseid}\n" .
            "Certificate URL: {$job->certificateurl}\n";
        email_to_user($student, $from, $subject, $message);
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
