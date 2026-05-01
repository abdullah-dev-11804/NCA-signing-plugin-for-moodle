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

namespace local_ncasign\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Customcert-only template profile edit form.
 */
class template_edit_form extends \moodleform {
    /**
     * Define form controls.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;
        $templates = (array)($this->_customdata['availablecustomcerttemplates'] ?? []);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'profileheader', get_string('templateprofile', 'local_ncasign'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => 80]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'documenttitle', get_string('documenttitle', 'local_ncasign'), ['size' => 80]);
        $mform->setType('documenttitle', PARAM_TEXT);
        $mform->addRule('documenttitle', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'select',
            'customcerttemplateid',
            get_string('templatecustomcerttemplate', 'local_ncasign'),
            [0 => get_string('templatecustomcerttemplate_none', 'local_ncasign')] + $templates
        );
        $mform->setType('customcerttemplateid', PARAM_INT);
        $mform->addRule('customcerttemplateid', get_string('required'), 'required', null, 'client');
        $mform->addElement('static', 'customcerttemplateid_desc', '', get_string('templatecustomcerttemplate_desc', 'local_ncasign'));

        $mform->addElement('text', 'courseids', get_string('templatecourses', 'local_ncasign'), ['size' => 80]);
        $mform->setType('courseids', PARAM_RAW_TRIMMED);
        $mform->addRule('courseids', get_string('required'), 'required', null, 'client');
        $mform->addElement('static', 'courseids_desc', '', get_string('templatecourses_desc', 'local_ncasign'));

        $mform->addElement('textarea', 'signersraw', get_string('templatesigners', 'local_ncasign'), [
            'rows' => 6,
            'cols' => 100,
        ]);
        $mform->setType('signersraw', PARAM_RAW);
        $mform->addRule('signersraw', get_string('required'), 'required', null, 'client');
        $mform->addElement('static', 'signersraw_desc', '', get_string('templatesigners_desc', 'local_ncasign'));

        $mform->addElement('advcheckbox', 'active', get_string('templateactive', 'local_ncasign'));
        $mform->setType('active', PARAM_BOOL);
        $mform->setDefault('active', 1);

        $mform->addElement('header', 'metadataheader', get_string('templatefieldvalues', 'local_ncasign'));
        $mform->setExpanded('metadataheader', false);

        $mform->addElement('select', 'outputlanguage', get_string('templateoutputlanguage', 'local_ncasign'), [
            'bilingual' => get_string('templateoutputlanguage_bilingual', 'local_ncasign'),
            'ru' => get_string('templateoutputlanguage_ru', 'local_ncasign'),
        ]);
        $mform->setType('outputlanguage', PARAM_ALPHA);
        $mform->setDefault('outputlanguage', 'bilingual');

        $this->add_text_element('clientcompanyoverride', 'templateclientcompanyoverride', 80);
        $this->add_text_element('sentalcompanyname', 'templatesentalcompany', 80);
        $this->add_text_element('ordernumber', 'templateordernumber', 50);
        $mform->addElement('date_selector', 'orderdate', get_string('templateorderdate', 'local_ncasign'), ['optional' => true]);

        $this->add_text_element('protocoltype_initial_kz', 'templateprotocoltypeinitialkz', 40);
        $this->add_text_element('protocoltype_initial_ru', 'templateprotocoltypeinitialru', 40);
        $this->add_text_element('protocoltype_repeat_kz', 'templateprotocoltyperepeatkz', 40);
        $this->add_text_element('protocoltype_repeat_ru', 'templateprotocoltyperepeaturu', 40);
        $this->add_text_element('status_passed', 'templatestatuspassed', 80);
        $this->add_text_element('status_failed', 'templatestatusfailed', 80);

        $this->add_action_buttons(true);
    }

    /**
     * Add a text control using a local_ncasign string key.
     *
     * @param string $name
     * @param string $stringkey
     * @param int $size
     * @return void
     */
    private function add_text_element(string $name, string $stringkey, int $size): void {
        $mform = $this->_form;
        $mform->addElement('text', $name, get_string($stringkey, 'local_ncasign'), ['size' => $size]);
        $mform->setType($name, PARAM_TEXT);
    }

