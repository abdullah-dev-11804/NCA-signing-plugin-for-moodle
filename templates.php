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

$deleteid = optional_param('delete', 0, PARAM_INT);
if ($deleteid > 0 && confirm_sesskey()) {
    (new \local_ncasign\local\template_manager())->delete_profile($deleteid);
    redirect(new moodle_url('/local/ncasign/templates.php'), get_string('templateprofilenotice_deleted', 'local_ncasign'));
}

$PAGE->set_url(new moodle_url('/local/ncasign/templates.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('templateprofiles', 'local_ncasign'));
$PAGE->set_heading(get_string('templateprofiles', 'local_ncasign'));

$manager = new \local_ncasign\local\template_manager();
$profiles = $manager->get_all_profiles();

echo $OUTPUT->header();
echo $OUTPUT->single_button(
    new moodle_url('/local/ncasign/template_edit.php'),
    get_string('templateprofileadd', 'local_ncasign')
);

if (!$profiles) {
    echo $OUTPUT->notification(get_string('templateprofilesempty', 'local_ncasign'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    'ID',
    get_string('name'),
    get_string('templatecustomcerttemplate', 'local_ncasign'),
    get_string('templatecourses', 'local_ncasign'),
    get_string('templatesigners', 'local_ncasign'),
    get_string('status', 'local_ncasign'),
    get_string('actions'),
];

foreach ($profiles as $profile) {
    $courses = $profile['courseids'] ? implode(', ', array_map('intval', $profile['courseids'])) : '-';
    $signers = [];
    foreach ($profile['signers'] as $signer) {
        $signers[] = s((string)$signer['email']);
    }

    $editurl = new moodle_url('/local/ncasign/template_edit.php', ['id' => (int)$profile['id']]);
    $deleteurl = new moodle_url('/local/ncasign/templates.php', ['delete' => (int)$profile['id'], 'sesskey' => sesskey()]);
    $actions = html_writer::link($editurl, get_string('edit')) . ' | ' .
        html_writer::link($deleteurl, get_string('delete'));

    $templatelabel = !empty($profile['customcerttemplatename'])
        ? (string)$profile['customcerttemplatename'] . ' #' . (int)$profile['customcerttemplateid']
        : '-';

    $table->data[] = [
        (int)$profile['id'],
        s((string)$profile['name']),
        s($templatelabel),
        s($courses),
        $signers ? implode('<br>', $signers) : '-',
        !empty($profile['active']) ? get_string('yes') : get_string('no'),
        $actions,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
