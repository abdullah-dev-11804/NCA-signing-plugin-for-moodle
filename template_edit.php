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

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/ncasign:managejobs', $context);

$manager = new \local_ncasign\local\template_manager();
$id = optional_param('id', 0, PARAM_INT);
$profile = $id > 0 ? $manager->get_profile($id) : null;

$url = new moodle_url('/local/ncasign/template_edit.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('templateprofileedit', 'local_ncasign'));
$PAGE->set_heading(get_string('templateprofileedit', 'local_ncasign'));

if (optional_param('saveprofile', 0, PARAM_BOOL) && confirm_sesskey()) {
    $layoutconfigraw = optional_param('layoutconfig', '', PARAM_RAW);
    if (trim($layoutconfigraw) !== '') {
        $decodedlayout = json_decode($layoutconfigraw, true);
        if (!is_array($decodedlayout)) {
            throw new moodle_exception('templateprofilelayoutinvalid', 'local_ncasign');
        }
        $layoutconfig = $decodedlayout;
    } else {
        $layoutconfig = [];
    }

    $layoutconfig = local_ncasign_merge_protocol_layout_defaults($layoutconfig);
    $layoutconfig['metadata']['outputlanguage'] = trim((string)optional_param('outputlanguage', 'bilingual', PARAM_ALPHA));
    $layoutconfig['metadata']['clientcompanyoverride'] = trim((string)optional_param('clientcompanyoverride', '', PARAM_TEXT));
    $layoutconfig['metadata']['sentalcompanyname'] = trim((string)optional_param('sentalcompanyname', '', PARAM_TEXT));
    $layoutconfig['metadata']['orderdate'] = trim((string)optional_param('orderdate', '', PARAM_TEXT));
    $layoutconfig['metadata']['ordernumber'] = trim((string)optional_param('ordernumber', '', PARAM_TEXT));
    $layoutconfig['metadata']['protocoltype_initial_kz'] = trim((string)optional_param('protocoltype_initial_kz', '', PARAM_TEXT));
    $layoutconfig['metadata']['protocoltype_initial_ru'] = trim((string)optional_param('protocoltype_initial_ru', '', PARAM_TEXT));
    $layoutconfig['metadata']['protocoltype_repeat_kz'] = trim((string)optional_param('protocoltype_repeat_kz', '', PARAM_TEXT));
    $layoutconfig['metadata']['protocoltype_repeat_ru'] = trim((string)optional_param('protocoltype_repeat_ru', '', PARAM_TEXT));
    $layoutconfig['metadata']['status_passed'] = trim((string)optional_param('status_passed', '', PARAM_TEXT));
    $layoutconfig['metadata']['status_failed'] = trim((string)optional_param('status_failed', '', PARAM_TEXT));
    $persistedlayoutconfig = json_encode($layoutconfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    $savedid = $manager->save_profile([
        'id' => $id,
        'name' => required_param('name', PARAM_TEXT),
        'renderer' => required_param('renderer', PARAM_ALPHANUMEXT),
        'documenttype' => required_param('documenttype', PARAM_ALPHA),
        'documenttitle' => required_param('documenttitle', PARAM_TEXT),
        'templatepath' => required_param('templatepath', PARAM_RAW_TRIMMED),
        'layoutconfig' => $persistedlayoutconfig,
        'active' => optional_param('active', 0, PARAM_BOOL),
        'courseids' => optional_param('courseids', '', PARAM_RAW_TRIMMED),
        'signers' => local_ncasign_parse_template_signers(optional_param('signersraw', '', PARAM_RAW)),
    ]);

    redirect(
        new moodle_url('/local/ncasign/template_edit.php', ['id' => $savedid]),
        get_string('templateprofilenotice_saved', 'local_ncasign')
    );
}

$defaults = [
    'name' => '',
    'renderer' => \local_ncasign\local\document_generator::DOC_ENGINEER_PROTOCOL,
    'documenttype' => 'protocol',
    'documenttitle' => 'Industrial Safety Protocol (BiOT ITR)',
    'templatepath' => '',
    'layoutconfigraw' => json_encode(local_ncasign_protocol_layout_defaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
    'layoutconfig' => local_ncasign_protocol_layout_defaults(),
    'courseids' => [],
    'signers' => [],
    'active' => 1,
];
$profile = $profile ? array_merge($defaults, $profile) : $defaults;
$profile['layoutconfig'] = local_ncasign_merge_protocol_layout_defaults(is_array($profile['layoutconfig'] ?? null) ? $profile['layoutconfig'] : []);
$profile['layoutconfigraw'] = json_encode($profile['layoutconfig'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$courseidscsv = $profile['courseids'] ? implode(',', array_map('intval', $profile['courseids'])) : '';
$signersraw = local_ncasign_template_signers_to_text($profile['signers']);
$layoutmetadata = (array)($profile['layoutconfig']['metadata'] ?? []);

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/ncasign/templates.php'), get_string('templateprofilesback', 'local_ncasign'));

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('name'), 'id_name');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'name',
    'id' => 'id_name',
    'value' => s((string)$profile['name']),
    'size' => 80,
    'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templaterenderer', 'local_ncasign'), 'id_renderer');
echo html_writer::select(
    [
        \local_ncasign\local\document_generator::DOC_ENGINEER_PROTOCOL => 'Engineer protocol',
    ],
    'renderer',
    (string)$profile['renderer'],
    false,
    ['id' => 'id_renderer']
);
echo html_writer::tag('p', get_string('templaterenderer_desc', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('documenttype', 'local_ncasign'), 'id_documenttype');
echo html_writer::select(
    [
        'certificate' => 'Certificate',
        'protocol' => 'Protocol',
        'credential' => 'Credential',
    ],
    'documenttype',
    (string)$profile['documenttype'],
    false,
    ['id' => 'id_documenttype']
);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('documenttitle', 'local_ncasign'), 'id_documenttitle');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'documenttitle',
    'id' => 'id_documenttitle',
    'value' => s((string)$profile['documenttitle']),
    'size' => 80,
    'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templatepathlabel', 'local_ncasign'), 'id_templatepath');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'templatepath',
    'id' => 'id_templatepath',
    'value' => s((string)$profile['templatepath']),
    'size' => 100,
    'required' => 'required',
]);
echo html_writer::tag('p', get_string('templatepathlabel_desc', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templatecourses', 'local_ncasign'), 'id_courseids');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'courseids',
    'id' => 'id_courseids',
    'value' => s($courseidscsv),
    'size' => 80,
    'placeholder' => '56,174,221',
]);
echo html_writer::tag('p', get_string('templatecourses_desc', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templatesigners', 'local_ncasign'), 'id_signersraw');
echo html_writer::tag('textarea', s($signersraw), [
    'name' => 'signersraw',
    'id' => 'id_signersraw',
    'rows' => 6,
    'cols' => 100,
    'placeholder' => "signer1@example.com|Signer One|Commission Chair|123456789012\nsigner2@example.com|Signer Two|Commission Member|123456789013",
]);
echo html_writer::tag('p', get_string('templatesigners_desc', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templateoutputlanguage', 'local_ncasign'), 'id_outputlanguage');
echo html_writer::select(
    [
        'bilingual' => get_string('templateoutputlanguage_bilingual', 'local_ncasign'),
        'ru' => get_string('templateoutputlanguage_ru', 'local_ncasign'),
    ],
    'outputlanguage',
    (string)($layoutmetadata['outputlanguage'] ?? 'bilingual'),
    false,
    ['id' => 'id_outputlanguage']
);
echo html_writer::tag('p', get_string('templateoutputlanguage_desc', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templateclientcompanyoverride', 'local_ncasign'), 'id_clientcompanyoverride');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'clientcompanyoverride',
    'id' => 'id_clientcompanyoverride',
    'value' => s((string)($layoutmetadata['clientcompanyoverride'] ?? '')),
    'size' => 80,
]);
echo html_writer::tag('p', get_string('templateclientcompanyoverride_desc', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templatesentalcompany', 'local_ncasign'), 'id_sentalcompanyname');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'sentalcompanyname',
    'id' => 'id_sentalcompanyname',
    'value' => s((string)($layoutmetadata['sentalcompanyname'] ?? '')),
    'size' => 80,
]);
echo html_writer::tag('p', get_string('templatesentalcompany_desc', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templateorderdate', 'local_ncasign'), 'id_orderdate');
echo html_writer::empty_tag('input', [
    'type' => 'date',
    'name' => 'orderdate',
    'id' => 'id_orderdate',
    'value' => s((string)($layoutmetadata['orderdate'] ?? '')),
]);
echo html_writer::tag('p', get_string('templateorderdate_desc', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templateordernumber', 'local_ncasign'), 'id_ordernumber');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'ordernumber',
    'id' => 'id_ordernumber',
    'value' => s((string)($layoutmetadata['ordernumber'] ?? '')),
    'size' => 50,
]);
echo html_writer::tag('p', get_string('templateordernumber_desc', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templateprotocoltypeinitialkz', 'local_ncasign'), 'id_protocoltype_initial_kz');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'protocoltype_initial_kz',
    'id' => 'id_protocoltype_initial_kz',
    'value' => s((string)($layoutmetadata['protocoltype_initial_kz'] ?? '')),
    'size' => 40,
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templateprotocoltypeinitialru', 'local_ncasign'), 'id_protocoltype_initial_ru');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'protocoltype_initial_ru',
    'id' => 'id_protocoltype_initial_ru',
    'value' => s((string)($layoutmetadata['protocoltype_initial_ru'] ?? '')),
    'size' => 40,
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templateprotocoltyperepeatkz', 'local_ncasign'), 'id_protocoltype_repeat_kz');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'protocoltype_repeat_kz',
    'id' => 'id_protocoltype_repeat_kz',
    'value' => s((string)($layoutmetadata['protocoltype_repeat_kz'] ?? '')),
    'size' => 40,
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templateprotocoltyperepeaturu', 'local_ncasign'), 'id_protocoltype_repeat_ru');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'protocoltype_repeat_ru',
    'id' => 'id_protocoltype_repeat_ru',
    'value' => s((string)($layoutmetadata['protocoltype_repeat_ru'] ?? '')),
    'size' => 40,
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templatestatuspassed', 'local_ncasign'), 'id_status_passed');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'status_passed',
    'id' => 'id_status_passed',
    'value' => s((string)($layoutmetadata['status_passed'] ?? '')),
    'size' => 80,
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templatestatusfailed', 'local_ncasign'), 'id_status_failed');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'status_failed',
    'id' => 'id_status_failed',
    'value' => s((string)($layoutmetadata['status_failed'] ?? '')),
    'size' => 80,
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('templatelayoutconfig', 'local_ncasign'), 'id_layoutconfig');
echo html_writer::tag('textarea', s((string)$profile['layoutconfigraw']), [
    'name' => 'layoutconfig',
    'id' => 'id_layoutconfig',
    'rows' => 12,
    'cols' => 100,
    'placeholder' => '{"page1":{"fullname":{"x":89,"y":617,"w":152,"h":14}}}',
]);
echo html_writer::tag('p', get_string('templatelayoutconfig_desc', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::start_div('form-check mb-3');
echo html_writer::checkbox('active', 1, !empty($profile['active']), get_string('templateactive', 'local_ncasign'));
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'name' => 'saveprofile',
    'value' => get_string('savechanges'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();

/**
 * Parse signer textarea lines into structured signers.
 *
 * Format per line: email|name|position|expectediin
 *
 * @param string $raw
 * @return array<int, array<string, string>>
 */
function local_ncasign_parse_template_signers(string $raw): array {
    $signers = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        $signers[] = [
            'email' => $parts[0] ?? '',
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
 * @param array<int, array<string, mixed>> $signers
 * @return string
 */
function local_ncasign_template_signers_to_text(array $signers): string {
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
 * Decode layout JSON safely.
 *
 * @param string $raw
 * @return array<string, mixed>
 */
function local_ncasign_decode_template_layout(string $raw): array {
    if (trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Default layout config for the finalized BiOT ITR protocol.
 *
 * @return array<string, mixed>
 */
function local_ncasign_protocol_layout_defaults(): array {
    return [
        'metadata' => [
            'outputlanguage' => 'bilingual',
            'clientcompanyoverride' => '',
            'sentalcompanyname' => 'ТОО "SENTAL"',
            'orderdate' => '',
            'ordernumber' => '',
            'protocoltype_initial_kz' => 'бастапқы',
            'protocoltype_initial_ru' => 'первичный',
            'protocoltype_repeat_kz' => 'қайталама',
            'protocoltype_repeat_ru' => 'повторный',
            'status_passed' => 'өтті / прошел',
            'status_failed' => 'қайта тексеруге жатады / подлежит повторной проверке знаний',
        ],
        'positions' => [],
    ];
}

/**
 * Merge stored layout with finalized protocol defaults.
 *
 * @param array<string, mixed> $layout
 * @return array<string, mixed>
 */
function local_ncasign_merge_protocol_layout_defaults(array $layout): array {
    return array_replace_recursive(local_ncasign_protocol_layout_defaults(), $layout);
}