    /**
     * Validate profile data.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (empty($data['customcerttemplateid'])) {
            $errors['customcerttemplateid'] = get_string('required');
        }

        if (empty(self::normalise_course_ids($data['courseids'] ?? ''))) {
            $errors['courseids'] = get_string('templatecourses_invalid', 'local_ncasign');
        }

        $signers = self::parse_signers((string)($data['signersraw'] ?? ''));
        if (!$signers) {
            $errors['signersraw'] = get_string('templatesigners_invalid', 'local_ncasign');
        }

        return $errors;
    }

    /**
     * Build initial form data from a profile.
     *
     * @param array<string,mixed>|null $profile
     * @return \stdClass
     */
    public static function build_form_data(?array $profile): \stdClass {
        $profile = $profile ?? [];
        $layoutconfig = is_array($profile['layoutconfig'] ?? null) ? $profile['layoutconfig'] : [];
        $metadata = (array)($layoutconfig['metadata'] ?? []);

        $data = (object)[
            'id' => (int)($profile['id'] ?? 0),
            'name' => (string)($profile['name'] ?? ''),
            'documenttitle' => (string)($profile['documenttitle'] ?? 'Course certificate'),
            'customcerttemplateid' => self::resolve_customcert_template_id($profile, $layoutconfig),
            'courseids' => !empty($profile['courseids']) && is_array($profile['courseids'])
                ? implode(',', array_map('intval', $profile['courseids']))
                : '',
            'signersraw' => self::signers_to_text(is_array($profile['signers'] ?? null) ? $profile['signers'] : []),
            'active' => array_key_exists('active', $profile) ? (int)!empty($profile['active']) : 1,
            'outputlanguage' => (string)($metadata['outputlanguage'] ?? 'bilingual'),
            'clientcompanyoverride' => (string)($metadata['clientcompanyoverride'] ?? ''),
            'sentalcompanyname' => (string)($metadata['sentalcompanyname'] ?? 'ТОО "SENTAL"'),
            'ordernumber' => (string)($metadata['ordernumber'] ?? ''),
            'protocoltype_initial_kz' => (string)($metadata['protocoltype_initial_kz'] ?? 'бастапқы'),
            'protocoltype_initial_ru' => (string)($metadata['protocoltype_initial_ru'] ?? 'первичный'),
            'protocoltype_repeat_kz' => (string)($metadata['protocoltype_repeat_kz'] ?? 'қайталама'),
            'protocoltype_repeat_ru' => (string)($metadata['protocoltype_repeat_ru'] ?? 'повторный'),
            'status_passed' => (string)($metadata['status_passed'] ?? 'өтті / прошел'),
            'status_failed' => (string)($metadata['status_failed'] ?? 'қайта тексеруге жатады / подлежит повторной проверке знаний'),
        ];

        $orderdate = trim((string)($metadata['orderdate'] ?? ''));
        $data->orderdate = $orderdate !== '' ? strtotime($orderdate) : 0;

        return $data;
    }

    /**
     * Build stored layout config from submitted form data.
     *
     * @param \stdClass $data
     * @param array<string,mixed>|null $existingprofile
     * @return array<string,mixed>
     */
    public static function build_layout_config_from_data(\stdClass $data, ?array $existingprofile = null): array {
        $layoutconfig = is_array($existingprofile['layoutconfig'] ?? null) ? $existingprofile['layoutconfig'] : [];

        unset($layoutconfig['positions'], $layoutconfig['placeholder_masks'], $layoutconfig['static_masks']);
        unset($layoutconfig['structuredtemplate'], $layoutconfig['structuredcss']);

        $layoutconfig['customcert']['templateid'] = (int)$data->customcerttemplateid;
        $layoutconfig['metadata'] = [
            'outputlanguage' => in_array((string)$data->outputlanguage, ['bilingual', 'ru'], true)
                ? (string)$data->outputlanguage
                : 'bilingual',
            'clientcompanyoverride' => trim((string)$data->clientcompanyoverride),
            'sentalcompanyname' => trim((string)$data->sentalcompanyname),
            'orderdate' => !empty($data->orderdate) ? date('Y-m-d', (int)$data->orderdate) : '',
            'ordernumber' => trim((string)$data->ordernumber),
            'protocoltype_initial_kz' => trim((string)$data->protocoltype_initial_kz),
            'protocoltype_initial_ru' => trim((string)$data->protocoltype_initial_ru),
            'protocoltype_repeat_kz' => trim((string)$data->protocoltype_repeat_kz),
            'protocoltype_repeat_ru' => trim((string)$data->protocoltype_repeat_ru),
            'status_passed' => trim((string)$data->status_passed),
            'status_failed' => trim((string)$data->status_failed),
        ];

        return $layoutconfig;
    }

    /**
     * Parse signer textarea lines into structured signers.
     *
     * @param string $raw
     * @return array<int,array<string,string>>
     */
    public static function parse_signers(string $raw): array {
        $signers = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $email = $parts[0] ?? '';
            if ($email === '' || !validate_email($email)) {
                continue;
            }

            $signers[] = [
                'email' => $email,
                'name' => $parts[1] ?? '',
                'position' => $parts[2] ?? '',
                'expectediin' => preg_replace('/\D+/', '', (string)($parts[3] ?? '')),
            ];
        }

        return $signers;
    }

    /**
     * Convert signer array to textarea format.
     *
     * @param array<int,array<string,mixed>> $signers
     * @return string
     */
    private static function signers_to_text(array $signers): string {
        $lines = [];
        foreach ($signers as $signer) {
            $lines[] = implode('|', [
                trim((string)($signer['email'] ?? '')),
                trim((string)($signer['name'] ?? '')),
                trim((string)($signer['position'] ?? '')),
                preg_replace('/\D+/', '', (string)($signer['expectediin'] ?? '')),
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve customcert template id from hydrated or legacy profile data.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $layoutconfig
     * @return int
     */
    private static function resolve_customcert_template_id(array $profile, array $layoutconfig): int {
        $templateid = (int)($profile['customcerttemplateid'] ?? 0);
        if ($templateid <= 0) {
            $templateid = (int)((array)($layoutconfig['customcert'] ?? [])['templateid'] ?? 0);
        }
        if ($templateid <= 0
            && !empty($profile['templatepath'])
            && preg_match('/^customcert:(\d+)$/', (string)$profile['templatepath'], $matches)) {
            $templateid = (int)$matches[1];
        }

        return $templateid;
    }

    /**
     * Normalise course ids into integers.
     *
     * @param mixed $courseids
     * @return int[]
     */
    private static function normalise_course_ids($courseids): array {
        if (is_string($courseids)) {
            $courseids = preg_split('/[\s,]+/', $courseids, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($courseids)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $courseids)));
    }
}
