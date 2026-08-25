<?php
// Soft-delete a signing job.

require_once(__DIR__ . '/../../config.php');

$jobid = required_param('id', PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/ncasign:managejobs', $context);
require_sesskey();

$url = new moodle_url('/local/ncasign/delete_job.php', ['id' => $jobid, 'sesskey' => sesskey()]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('deletejob', 'local_ncasign'));
$PAGE->set_heading(get_string('deletejob', 'local_ncasign'));

$manager = new \local_ncasign\local\job_manager();
$job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', MUST_EXIST);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = optional_param('reason', '', PARAM_TEXT);
    $manager->soft_delete_job(
        $jobid,
        \local_ncasign\local\job_manager::JOB_SOFT_DELETED,
        trim($reason) !== '' ? trim($reason) : get_string('jobsoftdeleteddefaultreason', 'local_ncasign'),
        (int)$USER->id
    );
    redirect(new moodle_url('/local/ncasign/index.php'), get_string('jobsoftdeleted', 'local_ncasign', $jobid));
}

echo $OUTPUT->header();
echo html_writer::tag('h3', get_string('deletejob', 'local_ncasign'));
echo html_writer::tag('p', get_string('deletejobconfirm', 'local_ncasign', $jobid), ['class' => 'alert alert-warning']);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('jobactionreason', 'local_ncasign'), ['for' => 'id_reason']);
echo html_writer::tag('textarea', '', [
    'name' => 'reason',
    'id' => 'id_reason',
    'class' => 'form-control',
    'rows' => 4,
]);
echo html_writer::end_div();
echo html_writer::tag('button', get_string('deletejobbutton', 'local_ncasign'), [
    'type' => 'submit',
    'class' => 'btn btn-danger mr-2',
]);
echo html_writer::link(new moodle_url('/local/ncasign/job.php', ['id' => $jobid]), get_string('cancel'), [
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
