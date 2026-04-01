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
        if (!(int)get_config('local_ncasign', 'enabled')) {
            return;
        }

        $courseid = (int)$event->courseid;
        $userid = (int)$event->relateduserid;
        if (!$courseid || !$userid) {
            return;
        }

        $manager = new job_manager();
        $templatemanager = new template_manager();
        $profiles = $templatemanager->get_course_template_profiles($courseid);
        if (!$profiles) {
            error_log('local_ncasign: no mapped template profiles found for course ' . $courseid . ', user ' . $userid);
            return;
        }
        $generator = new document_generator();

        ob_start();
        try {
            foreach ($profiles as $profile) {
                $signers = $profile['signers'] ?? [];
                if (!$signers) {
                    error_log('local_ncasign: template profile has no configured signers; skipping profile ' . (string)($profile['name'] ?? 'unknown'));
                    continue;
                }

                try {
                    $draft = $generator->generate_draft_from_profile($userid, $courseid, $profile);
                } catch (\Throwable $e) {
                    error_log(
                        'local_ncasign: failed to generate draft on course completion for profile ' .
                        (string)($profile['name'] ?? 'unknown') . ': ' . $e->getMessage()
                    );
                    continue;
                }

                $jobid = $manager->create_job(
                    $userid,
                    $courseid,
                    '',
                    $signers,
                    null,
                    (string)($draft['documenttype'] ?? ($profile['documenttype'] ?? 'protocol')),
                    (string)($draft['documenttitle'] ?? ($profile['documenttitle'] ?? 'Course document')),
                    false,
                    !empty($profile['id']) ? (int)$profile['id'] : null
                );

                try {
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
                } catch (\Throwable $e) {
                    self::delete_job($jobid);
                    error_log('local_ncasign: failed to persist generated draft for job ' . $jobid . ': ' . $e->getMessage());
                    continue;
                }

                try {
                    $manager->notify_signers_for_job($jobid);
                } catch (\Throwable $e) {
                    error_log('local_ncasign: failed to notify signers for job ' . $jobid . ': ' . $e->getMessage());
                }
            }
        } finally {
            $unexpectedoutput = trim((string)ob_get_clean());
            if ($unexpectedoutput !== '') {
                error_log('local_ncasign: unexpected output during course completion observer: ' . trim(strip_tags($unexpectedoutput)));
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
        $jobid = $manager->create_job($userid, $courseid, $certurl, $signers, null, 'certificate', $documenttitle);
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

            if (class_exists('\mod_customcert\service\pdf_generation_service')) {
                $pdfservice = \mod_customcert\service\pdf_generation_service::create();
                $content = $pdfservice->generate_pdf($template, false, $userid, true);
            } else if (method_exists($template, 'generate_pdf')) {
                $content = $template->generate_pdf(false, $userid, true);
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
    private static function get_customcert_template_instance(int $templateid): ?object {
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
}
