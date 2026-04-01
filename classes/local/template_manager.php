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

/**
 * Resolves document template profiles and signer sets.
 */
class template_manager {
    /**
     * Return all persisted template profiles.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_all_profiles(): array {
        global $DB;

        $records = $DB->get_records('local_ncasign_templates', null, 'active DESC, id ASC');
        $profiles = [];
        foreach ($records as $record) {
            $profiles[] = $this->hydrate_profile($record);
        }

        return $profiles;
    }

    /**
     * Return one persisted template profile.
     *
     * @param int $templateid
     * @return array<string, mixed>|null
     */
    public function get_profile(int $templateid): ?array {
        global $DB;

        $record = $DB->get_record('local_ncasign_templates', ['id' => $templateid], '*', IGNORE_MISSING);
        if (!$record) {
            return null;
        }

        return $this->hydrate_profile($record);
    }

    /**
     * Return all active template profiles mapped to a course.
     *
     * @param int $courseid
     * @return array<int, array<string, mixed>>
     */
    public function get_course_template_profiles(int $courseid): array {
        global $DB;

        $sql = "SELECT t.*
                  FROM {local_ncasign_templates} t
                  JOIN {local_ncasign_template_courses} tc ON tc.templateid = t.id
                 WHERE tc.courseid = :courseid
                   AND t.active = :active
              ORDER BY t.id ASC";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'active' => 1,
        ]);

        $profiles = [];
        foreach ($records as $record) {
            $profiles[] = $this->hydrate_profile($record);
        }

        return $profiles;
    }

    /**
     * Convert a DB record into the runtime profile structure.
     *
     * @param \stdClass $record
     * @return array<string, mixed>
     */
    public function hydrate_profile(\stdClass $record): array {
        return [
            'id' => (int)$record->id,
            'name' => (string)$record->name,
            'renderer' => (string)$record->renderer,
            'documenttype' => (string)$record->documenttype,
            'documenttitle' => (string)($record->documenttitle ?? ''),
            'templatepath' => (string)($record->templatepath ?? ''),
            'layoutconfig' => $this->decode_layout_config((string)($record->layoutconfig ?? '')),
            'layoutconfigraw' => (string)($record->layoutconfig ?? ''),
            'courseids' => $this->get_template_course_ids((int)$record->id),
            'signers' => $this->get_template_signers((int)$record->id),
        ];
    }

    /**
     * Return signer sequence for a template profile.
     *
     * @param int $templateid
     * @return array<int, array<string, mixed>>
     */
    public function get_template_signers(int $templateid): array {
        global $DB;

        $records = $DB->get_records('local_ncasign_template_signers', ['templateid' => $templateid], 'signorder ASC, id ASC');
        $signers = [];
        foreach ($records as $record) {
            $email = trim((string)$record->signeremail);
            if ($email === '' || !validate_email($email)) {
                continue;
            }

            $signers[] = [
                'id' => $this->resolve_userid_by_email($email),
                'email' => $email,
                'name' => trim((string)($record->signername ?? '')) !== '' ? trim((string)$record->signername) : $email,
                'position' => trim((string)($record->signerposition ?? '')) !== '' ? trim((string)$record->signerposition) : ('Commission member ' . (int)$record->signorder),
                'expectediin' => preg_replace('/\D+/', '', (string)($record->expectediin ?? '')),
            ];
        }

        return $signers;
    }

    /**
     * Return mapped course ids for a profile.
     *
     * @param int $templateid
     * @return int[]
     */
    public function get_template_course_ids(int $templateid): array {
        global $DB;

        $records = $DB->get_records('local_ncasign_template_courses', ['templateid' => $templateid], 'courseid ASC');
        return array_map(static function(\stdClass $record): int {
            return (int)$record->courseid;
        }, array_values($records));
    }

    /**
     * Create or update a template profile and replace its mappings/signers.
     *
     * @param array<string, mixed> $profiledata
     * @return int template id
     */
    public function save_profile(array $profiledata): int {
        global $DB;

        $now = time();
        $templateid = !empty($profiledata['id']) ? (int)$profiledata['id'] : 0;
        $record = (object)[
            'name' => trim((string)($profiledata['name'] ?? '')),
            'renderer' => trim((string)($profiledata['renderer'] ?? '')),
            'documenttype' => trim((string)($profiledata['documenttype'] ?? 'certificate')),
            'documenttitle' => trim((string)($profiledata['documenttitle'] ?? '')),
            'templatepath' => trim((string)($profiledata['templatepath'] ?? '')),
            'layoutconfig' => trim((string)($profiledata['layoutconfig'] ?? '')),
            'active' => !empty($profiledata['active']) ? 1 : 0,
            'timemodified' => $now,
        ];

        if ($templateid > 0) {
            $record->id = $templateid;
            $DB->update_record('local_ncasign_templates', $record);
        } else {
            $record->timecreated = $now;
            $templateid = (int)$DB->insert_record('local_ncasign_templates', $record);
        }

        $this->replace_template_courses($templateid, $profiledata['courseids'] ?? []);
        $this->replace_template_signers($templateid, $profiledata['signers'] ?? []);

        return $templateid;
    }

    /**
     * Delete a template profile and its mappings.
     *
     * @param int $templateid
     * @return void
     */
    public function delete_profile(int $templateid): void {
        global $DB;

        $DB->delete_records('local_ncasign_template_signers', ['templateid' => $templateid]);
        $DB->delete_records('local_ncasign_template_courses', ['templateid' => $templateid]);
        $DB->delete_records('local_ncasign_templates', ['id' => $templateid]);
    }

    /**
     * Decode JSON layout configuration safely.
     *
     * @param string $json
     * @return array<string, mixed>
     */
    private function decode_layout_config(string $json): array {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Resolve Moodle user id by email when a signer is also a local user.
     *
     * @param string $email
     * @return int|null
     */
    private function resolve_userid_by_email(string $email): ?int {
        global $DB;

        $records = $DB->get_records('user', [
            'email' => $email,
            'deleted' => 0,
            'suspended' => 0,
        ], 'id ASC', 'id', 0, 1);
        if (!$records) {
            return null;
        }

        $record = reset($records);
        return !empty($record->id) ? (int)$record->id : null;
    }

    /**
     * Replace course mappings for a template.
     *
     * @param int $templateid
     * @param mixed $courseids
     * @return void
     */
    private function replace_template_courses(int $templateid, $courseids): void {
        global $DB;

        $DB->delete_records('local_ncasign_template_courses', ['templateid' => $templateid]);
        $seen = [];
        foreach ($this->normalise_course_ids($courseids) as $courseid) {
            if (isset($seen[$courseid])) {
                continue;
            }
            $seen[$courseid] = true;
            $DB->insert_record('local_ncasign_template_courses', (object)[
                'templateid' => $templateid,
                'courseid' => $courseid,
            ]);
        }
    }

    /**
     * Replace signer sequence for a template.
     *
     * @param int $templateid
     * @param mixed $signers
     * @return void
     */
    private function replace_template_signers(int $templateid, $signers): void {
        global $DB;

        $DB->delete_records('local_ncasign_template_signers', ['templateid' => $templateid]);
        $normalisedsigners = is_array($signers) ? $signers : [];
        $order = 1;
        foreach ($normalisedsigners as $signer) {
            $email = trim((string)($signer['email'] ?? ''));
            if ($email === '' || !validate_email($email)) {
                continue;
            }

            $DB->insert_record('local_ncasign_template_signers', (object)[
                'templateid' => $templateid,
                'signeremail' => $email,
                'signername' => trim((string)($signer['name'] ?? '')) ?: null,
                'signerposition' => trim((string)($signer['position'] ?? '')) ?: null,
                'expectediin' => ($expectediin = preg_replace('/\D+/', '', (string)($signer['expectediin'] ?? ''))) !== '' ? $expectediin : null,
                'signorder' => $order,
            ]);
            $order++;
        }
    }

    /**
     * Normalise course ids input into integers.
     *
     * @param mixed $courseids
     * @return int[]
     */
    private function normalise_course_ids($courseids): array {
        if (is_string($courseids)) {
            $courseids = preg_split('/[\s,]+/', $courseids, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($courseids)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $courseids)));
    }
}
