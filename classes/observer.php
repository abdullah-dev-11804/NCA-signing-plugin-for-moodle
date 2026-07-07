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

namespace local_ncasign;

defined('MOODLE_INTERNAL') || die();

use local_ncasign\local\job_manager;
use local_ncasign\local\document_generator;
use local_ncasign\local\document_storage;
use local_ncasign\local\template_manager;

/**
 * Plugin event observers.
 */
class observer {
    /**
     * Queue signing workflow after course completion.
     *
     * @param \core\event\course_completed $event
     * @return void
     */
    public static function course_completed(\core\event\course_completed $event): void {
        self::ensure_course_completion_debug_log();
        error_log(
            'NCASIGN_CANARY course_completed entered' .
            ' event=' . get_class($event) .
            ' courseid=' . (int)($event->courseid ?? 0) .
            ' relateduserid=' . (int)($event->relateduserid ?? 0) .
            ' userid=' . (int)($event->userid ?? 0)
        );

        if (!(int)get_config('local_ncasign', 'enabled')) {
            error_log('NCASIGN_CANARY course_completed skipped plugin_disabled');
            return;
        }

        $courseid = (int)$event->courseid;
        $userid = (int)$event->relateduserid;
        if (!$courseid || !$userid) {
            error_log(
                'NCASIGN_CANARY course_completed skipped missing_ids' .
                ' courseid=' . $courseid .
                ' userid=' . $userid
            );
            return;
        }

        if (self::consume_completion_suppression($courseid, $userid)) {
            error_log(
                'NCASIGN_CANARY course_completed skipped_suppressed' .
                ' courseid=' . $courseid .
                ' userid=' . $userid
            );
            return;
        }

        $manager = new job_manager();
        $templatemanager = new template_manager();
        error_log(
            'NCASIGN_CANARY course_completed before_profile_lookup' .
            ' courseid=' . $courseid .
            ' userid=' . $userid
        );
        $profiles = $templatemanager->get_course_template_profiles($courseid);
        if (!$profiles) {
            error_log('local_ncasign: no mapped template profiles found for course ' . $courseid . ', user ' . $userid);
            error_log(
                'NCASIGN_CANARY course_completed no_profiles' .
                ' courseid=' . $courseid .
                ' userid=' . $userid
            );
            return;
        }
        error_log(
            'local_ncasign: course completion observer found ' . count($profiles) .
            ' mapped template profile(s) for course ' . $courseid . ', user ' . $userid
        );
        $generator = new document_generator();
        ob_start();
        try {
            foreach ($profiles as $profile) {
                $signers = $profile['signers'] ?? [];
                error_log(
                    'local_ncasign: processing profile id=' . (string)($profile['id'] ?? 'none') .
                    ', name=' . (string)($profile['name'] ?? 'unknown') .
                    ', active=' . (!empty($profile['active']) ? '1' : '0') .
                    ', signer_count=' . (is_array($signers) ? count($signers) : 0) .
                    ', signer_emails=' . self::debug_signer_emails(is_array($signers) ? $signers : [])
                );
                error_log(
                    'NCASIGN_CANARY course_completed profile_start' .
                    ' courseid=' . $courseid .
                    ' userid=' . $userid .
                    ' profileid=' . (int)($profile['id'] ?? 0) .
                    ' renderer=' . (string)($profile['renderer'] ?? '') .
                    ' customcerttemplateid=' . (int)($profile['customcerttemplateid'] ?? 0) .
                    ' active=' . (!empty($profile['active']) ? '1' : '0') .
                    ' signer_count=' . (is_array($signers) ? count($signers) : 0) .
                    ' signer_emails=' . self::debug_signer_emails(is_array($signers) ? $signers : [])
                );
                if (!$signers) {
                    error_log('local_ncasign: template profile has no configured signers; skipping profile ' . (string)($profile['name'] ?? 'unknown'));
                    error_log(
                        'NCASIGN_CANARY course_completed profile_skipped_no_signers' .
                        ' profileid=' . (int)($profile['id'] ?? 0)
                    );
                    continue;
                }

                $documentuuid = $manager->create_document_uuid();
                $verifyurl = $manager->build_verification_url_for_document_uuid($documentuuid);
                try {
                    error_log(
                        'NCASIGN_CANARY course_completed before_generate' .
                        ' userid=' . $userid .
                        ' courseid=' . $courseid .
                        ' profileid=' . (int)($profile['id'] ?? 0) .
                        ' customcerttemplateid=' . (int)($profile['customcerttemplateid'] ?? 0) .
                        ' documentuuid=' . $documentuuid
                    );
                    $draft = $generator->generate_draft_from_profile($userid, $courseid, $profile, [
                        'documentuuid' => $documentuuid,
                        'verifyurl' => $verifyurl,
                        'signers' => $signers,
                    ]);
                    error_log(
                        'NCASIGN_CANARY course_completed after_generate' .
                        ' userid=' . $userid .
                        ' courseid=' . $courseid .
                        ' profileid=' . (int)($profile['id'] ?? 0) .
                        ' filename=' . (string)($draft['filename'] ?? '') .
                        ' bytes=' . strlen((string)($draft['content'] ?? '')) .
                        ' documenttype=' . (string)($draft['documenttype'] ?? '') .
                        ' documenttitle=' . (string)($draft['documenttitle'] ?? '')
                    );
                } catch (\Throwable $e) {
                    error_log(
                        'local_ncasign: failed to generate draft on course completion for profile ' .
                        (string)($profile['name'] ?? 'unknown') . ': ' . $e->getMessage()
                    );
                    error_log(
                        'NCASIGN_CANARY course_completed generate_failed' .
                        ' profileid=' . (int)($profile['id'] ?? 0) .
                        ' message=' . $e->getMessage() .
                        ' file=' . $e->getFile() .
                        ' line=' . $e->getLine()
                    );
                    continue;
                }

                error_log(
                    'NCASIGN_CANARY course_completed before_create_job' .
                    ' userid=' . $userid .
                    ' courseid=' . $courseid .
                    ' profileid=' . (int)($profile['id'] ?? 0) .
                    ' signer_count=' . count($signers)
                );
                $jobid = $manager->create_job(
                    $userid,
                    $courseid,
                    '',
                    $signers,
                    null,
                    (string)($draft['documenttype'] ?? ($profile['documenttype'] ?? 'protocol')),
                    (string)($draft['documenttitle'] ?? ($profile['documenttitle'] ?? 'Course document')),
                    false,
                    !empty($profile['id']) ? (int)$profile['id'] : null,
                    $documentuuid,
                    job_manager::JOB_ORIGIN_COURSE_COMPLETION
                );
                error_log(
                    'NCASIGN_CANARY course_completed after_create_job' .
                    ' jobid=' . $jobid .
                    ' userid=' . $userid .
                    ' courseid=' . $courseid .
                    ' profileid=' . (int)($profile['id'] ?? 0)
                );
                error_log(
                    'local_ncasign: created signing job ' . $jobid .
                    ' for course ' . $courseid .
                    ', user ' . $userid .
                    ', profile id=' . (string)($profile['id'] ?? 'none') .
                    ', signer_count=' . count($signers)
                );

                try {
                    error_log(
                        'NCASIGN_CANARY course_completed before_store_attach' .
                        ' jobid=' . $jobid .
                        ' filename=' . (string)($draft['filename'] ?? '') .
                        ' bytes=' . strlen((string)($draft['content'] ?? '')) .
                        ' has_manifest=' . (!empty($draft['finalizationmanifest']) && is_array($draft['finalizationmanifest']) ? '1' : '0')
                    );
                    $storage = new document_storage();
                    $storedpath = $storage->store_pending_draft($jobid, (string)$draft['filename'], (string)$draft['content']);
                    $manager->attach_certificate_binary_to_job(
                        $jobid,
                        (string)$draft['filename'],
                        (string)$draft['content'],
                        'local_generated_draft:' . $storedpath,
                        !empty($draft['finalizationmanifest']) && is_array($draft['finalizationmanifest'])
                            ? $draft['finalizationmanifest']
                            : null
                    );
                    error_log(
                        'local_ncasign: attached generated draft to job ' . $jobid .
                        ', filename=' . (string)$draft['filename'] .
                        ', bytes=' . strlen((string)$draft['content'])
                    );
                    error_log(
                        'NCASIGN_CANARY course_completed after_store_attach' .
                        ' jobid=' . $jobid .
                        ' storedpath=' . $storedpath .
                        ' filename=' . (string)$draft['filename'] .
                        ' bytes=' . strlen((string)$draft['content'])
                    );
                } catch (\Throwable $e) {
                    self::delete_job($jobid);
                    error_log('local_ncasign: failed to persist generated draft for job ' . $jobid . ': ' . $e->getMessage());
                    error_log(
                        'NCASIGN_CANARY course_completed store_attach_failed_deleted_job' .
                        ' jobid=' . $jobid .
                        ' message=' . $e->getMessage() .
                        ' file=' . $e->getFile() .
                        ' line=' . $e->getLine()
                    );
                    continue;
                }

                try {
                    error_log('local_ncasign: notifying active manual signer for job ' . $jobid);
                    error_log(
                        'NCASIGN_CANARY course_completed before_notify' .
                        ' jobid=' . $jobid .
                        ' userid=' . $userid .
                        ' courseid=' . $courseid .
                        ' profileid=' . (int)($profile['id'] ?? 0)
                    );
                    $manager->notify_signers_for_job($jobid);
                    error_log(
                        'NCASIGN_CANARY course_completed after_notify' .
                        ' jobid=' . $jobid
                    );
                } catch (\Throwable $e) {
                    error_log('local_ncasign: failed to notify signers for job ' . $jobid . ': ' . $e->getMessage());
                    error_log(
                        'NCASIGN_CANARY course_completed notify_failed' .
                        ' jobid=' . $jobid .
                        ' message=' . $e->getMessage() .
                        ' file=' . $e->getFile() .
                        ' line=' . $e->getLine()
                    );
                }
            }
        } finally {
            $unexpectedoutput = trim((string)ob_get_clean());
            if ($unexpectedoutput !== '') {
                error_log('local_ncasign: unexpected output during course completion observer: ' . trim(strip_tags($unexpectedoutput)));
                error_log(
                    'NCASIGN_CANARY course_completed unexpected_output' .
                    ' text=' . trim(strip_tags($unexpectedoutput))
                );
            }
        }
    }

