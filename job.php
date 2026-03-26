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
    [get_string('status', 'local_ncasign'), local_ncasign_job_status_badge($job, $signers)],
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
        local_ncasign_signer_status_badge($signer, $signers),
        $signer->expectediin ? s((string)$signer->expectediin) : '-',
        $signer->signeriin ? s((string)$signer->signeriin) : '-',
        !empty($signer->signedat) ? userdate((int)$signer->signedat) : '-',
        local_ncasign_verification_badge($signer),
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
            !empty($DB->get_field('local_ncasign_jobs', 'finalhash', ['id' => $jobid]))
                ? get_string('signedpdffinallabel', 'local_ncasign')
                : get_string('signedpdfprogresslabel', 'local_ncasign')
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
 * Render a compact badge.
 *
 * @param string $label
 * @param string $background
 * @param string $color
 * @return string
 */
function local_ncasign_job_badge(string $label, string $background, string $color = '#fff'): string {
    return html_writer::tag('span', s($label), [
        'style' => 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;background:' .
            $background . ';color:' . $color . ';white-space:nowrap;',
    ]);
}

/**
 * Render overall job status badge.
 *
 * @param stdClass $job
 * @param array $signers
 * @return string
 */
function local_ncasign_job_status_badge(\stdClass $job, array $signers): string {
    $total = count($signers);
    $signed = 0;
    foreach ($signers as $signer) {
        if ($signer->status === \local_ncasign\local\job_manager::SIGNER_SIGNED) {
            $signed++;
        }
    }
    if ($job->status === \local_ncasign\local\job_manager::JOB_COMPLETED_MANUAL) {
        return local_ncasign_job_badge(get_string('badgecompletedmanual', 'local_ncasign'), '#1f7a1f');
    }
    if ($job->status === \local_ncasign\local\job_manager::JOB_COMPLETED_AUTO) {
        return local_ncasign_job_badge(get_string('badgecompletedauto', 'local_ncasign'), '#6f42c1');
    }
    if ($signed > 0 && $signed < $total) {
        return local_ncasign_job_badge(get_string('badgepartial', 'local_ncasign', "{$signed}/{$total}"), '#0d6efd');
    }
    return local_ncasign_job_badge(get_string('badgepending', 'local_ncasign'), '#6c757d');
}

/**
 * Render signer workflow badge.
 *
 * @param stdClass $signer
 * @param array $signers
 * @return string
 */
function local_ncasign_signer_status_badge(\stdClass $signer, array $signers): string {
    if ($signer->status === \local_ncasign\local\job_manager::SIGNER_SIGNED) {
        return local_ncasign_job_badge(get_string('badgesigned', 'local_ncasign'), '#1f7a1f');
    }
    if ($signer->status === \local_ncasign\local\job_manager::SIGNER_SKIPPED) {
        return local_ncasign_job_badge(get_string('badgeskipped', 'local_ncasign'), '#6f42c1');
    }
    foreach ($signers as $candidate) {
        if ((int)$candidate->signorder < (int)$signer->signorder &&
                $candidate->status !== \local_ncasign\local\job_manager::SIGNER_SIGNED) {
            return local_ncasign_job_badge(get_string('badgewaitingprevious', 'local_ncasign'), '#adb5bd', '#212529');
        }
    }
    return local_ncasign_job_badge(get_string('badgeawaitingsignature', 'local_ncasign'), '#ffc107', '#212529');
}

/**
 * Render verification badge.
 *
 * @param stdClass $signer
 * @return string
 */
function local_ncasign_verification_badge(\stdClass $signer): string {
    $verification = local_ncasign_safe_json_decode($signer->verificationinfo ?? '');
    $validation = !empty($verification['validation']) && is_array($verification['validation']) ? $verification['validation'] : [];
    $revocations = !empty($validation['revocations']) && is_array($validation['revocations']) ? $validation['revocations'] : [];

    foreach ($revocations as $revocation) {
        if (!is_array($revocation)) {
            continue;
        }
        if (!empty($revocation['revoked'])) {
            return local_ncasign_job_badge(get_string('badgerevoked', 'local_ncasign'), '#dc3545');
        }
        $reason = (string)($revocation['reason'] ?? '');
        if ($reason !== '' && stripos($reason, 'Cannot find root certificate in NCANode') !== false) {
            return local_ncasign_job_badge(get_string('badgetrusterror', 'local_ncasign'), '#fd7e14');
        }
    }

    if (!empty($signer->verificationstatus) && $signer->verificationstatus === 'verified') {
        return local_ncasign_job_badge(get_string('badgeverified', 'local_ncasign'), '#198754');
    }
    if (!empty($signer->signeriin) && !empty($signer->expectediin) &&
            preg_replace('/\D+/', '', (string)$signer->signeriin) !== preg_replace('/\D+/', '', (string)$signer->expectediin)) {
        return local_ncasign_job_badge(get_string('badgeiinmismatch', 'local_ncasign'), '#dc3545');
    }
    if ($signer->status === \local_ncasign\local\job_manager::SIGNER_PENDING) {
        return local_ncasign_job_badge(get_string('badgepending', 'local_ncasign'), '#6c757d');
    }
    return '-';
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
