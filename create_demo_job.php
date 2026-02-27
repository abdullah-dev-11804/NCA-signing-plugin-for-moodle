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

$url = new moodle_url('/local/ncasign/create_demo_job.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('createdemojob', 'local_ncasign'));
$PAGE->set_heading(get_string('createdemojob', 'local_ncasign'));

$userid = optional_param('userid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$signeremails = optional_param('signeremails', '', PARAM_RAW_TRIMMED);

if (optional_param('submitjob', 0, PARAM_BOOL) && confirm_sesskey()) {
    $manager = new \local_ncasign\local\job_manager();
    $certurl = $manager->build_certificate_url($courseid, $userid);

    $signers = [];
    if (trim($signeremails) !== '') {
        $emails = array_filter(array_map('trim', explode(',', $signeremails)));
        foreach ($emails as $email) {
            if (validate_email($email)) {
                $signers[] = ['email' => $email, 'name' => $email];
            }
        }
    } else if ($courseid > 0) {
        $coursecontext = context_course::instance($courseid);
        $signers = $manager->get_signers_from_configured_roles($coursecontext);
    }

    if ($userid > 0 && $courseid > 0) {
        $jobid = $manager->create_job($userid, $courseid, $certurl, $signers);
        if (!empty($_FILES['certificatepdf']['tmp_name']) && is_uploaded_file($_FILES['certificatepdf']['tmp_name'])) {
            $pdfcontent = file_get_contents($_FILES['certificatepdf']['tmp_name']);
            if ($pdfcontent !== false && $pdfcontent !== '') {
                $pdffilename = $_FILES['certificatepdf']['name'] ?? "certificate_{$jobid}.pdf";
                $manager->attach_certificate_binary_to_job($jobid, $pdffilename, $pdfcontent, 'manual_upload');
            }
        }
        redirect(new moodle_url('/local/ncasign/index.php'), "Demo job created: {$jobid}", 2);
    }
}

echo $OUTPUT->header();

echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('userid', 'local_ncasign'), 'id_userid');
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'userid',
    'id' => 'id_userid',
    'value' => $userid ?: '',
    'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('courseid', 'local_ncasign'), 'id_courseid');
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'courseid',
    'id' => 'id_courseid',
    'value' => $courseid ?: '',
    'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label(get_string('signeremails', 'local_ncasign'), 'id_signeremails');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'signeremails',
    'id' => 'id_signeremails',
    'value' => s($signeremails),
    'size' => 90,
    'placeholder' => 'approver1@example.com,approver2@example.com',
]);
echo html_writer::tag('p', 'Leave empty to use configured role IDs in this course.');
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::label('Certificate PDF (optional)', 'id_certificatepdf');
echo html_writer::empty_tag('input', [
    'type' => 'file',
    'name' => 'certificatepdf',
    'id' => 'id_certificatepdf',
    'accept' => 'application/pdf,.pdf',
]);
echo html_writer::tag('p', 'If uploaded, signers will sign this actual PDF bytes.');
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'name' => 'submitjob',
    'value' => get_string('createjob', 'local_ncasign'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