    /**
     * Queue signing job when customcert creates an issue.
     *
     * @param \mod_customcert\event\issue_created $event
     * @return void
     */
    public static function issue_created($event): void {
        self::handle_customcert_issue_event($event);
    }

    /**
     * Backward-compatible alias for older/forked customcert versions.
     *
     * @param \mod_customcert\event\certificate_issued $event
     * @return void
     */
    public static function certificate_issued($event): void {
        self::handle_customcert_issue_event($event);
    }

    /**
     * Shared handler for customcert issue events.
     *
     * @param \core\event\base $event
     * @return void
     */
    private static function handle_customcert_issue_event(\core\event\base $event): void {
        if (!(int)get_config('local_ncasign', 'enabled')) {
            return;
        }

        $courseid = self::extract_courseid($event);
        $userid = (int)($event->relateduserid ?? $event->userid ?? 0);
        if (!$courseid || !$userid) {
            error_log('local_ncasign: customcert event missing course/user ids. Event=' . get_class($event));
            return;
        }

        $manager = new job_manager();
        $coursecontext = \context_course::instance($courseid);
        $signers = $manager->get_signers_from_configured_roles($coursecontext);

        $cmid = (int)($event->contextinstanceid ?? 0);
        if ($cmid > 0) {
            $certurl = '/mod/customcert/view.php?id=' . $cmid;
        } else {
            $certurl = $manager->build_certificate_url($courseid, $userid);
        }

        $issueid = (int)($event->objectid ?? 0);
        $documenttitle = self::resolve_customcert_document_title($issueid, $cmid, $courseid);
        $jobid = $manager->create_job(
            $userid,
            $courseid,
            $certurl,
            $signers,
            null,
            'certificate',
            $documenttitle,
            false,
            null,
            null,
            job_manager::JOB_ORIGIN_CUSTOMCERT_ISSUE
        );
        $attached = false;

        $generated = self::generate_customcert_pdf_from_issue($issueid, $userid);
        if ($generated) {
            $manager->attach_certificate_binary_to_job(
                $jobid,
                $generated['filename'],
                $generated['content'],
                'mod_customcert_generated'
            );
            $attached = true;
        }

        if (!$attached) {
            $contextid = (int)$event->contextid;
            $storedfile = self::find_customcert_pdf_file($contextid, $issueid, $userid);
            if ($storedfile) {
                $manager->attach_certificate_binary_to_job(
                    $jobid,
                    $storedfile->get_filename(),
                    $storedfile->get_content(),
                    'mod_customcert_filearea'
                );
                $attached = true;
            }
        }

        if (!$attached) {
            error_log('local_ncasign: no PDF attached for job ' . $jobid . ' from event ' . get_class($event));
        }

        try {
            $manager->notify_signers_for_job($jobid);
        } catch (\Throwable $e) {
            error_log('local_ncasign: failed to continue job ' . $jobid . ' after customcert issue event: ' . $e->getMessage());
        }
    }

