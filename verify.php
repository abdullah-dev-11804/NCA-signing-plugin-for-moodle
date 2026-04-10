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

$documentuuid = required_param('id', PARAM_ALPHANUMEXT);
$checksum = required_param('hash', PARAM_ALPHANUM);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ncasign/verify.php', ['id' => $documentuuid, 'hash' => $checksum]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('verifytitle', 'local_ncasign'));
$PAGE->set_heading(format_string($SITE->fullname));

$manager = new \local_ncasign\local\job_manager();
$expectedchecksum = $manager->get_verification_checksum($documentuuid);

echo $OUTPUT->header();

if (!hash_equals($expectedchecksum, $checksum)) {
    echo $OUTPUT->notification(get_string('verifyinvalidlink', 'local_ncasign'), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

$job = $manager->get_job_by_documentuuid($documentuuid);
if (!$job) {
    echo $OUTPUT->notification(get_string('verifynotfound', 'local_ncasign'), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

$job = $manager->ensure_job_verification_metadata((int)$job->id);
if (!$job) {
    echo $OUTPUT->notification(get_string('verifynotfound', 'local_ncasign'), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

$course = $DB->get_record('course', ['id' => (int)$job->courseid], 'id,fullname', IGNORE_MISSING);
$student = $DB->get_record('user', ['id' => (int)$job->userid], 'id,firstname,lastname,middlename,alternatename', IGNORE_MISSING);
$coursecontext = context_course::instance((int)$job->courseid, IGNORE_MISSING);

$completiondate = (int)$DB->get_field('course_completions', 'timecompleted', [
    'course' => (int)$job->courseid,
    'userid' => (int)$job->userid,
], IGNORE_MISSING);

$issuedate = (int)$job->timecreated;
$issue = $DB->get_record_sql(
    "SELECT ci.timecreated
       FROM {customcert_issues} ci
       JOIN {customcert} c ON c.id = ci.customcertid
      WHERE c.course = :courseid
        AND ci.userid = :userid
   ORDER BY ci.timecreated DESC, ci.id DESC",
    ['courseid' => (int)$job->courseid, 'userid' => (int)$job->userid],
    IGNORE_MULTIPLE
);
if ($issue && !empty($issue->timecreated)) {
    $issuedate = (int)$issue->timecreated;
}

$signedpdf = $manager->get_job_signed_pdf_binary((int)$job->id);
$storedhash = (string)($job->finalhash ?? '');
$currenthash = $signedpdf['sha256'] ?? '';
if ($storedhash !== '' && $currenthash !== '') {
    $integritylabel = hash_equals($storedhash, $currenthash) ?
        get_string('verifyauthentic', 'local_ncasign') :
        get_string('verifymodified', 'local_ncasign');
    $integrityclass = hash_equals($storedhash, $currenthash) ? 'success' : 'error';
} else {
    $integritylabel = get_string('verifyunavailable', 'local_ncasign');
    $integrityclass = 'warning';
}

$stylemap = [
    'success' => 'background:#e8f5e9;border:1px solid #2e7d32;color:#1b5e20;padding:16px;border-radius:6px;margin:16px 0;',
    'error' => 'background:#fdecea;border:1px solid #c62828;color:#b71c1c;padding:16px;border-radius:6px;margin:16px 0;',
    'warning' => 'background:#fff4e5;border:1px solid #ef6c00;color:#8d4b00;padding:16px;border-radius:6px;margin:16px 0;',
];

echo html_writer::tag('h2', get_string('verifytitle', 'local_ncasign'));
echo html_writer::div(
    html_writer::tag('strong', s($integritylabel)),
    '',
    ['style' => $stylemap[$integrityclass]]
);

$documentrows = [
    get_string('verifypublicid', 'local_ncasign') => s((string)$job->documentuuid),
    get_string('verifydocumenttype', 'local_ncasign') => s(ucfirst((string)$job->documenttype)),
    get_string('verifydocumenttitle', 'local_ncasign') => s((string)$job->documenttitle),
    get_string('verifycoursename', 'local_ncasign') => $course ? s((string)$course->fullname) : '-',
    get_string('verifyissuedate', 'local_ncasign') => $issuedate ? userdate($issuedate) : '-',
    get_string('verifyorganisation', 'local_ncasign') => format_string($SITE->fullname, true, ['context' => $context]),
];
echo html_writer::tag('h3', get_string('verifydocumentinfo', 'local_ncasign'));
echo html_writer::table(local_ncasign_build_verify_table($documentrows));

$userrows = [
    get_string('verifyfullname', 'local_ncasign') => $student ? s(local_ncasign_safe_fullname($student)) : '-',
    get_string('verifycompletiondate', 'local_ncasign') => $completiondate ? userdate($completiondate) : '-',
];
echo html_writer::tag('h3', get_string('verifyuserinfo', 'local_ncasign'));
echo html_writer::table(local_ncasign_build_verify_table($userrows));

$signers = $DB->get_records('local_ncasign_signers', ['jobid' => (int)$job->id], 'signorder ASC, id ASC');
echo html_writer::tag('h3', get_string('verifysignatures', 'local_ncasign'));
if (!$signers) {
    echo html_writer::div(get_string('verifynosigners', 'local_ncasign'));
} else {
    $table = new html_table();
    $table->head = [
        '#',
        get_string('verifyfullname', 'local_ncasign'),
        get_string('verifyposition', 'local_ncasign'),
        get_string('verificationstatuslabel', 'local_ncasign'),
        get_string('verifysignedat', 'local_ncasign'),
    ];

    foreach ($signers as $index => $signer) {
        $signername = trim((string)($signer->signername ?? $signer->signeremail));
        $position = trim((string)($signer->signerposition ?? ('Commission member ' . ((int)$signer->signorder ?: ($index + 1)))));

        if (!empty($signer->signerid)) {
            $user = $DB->get_record('user', ['id' => (int)$signer->signerid], 'id,firstname,lastname,middlename,alternatename,email', IGNORE_MISSING);
            if ($user) {
                $signername = local_ncasign_safe_fullname($user);
            }
        }

        $table->data[] = [
            (int)($signer->signorder ?: ($index + 1)),
            s($signername),
            s($position),
            s(local_ncasign_format_public_signer_status((string)$signer->status)),
            !empty($signer->signedat) ? userdate((int)$signer->signedat) : '-',
        ];
    }

    echo html_writer::table($table);

    echo html_writer::tag('h3', get_string('verifycryptodetails', 'local_ncasign'));
    foreach ($signers as $index => $signer) {
        $signername = trim((string)($signer->signername ?? $signer->signeremail));
        $position = trim((string)($signer->signerposition ?? ('Commission member ' . ((int)$signer->signorder ?: ($index + 1)))));
        if (!empty($signer->signerid)) {
            $user = $DB->get_record('user', ['id' => (int)$signer->signerid], 'id,firstname,lastname,middlename,alternatename,email', IGNORE_MISSING);
            if ($user) {
                $signername = local_ncasign_safe_fullname($user);
            }
        }

        $verification = local_ncasign_safe_json_decode($signer->verificationinfo ?? '');
        $certificate = [];
        if (!empty($verification['certificateinfo']) && is_array($verification['certificateinfo'])) {
            $certificate = $verification['certificateinfo'];
        } else if (!empty($signer->signercertificate)) {
            $certificate = local_ncasign_safe_json_decode($signer->signercertificate ?? '');
        }
        $ocsp = !empty($verification['ocsp']) && is_array($verification['ocsp']) ? $verification['ocsp'] : [];
        $tsa = !empty($verification['tsa']) && is_array($verification['tsa']) ? $verification['tsa'] : [];

        $rows = [
            get_string('verifyfullname', 'local_ncasign') => s($signername),
            get_string('verifyposition', 'local_ncasign') => s($position),
            get_string('verifyiinidentifierlabel', 'local_ncasign') => s(local_ncasign_extract_signer_identifier($signer, $certificate)),
            get_string('certsubjectlabel', 'local_ncasign') => !empty($certificate['subject']['dn']) ? s((string)$certificate['subject']['dn']) : '-',
            get_string('certseriallabel', 'local_ncasign') => !empty($certificate['serialNumber']) ? s((string)$certificate['serialNumber']) : '-',
            get_string('certperiodlabel', 'local_ncasign') => local_ncasign_format_certificate_period($certificate),
            get_string('revocationstatuslabel', 'local_ncasign') => s(local_ncasign_format_revocation_status($verification)),
            get_string('ocspcountlabel', 'local_ncasign') => (string)(int)($ocsp['count'] ?? 0),
            get_string('ocspurlslabel', 'local_ncasign') => !empty($ocsp['urls']) && is_array($ocsp['urls'])
                ? s(implode(', ', array_map('strval', $ocsp['urls'])))
                : '-',
            get_string('tsapresentlabel', 'local_ncasign') => !empty($tsa['present']) ? get_string('yes') : get_string('no'),
            get_string('tsaauthoritylabel', 'local_ncasign') => !empty($tsa['authority']) ? s((string)$tsa['authority']) : '-',
            get_string('tsagentimelabel', 'local_ncasign') => !empty($tsa['genTime']) ? s((string)$tsa['genTime']) : '-',
        ];

        echo html_writer::tag(
            'h4',
            s(((int)($signer->signorder ?: ($index + 1))) . '. ' . $signername),
            ['style' => 'margin-top:20px;']
        );
        echo html_writer::table(local_ncasign_build_verify_table($rows));
    }
}

$integrityrows = [
    get_string('verifyintegrity', 'local_ncasign') => s($integritylabel),
    get_string('verifyhash', 'local_ncasign') => $storedhash !== '' ? s($storedhash) : '-',
    get_string('verifycurrenthash', 'local_ncasign') => $currenthash !== '' ? s($currenthash) : '-',
];
echo html_writer::tag('h3', get_string('verifyintegrity', 'local_ncasign'));
echo html_writer::table(local_ncasign_build_verify_table($integrityrows));

echo $OUTPUT->footer();

/**
 * Render a simple two-column verification details table.
 *
 * @param array $rows
 * @return html_table
 */
function local_ncasign_build_verify_table(array $rows): html_table {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';

    foreach ($rows as $label => $value) {
        $table->data[] = [
            html_writer::tag('strong', $label),
            $value,
        ];
    }

    return $table;
}

/**
 * Build a safe display name without calling fullname() on partial records.
 *
 * @param stdClass $user
 * @return string
 */
function local_ncasign_safe_fullname(stdClass $user): string {
    $parts = [];
    foreach (['firstname', 'middlename', 'lastname'] as $field) {
        if (!empty($user->{$field})) {
            $parts[] = trim((string)$user->{$field});
        }
    }

    if (!$parts && !empty($user->alternatename)) {
        $parts[] = trim((string)$user->alternatename);
    }

    return $parts ? implode(' ', $parts) : '-';
}

/**
 * Decode JSON into array safely.
 *
 * @param mixed $value
 * @return array
 */
function local_ncasign_safe_json_decode($value): array {
    if (empty($value) || !is_string($value)) {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Extract signer subject identifier from stored signer and certificate evidence.
 *
 * @param stdClass $signer
 * @param array $certificate
 * @return string
 */
function local_ncasign_extract_signer_identifier(stdClass $signer, array $certificate): string {
    if (!empty($signer->signeriin)) {
        return (string)$signer->signeriin;
    }

    $subjectdn = '';
    if (!empty($certificate['subject']['dn']) && is_string($certificate['subject']['dn'])) {
        $subjectdn = $certificate['subject']['dn'];
    }

    if ($subjectdn !== '') {
        if (preg_match('/serialNumber=([^,]+)/i', $subjectdn, $matches)) {
            return trim((string)$matches[1]);
        }
        if (preg_match('/2\\.5\\.4\\.5=#([0-9A-Fa-f]+)/', $subjectdn, $matches)) {
            $decoded = @hex2bin((string)$matches[1]);
            if ($decoded !== false && preg_match('/IIN\\d+/i', $decoded, $iinmatch)) {
                return (string)$iinmatch[0];
            }
        }
    }

    return '-';
}

/**
 * Format certificate validity.
 *
 * @param array $certificate
 * @return string
 */
function local_ncasign_format_certificate_period(array $certificate): string {
    if (empty($certificate['notBefore']) && empty($certificate['notAfter'])) {
        return '-';
    }
    return trim((string)($certificate['notBefore'] ?? '-')) . ' -> ' . trim((string)($certificate['notAfter'] ?? '-'));
}

/**
 * Format revocation status from stored verification evidence.
 *
 * @param array $verification
 * @return string
 */
function local_ncasign_format_revocation_status(array $verification): string {
    $ocsp = !empty($verification['ocsp']) && is_array($verification['ocsp']) ? $verification['ocsp'] : [];
    if (!empty($ocsp['details']) && is_array($ocsp['details'])) {
        $statuses = [];
        foreach ($ocsp['details'] as $detail) {
            if (!is_array($detail) || empty($detail['status'])) {
                continue;
            }
            $status = strtolower((string)$detail['status']);
            if ($status === 'good') {
                $status = 'good / действителен';
            } else if ($status === 'revoked') {
                $status = 'revoked / отозван';
            }
            $line = 'OCSP: ' . $status;
            if (!empty($detail['thisUpdate'])) {
                $line .= ' (' . (string)$detail['thisUpdate'] . ')';
            }
            $statuses[] = $line;
        }
        if ($statuses) {
            return implode('; ', $statuses);
        }
    }

    $validation = !empty($verification['validation']) && is_array($verification['validation']) ? $verification['validation'] : [];
    if (!empty($validation['revocations']) && is_array($validation['revocations'])) {
        $statuses = [];
        foreach ($validation['revocations'] as $revocation) {
            if (!is_array($revocation)) {
                continue;
            }
            $line = !empty($revocation['by']) ? (string)$revocation['by'] . ': ' : '';
            $line .= !empty($revocation['revoked']) ? 'revoked' : 'good';
            if (!empty($revocation['reason'])) {
                $line .= ' (' . (string)$revocation['reason'] . ')';
            }
            $statuses[] = $line;
        }
        if ($statuses) {
            return implode('; ', $statuses);
        }
    }

    return '-';
}

/**
 * Format signer workflow status for the public verification page.
 *
 * @param string $status
 * @return string
 */
function local_ncasign_format_public_signer_status(string $status): string {
    return match (trim($status)) {
        'signed' => 'подписано / қол қойылды',
        'pending' => 'ожидается / күтуде',
        'skipped' => 'пропущено / өткізіліп жіберілді',
        default => $status !== '' ? $status : '-',
    };
}
