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

$jobid = required_param('id', PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/ncasign:managejobs', $context);

$url = new moodle_url('/local/ncasign/job.php', ['id' => $jobid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('jobdetails', 'local_ncasign'));
$PAGE->set_heading(get_string('jobdetails', 'local_ncasign'));

$manager = new \local_ncasign\local\job_manager();
$job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', MUST_EXIST);
$signers = $manager->get_signer_records($jobid);

echo $OUTPUT->header();
echo $OUTPUT->single_button(new moodle_url('/local/ncasign/index.php'), get_string('backtojobs', 'local_ncasign'));

$summary = new html_table();
$summary->attributes['class'] = 'generaltable';
$summary->data = [
    [get_string('jobidlabel', 'local_ncasign'), (int)$job->id],
    [get_string('documenttitle', 'local_ncasign'), s((string)$job->documenttitle)],
    [get_string('documenttype', 'local_ncasign'), s((string)$job->documenttype)],
    [get_string('courseid', 'local_ncasign'), (int)$job->courseid],
    [get_string('userid', 'local_ncasign'), (int)$job->userid],
    [get_string('status', 'local_ncasign'), s((string)$job->status)],
    [get_string('deadline', 'local_ncasign'), userdate((int)$job->manualdeadline)],
    [get_string('templateprofile', 'local_ncasign'), !empty($job->templateprofileid) ? (int)$job->templateprofileid : '-'],
    [get_string('jobdrafthash', 'local_ncasign'), !empty($job->drafthash) ? s((string)$job->drafthash) : '-'],
    [get_string('jobfinalhash', 'local_ncasign'), !empty($job->finalhash) ? s((string)$job->finalhash) : '-'],
    [get_string('artifacts', 'local_ncasign'), local_ncasign_job_render_artifacts($jobid)],
];

echo html_writer::tag('h3', get_string('jobsummary', 'local_ncasign'));
echo html_writer::table($summary);

$table = new html_table();
$table->head = [
    get_string('signerorder', 'local_ncasign'),
    get_string('signername', 'local_ncasign'),
    get_string('signeremail', 'local_ncasign'),
    get_string('verifyposition', 'local_ncasign'),
    get_string('status', 'local_ncasign'),
    get_string('expectediinlabel', 'local_ncasign'),
    get_string('actualiinlabel', 'local_ncasign'),
    get_string('signedatlabel', 'local_ncasign'),
    get_string('verificationstatuslabel', 'local_ncasign'),
    get_string('signingmethodlabel', 'local_ncasign'),
    get_string('verificationdetails', 'local_ncasign'),
];

foreach ($signers as $signer) {
    $details = local_ncasign_render_signer_verification_details($signer);
    $table->data[] = [
        (int)$signer->signorder,
        s((string)($signer->signername ?? '')),
        s((string)$signer->signeremail),
        s((string)($signer->signerposition ?? '')),
        s((string)$signer->status),
        $signer->expectediin ? s((string)$signer->expectediin) : '-',
        $signer->signeriin ? s((string)$signer->signeriin) : '-',
        !empty($signer->signedat) ? userdate((int)$signer->signedat) : '-',
        !empty($signer->verificationstatus) ? s((string)$signer->verificationstatus) : '-',
        !empty($signer->signingmethod) ? s((string)$signer->signingmethod) : '-',
        $details,
    ];
}

echo html_writer::tag('h3', get_string('verifysignatures', 'local_ncasign'));
echo html_writer::table($table);
echo $OUTPUT->footer();

/**
 * Render artifact links for a signing job.
 *
 * @param int $jobid
 * @return string
 */
function local_ncasign_job_render_artifacts(int $jobid): string {
    global $DB;

    $manager = new \local_ncasign\local\job_manager();
    $links = [];
    if ($manager->has_job_original_pdf($jobid)) {
        $links[] = html_writer::link(
            new moodle_url('/local/ncasign/download_artifact.php', ['jobid' => $jobid, 'type' => 'original']),
            'Original PDF'
        );
    }
    if ($manager->has_job_signed_pdf($jobid)) {
        $links[] = html_writer::link(
            new moodle_url('/local/ncasign/download_artifact.php', ['jobid' => $jobid, 'type' => 'signedpdf']),
            'Signed PDF (QR blocks)'
        );
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
        $links[] = html_writer::link(
            new moodle_url('/local/ncasign/download_artifact.php', [
                'jobid' => $jobid,
                'type' => 'signature',
                'signerid' => (int)$signer->id,
            ]),
            'CMS signer #' . (int)$signer->signorder
        );
    }

    return $links ? implode(' | ', $links) : '-';
}

/**
 * Decode JSON safely.
 *
 * @param mixed $value
 * @return array
 */
function local_ncasign_safe_json_decode($value): array {
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Render signer verification details for admin view.
 *
 * @param stdClass $signer
 * @return string
 */
function local_ncasign_render_signer_verification_details(\stdClass $signer): string {
    $verification = local_ncasign_safe_json_decode($signer->verificationinfo ?? '');
    $signmeta = local_ncasign_safe_json_decode($signer->signmeta ?? '');
    $certificate = [];
    if (!empty($verification['certificateinfo']) && is_array($verification['certificateinfo'])) {
        $certificate = $verification['certificateinfo'];
    } else if (!empty($signer->signercertificate)) {
        $certificate = local_ncasign_safe_json_decode($signer->signercertificate);
    }
    $validation = !empty($verification['validation']) && is_array($verification['validation']) ? $verification['validation'] : [];
    $revocations = !empty($validation['revocations']) && is_array($validation['revocations']) ? $validation['revocations'] : [];

    $items = [];
    if (!empty($certificate['subject']['dn'])) {
        $items[] = html_writer::tag('div', html_writer::tag('strong', get_string('certsubjectlabel', 'local_ncasign') . ': ') . s((string)$certificate['subject']['dn']));
    }
    if (!empty($certificate['serialNumber'])) {
        $items[] = html_writer::tag('div', html_writer::tag('strong', get_string('certseriallabel', 'local_ncasign') . ': ') . s((string)$certificate['serialNumber']));
    }
    if (!empty($certificate['keyUsage'])) {
        $items[] = html_writer::tag('div', html_writer::tag('strong', get_string('certkeyusagelabel', 'local_ncasign') . ': ') . s((string)$certificate['keyUsage']));
    }
    if (!empty($certificate['notBefore']) || !empty($certificate['notAfter'])) {
        $period = s((string)($certificate['notBefore'] ?? '-')) . ' -> ' . s((string)($certificate['notAfter'] ?? '-'));
        $items[] = html_writer::tag('div', html_writer::tag('strong', get_string('certperiodlabel', 'local_ncasign') . ': ') . $period);
    }
    if (!empty($signmeta['payload_sha256'])) {
        $items[] = html_writer::tag('div', html_writer::tag('strong', get_string('payloadsha256label', 'local_ncasign') . ': ') . s((string)$signmeta['payload_sha256']));
    }
    if (!empty($signmeta['cms_sha256'])) {
        $items[] = html_writer::tag('div', html_writer::tag('strong', get_string('cmssha256label', 'local_ncasign') . ': ') . s((string)$signmeta['cms_sha256']));
    }
    if (!empty($verification['signingtime'])) {
        $items[] = html_writer::tag('div', html_writer::tag('strong', get_string('signingtimelabel', 'local_ncasign') . ': ') . s((string)$verification['signingtime']));
    }
    if (!empty($verification['verifyinfo'])) {
        $items[] = html_writer::tag('div', html_writer::tag('strong', get_string('verifyinfolabel', 'local_ncasign') . ': ') . s((string)$verification['verifyinfo']));
    }
    if ($revocations) {
        $revlines = [];
        foreach ($revocations as $revocation) {
            if (!is_array($revocation)) {
                continue;
            }
            $revlines[] = s((string)($revocation['by'] ?? 'UNKNOWN'))
                . ': revoked=' . (!empty($revocation['revoked']) ? 'yes' : 'no')
                . (!empty($revocation['reason']) ? ', reason=' . s((string)$revocation['reason']) : '');
        }
        if ($revlines) {
            $items[] = html_writer::tag('div', html_writer::tag('strong', get_string('revocationslabel', 'local_ncasign') . ': ') . implode(html_writer::empty_tag('br'), $revlines));
        }
    }

    if (!$items) {
        return '-';
    }
    return implode('', $items);
}
