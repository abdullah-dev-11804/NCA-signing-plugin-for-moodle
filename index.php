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

$url = new moodle_url('/local/ncasign/index.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('jobs', 'local_ncasign'));
$PAGE->set_heading(get_string('jobs', 'local_ncasign'));

echo $OUTPUT->header();
echo $OUTPUT->single_button(
    new moodle_url('/local/ncasign/create_demo_job.php'),
    get_string('createdemojob', 'local_ncasign')
);

$jobs = $DB->get_records('local_ncasign_jobs', null, 'id DESC', '*', 0, 200);

$table = new html_table();
$table->head = [
    'ID',
    get_string('userid', 'local_ncasign'),
    get_string('courseid', 'local_ncasign'),
    get_string('status', 'local_ncasign'),
    get_string('deadline', 'local_ncasign'),
    get_string('manualsigned', 'local_ncasign'),
    get_string('autosigned', 'local_ncasign'),
    get_string('artifacts', 'local_ncasign'),
];

foreach ($jobs as $job) {
    $signedcount = $DB->count_records('local_ncasign_signers', [
        'jobid' => $job->id,
        'status' => \local_ncasign\local\job_manager::SIGNER_SIGNED,
    ]);
    $totalcount = $DB->count_records('local_ncasign_signers', ['jobid' => $job->id]);

    $table->data[] = [
        (int)$job->id,
        (int)$job->userid,
        (int)$job->courseid,
        s($job->status),
        userdate((int)$job->manualdeadline),
        "{$signedcount}/{$totalcount}",
        $job->autosigned ? userdate((int)$job->autosigned) : '-',
        local_ncasign_render_artifacts((int)$job->id),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();

/**
 * Render artifact links for a signing job.
 *
 * @param int $jobid
 * @return string
 */
function local_ncasign_render_artifacts(int $jobid): string {
    global $DB;

    $links = [];
    $originallink = new moodle_url('/local/ncasign/download_artifact.php', [
        'jobid' => $jobid,
        'type' => 'original',
    ]);
    $links[] = html_writer::link($originallink, 'PDF');

    $signed = $DB->get_records('local_ncasign_signers', [
        'jobid' => $jobid,
        'status' => \local_ncasign\local\job_manager::SIGNER_SIGNED,
    ]);
    foreach ($signed as $signer) {
        $siglink = new moodle_url('/local/ncasign/download_artifact.php', [
            'jobid' => $jobid,
            'type' => 'signature',
            'signerid' => (int)$signer->id,
        ]);
        $links[] = html_writer::link($siglink, 'CMS signer #' . (int)$signer->id);
    }

    return implode(' | ', $links);
}
