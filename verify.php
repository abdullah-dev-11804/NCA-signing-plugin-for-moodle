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
$student = $DB->get_record('user', ['id' => (int)$job->userid], 'id,firstname,lastname', IGNORE_MISSING);
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
    get_string('verifyfullname', 'local_ncasign') => $student ? s(fullname($student)) : '-',
    get_string('verifycompletiondate', 'local_ncasign') => $completiondate ? userdate($completiondate) : '-',
];
echo html_writer::tag('h3', get_string('verifyuserinfo', 'local_ncasign'));
echo html_writer::table(local_ncasign_build_verify_table($userrows));

$signers = $DB->get_records('local_ncasign_signers', ['jobid' => (int)$job->id], 'id ASC');
echo html_writer::tag('h3', get_string('verifysignatures', 'local_ncasign'));
if (!$signers) {
    echo html_writer::div(get_string('verifynosigners', 'local_ncasign'));
} else {
    $table = new html_table();
    $table->head = [
        '#',
        get_string('verifyfullname', 'local_ncasign'),
        get_string('verifyposition', 'local_ncasign'),
        get_string('status', 'local_ncasign'),
        get_string('verifysignedat', 'local_ncasign'),
    ];

    foreach ($signers as $index => $signer) {
        $signername = trim((string)$signer->signeremail);
        $position = 'Commission member';

        if (!empty($signer->signerid)) {
            $user = $DB->get_record('user', ['id' => (int)$signer->signerid], 'id,firstname,lastname,email', IGNORE_MISSING);
            if ($user) {
                $signername = fullname($user);
            }

            if ($coursecontext) {
                $roles = get_user_roles($coursecontext, (int)$signer->signerid, false);
                $rolenames = [];
                foreach ($roles as $roleassignment) {
                    if (!empty($roleassignment->shortname)) {
                        $rolenames[$roleassignment->shortname] = role_get_name($roleassignment, $coursecontext);
                    } else if (!empty($roleassignment->name)) {
                        $rolenames[$roleassignment->name] = $roleassignment->name;
                    }
                }
                if ($rolenames) {
                    $position = implode(', ', array_values($rolenames));
                }
            }
        }

        $table->data[] = [
            (int)$index + 1,
            s($signername),
            s($position),
            s((string)$signer->status),
            !empty($signer->signedat) ? userdate((int)$signer->signedat) : '-',
        ];
    }

    echo html_writer::table($table);
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
