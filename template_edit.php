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
        $decoded = json_decode($layoutconfigraw, true);
        if (!is_array($decoded)) {
            throw new moodle_exception('templateprofilelayoutinvalid', 'local_ncasign');
        }
    }

    $savedid = $manager->save_profile([
        'id' => $id,
        'name' => required_param('name', PARAM_TEXT),
        'renderer' => required_param('renderer', PARAM_ALPHANUMEXT),
        'documenttype' => required_param('documenttype', PARAM_ALPHA),
        'documenttitle' => required_param('documenttitle', PARAM_TEXT),
        'templatepath' => required_param('templatepath', PARAM_RAW_TRIMMED),
        'layoutconfig' => $layoutconfigraw,
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
    'documenttitle' => 'Industrial Safety Protocol (Engineer)',
    'templatepath' => '',
    'layoutconfigraw' => '',
    'courseids' => [],
    'signers' => [],
    'active' => 1,
];
$profile = $profile ? array_merge($defaults, $profile) : $defaults;
$courseidscsv = $profile['courseids'] ? implode(',', array_map('intval', $profile['courseids'])) : '';
$signersraw = local_ncasign_template_signers_to_text($profile['signers']);

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
''
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
