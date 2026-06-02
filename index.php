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
    new moodle_url('/local/ncasign/templates.php'),
    get_string('templateprofiles', 'local_ncasign')
);
echo $OUTPUT->single_button(
    new moodle_url('/local/ncasign/create_demo_job.php'),
    get_string('createdemojob', 'local_ncasign')
);
echo local_ncasign_index_styles();

$jobs = $DB->get_records('local_ncasign_jobs', null, 'id DESC', '*', 0, 200);

$table = new html_table();
$table->head = [
    'ID',
    get_string('userid', 'local_ncasign'),
    get_string('courseid', 'local_ncasign'),
    get_string('joborigin', 'local_ncasign'),
    get_string('status', 'local_ncasign'),
    get_string('deadline', 'local_ncasign'),
    get_string('manualsigned', 'local_ncasign'),
    get_string('autosigned', 'local_ncasign'),
    get_string('jobdetails', 'local_ncasign'),
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
        local_ncasign_render_origin_badge((string)($job->origin ?? 'course_completion')),
        local_ncasign_render_job_status_badge($job, $signedcount, $totalcount),
        userdate((int)$job->manualdeadline),
        "{$signedcount}/{$totalcount}",
        $job->autosigned ? userdate((int)$job->autosigned) : '-',
        html_writer::link(new moodle_url('/local/ncasign/job.php', ['id' => (int)$job->id]), get_string('viewdetails', 'local_ncasign')),
        local_ncasign_render_artifacts((int)$job->id),
    ];
}

echo html_writer::div(html_writer::table($table), 'ncasign-jobs-scroll');
echo $OUTPUT->footer();

/**
 * Return page-local styles.
 *
 * @return string
 */
function local_ncasign_index_styles(): string {
    return html_writer::tag('style', '
.ncasign-jobs-scroll {
    margin-top: 1rem;
    overflow-x: auto;
    transform: rotateX(180deg);
}
.ncasign-jobs-scroll > table {
    min-width: 980px;
    transform: rotateX(180deg);
}
.ncasign-jobs-scroll th,
.ncasign-jobs-scroll td {
    white-space: nowrap;
}
.ncasign-jobs-scroll td:last-child {
    white-space: normal;
    min-width: 260px;
}
');
}

/**
 * Render artifact links for a signing job.
 *
 * @param int $jobid
 * @return string
 */
function local_ncasign_render_artifacts(int $jobid): string {
    global $DB;

    $manager = new \local_ncasign\local\job_manager();
    $links = [];
    if ($manager->has_job_original_pdf($jobid)) {
        $originallink = new moodle_url('/local/ncasign/download_artifact.php', [
            'jobid' => $jobid,
            'type' => 'original',
        ]);
        $links[] = html_writer::link($originallink, 'Original PDF');
    }

    if ($manager->has_job_signed_pdf($jobid)) {
        $signedpdf = $manager->get_job_signed_pdf_binary($jobid);
        $issignedfinal = !empty($signedpdf['filename']) && stripos((string)$signedpdf['filename'], 'signed_final_') !== false;
        $signedpdflink = new moodle_url('/local/ncasign/download_artifact.php', [
            'jobid' => $jobid,
            'type' => 'signedpdf',
        ]);
        $label = $issignedfinal
            ? get_string('signedpdffinallabel', 'local_ncasign')
            : get_string('signedpdfprogresslabel', 'local_ncasign');
        $links[] = html_writer::link($signedpdflink, $label);
    }

    $verifylink = $manager->get_verification_url_for_job($jobid);
    if ($verifylink !== '') {
        $links[] = html_writer::link($verifylink, 'Verify');
    }

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

    $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], 'autosignnote', IGNORE_MISSING);
    if ($job && !empty($job->autosignnote)) {
        $links[] = html_writer::tag('span', 'Finalization note: ' . s((string)$job->autosignnote), [
            'style' => 'color:#b00020;',
        ]);
    }

    if (!$links) {
        return '-';
    }

    return implode(' | ', $links);
}

/**
 * Render a job origin badge.
 *
 * @param string $origin
 * @return string
 */
function local_ncasign_render_origin_badge(string $origin): string {
    $origin = strtolower(trim($origin));
    if ($origin === \local_ncasign\local\job_manager::JOB_ORIGIN_DEMO) {
        return local_ncasign_badge(get_string('origin_demo_job', 'local_ncasign'), '#fff3cd', '#664d03');
    }
    if ($origin === \local_ncasign\local\job_manager::JOB_ORIGIN_CUSTOMCERT_ISSUE) {
        return local_ncasign_badge(get_string('origin_customcert_issue', 'local_ncasign'), '#cff4fc', '#055160');
    }

    return local_ncasign_badge(get_string('origin_course_completion', 'local_ncasign'), '#d1e7dd', '#0f5132');
}

/**
 * Render a compact badge.
 *
 * @param string $label
 * @param string $background
 * @param string $color
 * @return string
 */
function local_ncasign_badge(string $label, string $background, string $color = '#fff'): string {
    return html_writer::tag('span', s($label), [
        'style' => 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;background:' .
            $background . ';color:' . $color . ';white-space:nowrap;',
    ]);
}

/**
 * Render a job status badge.
 *
 * @param stdClass $job
 * @param int $signedcount
 * @param int $totalcount
 * @return string
 */
function local_ncasign_render_job_status_badge(\stdClass $job, int $signedcount, int $totalcount): string {
    if ($job->status === \local_ncasign\local\job_manager::JOB_COMPLETED_MANUAL) {
        return local_ncasign_badge(get_string('badgecompletedmanual', 'local_ncasign'), '#1f7a1f');
    }
    if ($job->status === \local_ncasign\local\job_manager::JOB_COMPLETED_AUTO) {
        return local_ncasign_badge(get_string('badgecompletedauto', 'local_ncasign'), '#6f42c1');
    }
    if ($job->status === \local_ncasign\local\job_manager::JOB_FINALIZE_FAILED) {
        return local_ncasign_badge(get_string('badgefinalizefailed', 'local_ncasign'), '#dc3545');
    }
    if ($signedcount > 0 && $signedcount < $totalcount) {
        return local_ncasign_badge(get_string('badgepartial', 'local_ncasign', "{$signedcount}/{$totalcount}"), '#0d6efd');
    }
    return local_ncasign_badge(get_string('badgepending', 'local_ncasign'), '#6c757d');
}