    /**
     * Extract course id across customcert event variants.
     *
     * @param \core\event\base $event
     * @return int
     */
    private static function extract_courseid(\core\event\base $event): int {
        $courseid = (int)($event->courseid ?? 0);
        if ($courseid > 0) {
            return $courseid;
        }

        $cmid = (int)($event->contextinstanceid ?? 0);
        if ($cmid > 0) {
            $cm = get_coursemodule_from_id('customcert', $cmid, 0, false, 'id,course', IGNORE_MISSING);
            if ($cm && !empty($cm->course)) {
                return (int)$cm->course;
            }
        }

        return 0;
    }

    /**
     * Resolve human-readable document title from customcert issue/module.
     *
     * @param int $issueid
     * @param int $cmid
     * @param int $courseid
     * @return string
     */
    private static function resolve_customcert_document_title(int $issueid, int $cmid, int $courseid): string {
        global $DB;

        if ($issueid > 0) {
            $sql = "SELECT c.name
                      FROM {customcert_issues} ci
                      JOIN {customcert} c ON c.id = ci.customcertid
                     WHERE ci.id = :issueid";
            $name = $DB->get_field_sql($sql, ['issueid' => $issueid]);
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        if ($cmid > 0) {
            $cm = get_coursemodule_from_id('customcert', $cmid, $courseid, false, 'id,instance', IGNORE_MISSING);
            if ($cm) {
                $name = $DB->get_field('customcert', 'name', ['id' => (int)$cm->instance], IGNORE_MISSING);
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        }

        return '';
    }

    /**
     * Generate the PDF used by ncasign on course completion from a fixed customcert template.
     *
     * If the course has a customcert activity using this template, generate against a real issue
     * record so issue-dependent elements continue to work. Otherwise render directly from the
     * template as a fallback.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $templateid
     * @param string $verifyurl
     * @param array<int,array<string,mixed>> $signers
     * @return array{filename:string,content:string,documenttype:string,documenttitle:string,finalizationmanifest:array<string,mixed>}
     */
    private static function generate_customcert_pdf_for_course_completion(
        int $userid,
        int $courseid,
        int $templateid,
        string $verifyurl,
        array $signers = []
    ): array {
        global $DB;

        $customcert = $DB->get_record('customcert', ['templateid' => $templateid, 'course' => $courseid], '*', IGNORE_MISSING);
        if ($customcert) {
            $issue = $DB->get_record('customcert_issues', [
                'customcertid' => (int)$customcert->id,
                'userid' => $userid,
            ], '*', IGNORE_MISSING);

            if (!$issue) {
                $issue = (object)[
                    'userid' => $userid,
                    'customcertid' => (int)$customcert->id,
                    'code' => class_exists('\mod_customcert\certificate') ? \mod_customcert\certificate::generate_code() : null,
                    'emailed' => 0,
                    'timecreated' => time(),
                ];
                $issue->id = $DB->insert_record('customcert_issues', $issue);
            }

            $generated = self::generate_customcert_pdf_from_issue((int)$issue->id, $userid);
            if ($generated) {
                $overlay = self::add_signature_qr_slots_to_customcert_pdf((string)$generated['content'], $verifyurl, $signers);
                return [
                    'filename' => (string)$generated['filename'],
                    'content' => (string)$overlay['content'],
                    'documenttype' => 'certificate',
                    'documenttitle' => trim((string)$customcert->name) !== '' ? trim((string)$customcert->name) : 'Custom certificate',
                    'finalizationmanifest' => array_replace_recursive($overlay['finalizationmanifest'], [
                        'source' => 'customcert_issue',
                        'templateid' => $templateid,
                        'customcertid' => (int)$customcert->id,
                        'issueid' => (int)$issue->id,
                    ]),
                ];
            }
        }

        $generated = self::generate_customcert_pdf_from_template($templateid, $userid);
        if ($generated) {
            $overlay = self::add_signature_qr_slots_to_customcert_pdf((string)$generated['content'], $verifyurl, $signers);
            return [
                'filename' => (string)$generated['filename'],
                'content' => (string)$overlay['content'],
                'documenttype' => 'certificate',
                'documenttitle' => (string)$generated['documenttitle'],
                'finalizationmanifest' => array_replace_recursive($overlay['finalizationmanifest'], [
                    'source' => 'customcert_template',
                    'templateid' => $templateid,
                ]),
            ];
        }

        throw new \RuntimeException('Unable to generate PDF from customcert template id ' . $templateid . '.');
    }

    /**
     * Add the standard ncasign QR/signature slots to a customcert-generated PDF.
     *
     * @param string $pdfcontent
     * @param string $verifyurl
     * @param array<int,array<string,mixed>> $signers
     * @return array{content:string,finalizationmanifest:array<string,mixed>}
     */
    private static function add_signature_qr_slots_to_customcert_pdf(
        string $pdfcontent,
        string $verifyurl,
        array $signers = []
    ): array {
        $generator = new document_generator();
        return $generator->overlay_signature_qr_slots_on_pdf(
            $pdfcontent,
            $verifyurl,
            $signers,
            'customcert_template_pdf'
        );
    }

    /**
     * Generate certificate PDF bytes directly from mod_customcert issue data.
     *
     * @param int $issueid
     * @param int $fallbackuserid
     * @return array|null ['filename' => string, 'content' => string]
     */
    private static function generate_customcert_pdf_from_issue(int $issueid, int $fallbackuserid): ?array {
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
            $template = self::get_customcert_template_instance((int)$customcert->templateid);
            if (!$template) {
                return null;
            }
            $userid = (int)($issue->userid ?? $fallbackuserid);
            $content = null;
            $restoredelements = self::temporarily_apply_customcert_user_full_name((int)$customcert->templateid, $userid);
            $restoremiddlename = self::temporarily_apply_user_table_middlename_from_profile($userid);

            try {
                if (method_exists($template, 'generate_pdf')) {
                    $content = $template->generate_pdf(false, $userid, true);
                } else if (class_exists('\mod_customcert\service\pdf_generation_service')) {
                    $pdfservice = \mod_customcert\service\pdf_generation_service::create();
                    $content = $pdfservice->generate_pdf($template, false, $userid, true);
                }
            } finally {
                self::restore_customcert_text_overrides($restoredelements);
                self::restore_user_table_middlename($restoremiddlename);
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
     * Generate certificate PDF bytes directly from a customcert template id.
     *
     * @param int $templateid
     * @param int $userid
     * @return array{filename:string,content:string,documenttitle:string}|null
     */
    private static function generate_customcert_pdf_from_template(int $templateid, int $userid): ?array {
        global $DB;

        if ($templateid <= 0 || !class_exists('\mod_customcert\template')) {
            return null;
        }

        try {
            $template = self::get_customcert_template_instance($templateid);
            if (!$template) {
                return null;
            }

            $content = null;
            $restoredelements = self::temporarily_apply_customcert_user_full_name($templateid, $userid);
            $restoremiddlename = self::temporarily_apply_user_table_middlename_from_profile($userid);
            try {
                if (method_exists($template, 'generate_pdf')) {
                    $content = $template->generate_pdf(false, $userid, true);
                } else if (class_exists('\mod_customcert\service\pdf_generation_service')) {
                    $pdfservice = \mod_customcert\service\pdf_generation_service::create();
                    $content = $pdfservice->generate_pdf($template, false, $userid, true);
                }
            } finally {
                self::restore_customcert_text_overrides($restoredelements);
                self::restore_user_table_middlename($restoremiddlename);
            }

            if (!is_string($content) || $content === '') {
                return null;
            }

            $templatename = (string)$DB->get_field('customcert_templates', 'name', ['id' => $templateid], IGNORE_MISSING);
            $documenttitle = trim($templatename) !== '' ? trim($templatename) : 'Custom certificate';

            return [
                'filename' => "customcert_template_{$templateid}_user_{$userid}.pdf",
                'content' => $content,
                'documenttitle' => $documenttitle,
            ];
        } catch (\Throwable $e) {
            error_log('local_ncasign: customcert PDF generation failed for template ' . $templateid . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Load mod_customcert template object directly from the template record.
     *
     * @param int $templateid
     * @return object|null
     */
    private static function get_customcert_template_instance(int $templateid): ?object {
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
    private static function temporarily_apply_customcert_user_full_name(int $templateid, int $userid): array {
        global $DB;

        $value = self::resolve_customcert_user_full_name($userid);
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
            $name = self::normalise_customcert_element_name($rawname);
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
    private static function normalise_customcert_element_name(string $name): string {
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
    private static function temporarily_apply_user_table_middlename_from_profile(int $userid): ?array {
        global $DB;

        if ($userid <= 0) {
            return null;
        }

        $current = $DB->get_field('user', 'middlename', ['id' => $userid], IGNORE_MISSING);
        if ($current === false) {
            return null;
        }

        $profilemiddlename = self::resolve_custom_profile_middlename($userid);
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
    private static function restore_user_table_middlename(?array $restore): void {
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
    private static function restore_customcert_text_overrides(array $restoredelements): void {
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
    private static function resolve_customcert_user_full_name(int $userid): string {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,middlename', IGNORE_MISSING);
        if (!$user) {
            return '';
        }

        $middlename = self::resolve_custom_profile_middlename($userid);

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
    private static function resolve_custom_profile_middlename(int $userid): string {
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
     * Find latest PDF file for a customcert issue/context as fallback.
     *
     * @param int $contextid
     * @param int $issueid
     * @param int $userid
     * @return \stored_file|null
     */
    private static function find_customcert_pdf_file(int $contextid, int $issueid, int $userid): ?\stored_file {
        global $DB;
        $fs = get_file_storage();

        $params = ['contextid' => $contextid, 'component' => 'mod_customcert'];

        if ($issueid > 0) {
            $sql = "SELECT id
                      FROM {files}
                     WHERE contextid = :contextid
                       AND component = :component
                       AND itemid = :itemid
                       AND filename <> '.'
                       AND mimetype = 'application/pdf'
                  ORDER BY id DESC";
            $fileid = $DB->get_field_sql($sql, $params + ['itemid' => $issueid], IGNORE_MULTIPLE);
            if ($fileid) {
                $file = $fs->get_file_by_id($fileid);
                if ($file) {
                    return $file;
                }
            }
        }

        $sql = "SELECT id
                  FROM {files}
                 WHERE contextid = :contextid
                   AND component = :component
                   AND filename <> '.'
                   AND mimetype = 'application/pdf'
                   AND userid = :userid
              ORDER BY timemodified DESC, id DESC";
        $fileid = $DB->get_field_sql($sql, $params + ['userid' => $userid], IGNORE_MULTIPLE);
        if ($fileid) {
            return $fs->get_file_by_id($fileid) ?: null;
        }

        return null;
    }

    /**
     * Delete an un-notified job after draft generation/storage failure.
     *
     * @param int $jobid
     * @return void
     */
    private static function delete_job(int $jobid): void {
        global $DB;

        $DB->delete_records('local_ncasign_signers', ['jobid' => $jobid]);
        $DB->delete_records('local_ncasign_jobs', ['id' => $jobid]);
    }

    /**
     * Consume a one-shot suppression inserted by a manual scan upload.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private static function consume_completion_suppression(int $courseid, int $userid): bool {
        global $DB;

        $table = 'local_ncasign_completion_suppress';
        if (!$DB->get_manager()->table_exists($table)) {
            return false;
        }

        $now = time();
        $DB->delete_records_select($table, 'consumed = 0 AND expiresat > 0 AND expiresat < :now', ['now' => $now]);

        $record = $DB->get_record_select(
            $table,
            'userid = :userid AND courseid = :courseid AND consumed = 0 AND (expiresat = 0 OR expiresat >= :now)',
            [
                'userid' => $userid,
                'courseid' => $courseid,
                'now' => $now,
            ],
            '*',
            IGNORE_MULTIPLE
        );
        if (!$record) {
            return false;
        }

        $DB->update_record($table, (object)[
            'id' => (int)$record->id,
            'consumed' => 1,
            'timeconsumed' => $now,
        ]);

        error_log(
            'local_ncasign: suppressed course-completion generation for manual upload' .
            ' suppressionid=' . (int)$record->id .
            ' courseid=' . $courseid .
            ' userid=' . $userid .
            ' sourcecomponent=' . (string)($record->sourcecomponent ?? '') .
            ' sourceid=' . (int)($record->sourceid ?? 0) .
            ' reason=' . (string)($record->reason ?? '')
        );

        return true;
    }

    /**
     * Force course-completion diagnostics into the same file used by demo jobs.
     *
     * @return void
     */
    private static function ensure_course_completion_debug_log(): void {
        static $logged = false;

        @ini_set('log_errors', '1');
        @ini_set('error_log', '/tmp/ncasign-debug.log');

        if (!$logged) {
            $logged = true;
            error_log(
                'NCASIGN_CANARY course_completed forced log file active' .
                ' error_log=' . (string)ini_get('error_log') .
                ' sapi=' . PHP_SAPI
            );
        }
    }

    /**
     * Return signer emails for safe debug logging.
     *
     * @param array<int,array<string,mixed>> $signers
     * @return string
     */
    private static function debug_signer_emails(array $signers): string {
        $emails = [];
        foreach ($signers as $signer) {
            $email = trim((string)($signer['email'] ?? ''));
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return $emails ? implode(',', $emails) : '-';
    }
}
