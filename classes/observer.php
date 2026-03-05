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
        error_log('Course completed event observed for user ' . $userid . ' in course ' . $courseid);
        $manager = new job_manager();
        $context = \context_course::instance($courseid);
        $signers = $manager->get_signers_from_configured_roles($context);
        $certurl = $manager->build_certificate_url($courseid, $userid);
        $manager->create_job($userid, $courseid, $certurl, $signers);
    }

    /**
     * Queue signing job when customcert issues a certificate.
     *
     * @param \mod_customcert\event\certificate_issued $event
     * @return void
     */
    public static function certificate_issued($event): void {
        global $DB;
        if (!(int)get_config('local_ncasign', 'enabled')) {
            return;
        }
        error_log('Certificate issued event observed: ' . $event->get_name());
        $courseid = (int)$event->courseid;
        $userid = (int)($event->relateduserid ?? $event->userid ?? 0);
        if (!$courseid || !$userid) {
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

        $contextid = (int)$event->contextid;
        $issueid = (int)($event->objectid ?? 0);
        $storedfile = self::find_customcert_pdf_file($contextid, $issueid, $userid);
        if ($storedfile) {
            $manager->attach_certificate_binary_to_job(
                $jobid,
                $storedfile->get_filename(),
                $storedfile->get_content(),
                'mod_customcert_event'
            );
        }
    }

    /**
     * Find latest PDF file for a customcert issue/context.
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
