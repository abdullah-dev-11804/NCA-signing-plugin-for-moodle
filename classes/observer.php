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
        $context = \context_course::instance($courseid);
        $signers = $manager->get_signers_from_configured_roles($context);
        $certurl = $manager->build_certificate_url($courseid, $userid);
        $manager->create_job($userid, $courseid, $certurl, $signers);
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

        $jobid = $manager->create_job($userid, $courseid, $certurl, $signers);

        $issueid = (int)($event->objectid ?? 0);
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
            return new \mod_customcert\template($templateid);
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
}
