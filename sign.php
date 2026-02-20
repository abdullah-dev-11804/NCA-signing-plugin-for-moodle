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

$token = required_param('token', PARAM_ALPHANUMEXT);
$url = new moodle_url('/local/ncasign/sign.php', ['token' => $token]);
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('signtitle', 'local_ncasign'));
$PAGE->set_heading(get_string('signtitle', 'local_ncasign'));

$manager = new \local_ncasign\local\job_manager();
$row = $manager->get_signer_by_token($token);

echo $OUTPUT->header();

if (!$row) {
    echo $OUTPUT->notification(get_string('invalidtoken', 'local_ncasign'), \core\output\notification::NOTIFY_ERROR);
    echo $OUTPUT->footer();
    exit;
}

$signer = $row['signer'];
$job = $row['job'];

if (optional_param('signnow', 0, PARAM_BOOL) && $signer->status === \local_ncasign\local\job_manager::SIGNER_PENDING) {
    $meta = [
        'mode' => 'demo_manual_click',
        'ip' => getremoteaddr(null),
        'time' => time(),
    ];
    $manager->mark_signer_signed($token, 'manual_demo', $meta);
    echo $OUTPUT->notification(get_string('signedok', 'local_ncasign'), \core\output\notification::NOTIFY_SUCCESS);
    $row = $manager->get_signer_by_token($token);
    $signer = $row['signer'];
    $job = $row['job'];
}

if ($signer->status !== \local_ncasign\local\job_manager::SIGNER_PENDING) {
    echo $OUTPUT->notification(get_string('alreadysigned', 'local_ncasign'), \core\output\notification::NOTIFY_INFO);
}

echo html_writer::tag('p', get_string('signinstructions', 'local_ncasign'));
echo html_writer::start_tag('ul');
echo html_writer::tag('li', 'Signer email: ' . s($signer->signeremail));
echo html_writer::tag('li', 'Student user ID: ' . (int)$job->userid);
echo html_writer::tag('li', 'Course ID: ' . (int)$job->courseid);
echo html_writer::tag('li', 'Certificate URL: ' . s((string)$job->certificateurl));
echo html_writer::tag('li', 'Manual deadline: ' . userdate((int)$job->manualdeadline));
echo html_writer::end_tag('ul');

if ($signer->status === \local_ncasign\local\job_manager::SIGNER_PENDING) {
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => s($token)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'signnow', 'value' => 1]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('signbutton', 'local_ncasign'), 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
