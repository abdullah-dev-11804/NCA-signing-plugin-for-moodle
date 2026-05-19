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

namespace local_ncasign\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Admin form for creating preview/demo signing jobs.
 */
class demo_job_form extends \moodleform {
    /**
     * Define form controls.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;
        $profileoptions = (array)($this->_customdata['profileoptions'] ?? []);

        $mform->addElement('header', 'demoheader', get_string('createdemojob', 'local_ncasign'));

        $mform->addElement('text', 'userid', get_string('userid', 'local_ncasign'), ['size' => 12]);
        $mform->setType('userid', PARAM_INT);
        $mform->addRule('userid', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'courseid', get_string('courseid', 'local_ncasign'), ['size' => 12]);
        $mform->setType('courseid', PARAM_INT);
        $mform->addRule('courseid', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'select',
            'templateprofileid',
            get_string('demotemplateprofile', 'local_ncasign'),
            [0 => get_string('demotemplateprofile_firstmapped', 'local_ncasign')] + $profileoptions
        );
        $mform->setType('templateprofileid', PARAM_INT);
        $mform->addElement('static', 'templateprofileid_desc', '', get_string('demotemplateprofile_desc', 'local_ncasign'));

        $mform->addElement('text', 'documenttitle', get_string('documenttitle', 'local_ncasign'), ['size' => 80]);
        $mform->setType('documenttitle', PARAM_TEXT);

        $mform->addElement('text', 'signeremails', get_string('signeremails', 'local_ncasign'), ['size' => 90]);
        $mform->setType('signeremails', PARAM_RAW_TRIMMED);
        $mform->addElement('static', 'signeremails_desc', '', get_string('demotsigneremails_desc', 'local_ncasign'));

        $mform->addElement('advcheckbox', 'usedemodata', get_string('demousedemodata', 'local_ncasign'));
        $mform->setType('usedemodata', PARAM_BOOL);
        $mform->setDefault('usedemodata', 1);
        $mform->addElement('static', 'usedemodata_desc', '', get_string('demousedemodata_desc', 'local_ncasign'));

        $mform->addElement('advcheckbox', 'autosigndemo', get_string('demoautosign', 'local_ncasign'));
        $mform->setType('autosigndemo', PARAM_BOOL);
        $mform->setDefault('autosigndemo', 0);
        $mform->addElement('static', 'autosigndemo_desc', '', get_string('demoautosign_desc', 'local_ncasign'));

        $this->add_action_buttons(true, get_string('createjob', 'local_ncasign'));
    }

    /**
     * Validate submitted form data.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        global $DB;

        $errors = parent::validation($data, $files);

        $userid = (int)($data['userid'] ?? 0);
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            $errors['userid'] = get_string('demouser_invalid', 'local_ncasign');
        }

        $courseid = (int)($data['courseid'] ?? 0);
        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            $errors['courseid'] = get_string('democourse_invalid', 'local_ncasign');
        }

        $templateprofileid = (int)($data['templateprofileid'] ?? 0);
        if ($templateprofileid > 0 && !$DB->record_exists('local_ncasign_templates', ['id' => $templateprofileid])) {
            $errors['templateprofileid'] = get_string('demotemplateprofile_invalid', 'local_ncasign');
        }

        foreach (self::parse_signer_emails((string)($data['signeremails'] ?? '')) as $email => $valid) {
            if (!$valid) {
                $errors['signeremails'] = get_string('demotsigneremails_invalid', 'local_ncasign');
                break;
            }
        }

        return $errors;
    }

    /**
     * Parse comma-separated signer emails.
     *
     * @param string $raw
     * @return array<string,bool>
     */
    public static function parse_signer_emails(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $result = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $email) {
            $email = trim((string)$email);
            $result[$email] = validate_email($email);
        }

        return $result;
    }
}
