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
$lang = local_ncasign_verify_language();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ncasign/verify.php', ['id' => $documentuuid, 'hash' => $checksum, 'lang' => $lang]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(local_ncasign_verify_text('title', $lang));
$PAGE->set_heading('SENTAL');

$manager = new \local_ncasign\local\job_manager();
$expectedchecksum = $manager->get_verification_checksum($documentuuid);

echo $OUTPUT->header();
echo local_ncasign_verify_styles();
echo html_writer::start_div('local-ncasign-verify');

if (!hash_equals($expectedchecksum, $checksum)) {
    echo local_ncasign_render_language_switcher($documentuuid, $checksum, $lang);
    echo $OUTPUT->notification(local_ncasign_verify_text('invalidlink', $lang), 'notifyproblem');
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

$job = $manager->get_job_by_documentuuid($documentuuid);
if (!$job) {
    echo local_ncasign_render_language_switcher($documentuuid, $checksum, $lang);
    echo $OUTPUT->notification(local_ncasign_verify_text('notfound', $lang), 'notifyproblem');
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

$job = $manager->ensure_job_verification_metadata((int)$job->id);
if (!$job) {
    echo local_ncasign_render_language_switcher($documentuuid, $checksum, $lang);
    echo $OUTPUT->notification(local_ncasign_verify_text('notfound', $lang), 'notifyproblem');
    echo html_writer::end_div();
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
        local_ncasign_verify_text('authentic', $lang) :
        local_ncasign_verify_text('modified', $lang);
    $integrityclass = hash_equals($storedhash, $currenthash) ? 'success' : 'error';
} else {
    $integritylabel = local_ncasign_verify_text('unavailable', $lang);
    $integrityclass = 'warning';
}

echo local_ncasign_render_language_switcher($documentuuid, $checksum, $lang);
echo html_writer::start_div('ncasign-verify-hero');
echo html_writer::tag('div', local_ncasign_verify_text('title', $lang), ['class' => 'ncasign-verify-title']);
echo html_writer::tag('div', s((string)$job->documentuuid), ['class' => 'ncasign-verify-id']);
echo html_writer::end_div();
echo html_writer::div(
    html_writer::tag('strong', s($integritylabel)),
    'ncasign-status ncasign-status-' . $integrityclass
);

$documentrows = [
    local_ncasign_verify_text('publicid', $lang) => s((string)$job->documentuuid),
    local_ncasign_verify_text('documenttype', $lang) => s(ucfirst((string)$job->documenttype)),
    local_ncasign_verify_text('documenttitle', $lang) => s((string)$job->documenttitle),
    local_ncasign_verify_text('coursename', $lang) => $course ? s((string)$course->fullname) : '-',
    local_ncasign_verify_text('issuedate', $lang) => $issuedate ? local_ncasign_format_public_datetime($issuedate) : '-',
    local_ncasign_verify_text('organisation', $lang) => 'SENTAL',
];
echo html_writer::tag('h3', local_ncasign_verify_text('documentinfo', $lang));
echo local_ncasign_render_verify_table($documentrows);

$userrows = [
    local_ncasign_verify_text('fullname', $lang) => $student ? s(local_ncasign_safe_fullname($student)) : '-',
    local_ncasign_verify_text('completiondate', $lang) => $completiondate ? local_ncasign_format_public_datetime($completiondate) : '-',
];
echo html_writer::tag('h3', local_ncasign_verify_text('userinfo', $lang));
echo local_ncasign_render_verify_table($userrows);

$signers = $DB->get_records('local_ncasign_signers', ['jobid' => (int)$job->id], 'signorder ASC, id ASC');
echo html_writer::tag('h3', local_ncasign_verify_text('signatures', $lang));
if (!$signers) {
    echo html_writer::div(local_ncasign_verify_text('nosigners', $lang));
} else {
    $signercards = '';

    foreach ($signers as $index => $signer) {
        $signername = trim((string)($signer->signername ?? $signer->signeremail));
        $position = trim((string)($signer->signerposition ?? ('Commission member ' . ((int)$signer->signorder ?: ($index + 1)))));

        if (!empty($signer->signerid)) {
            $user = $DB->get_record('user', ['id' => (int)$signer->signerid], 'id,firstname,lastname,middlename,alternatename,email', IGNORE_MISSING);
            if ($user) {
                $signername = local_ncasign_safe_fullname($user);
            }
        }

        $cardrows = [
            '#' => (string)(int)($signer->signorder ?: ($index + 1)),
            local_ncasign_verify_text('fullname', $lang) => s($signername),
            local_ncasign_verify_text('position', $lang) => s($position),
        ];
        if (trim((string)$signer->status) !== 'signed_manual') {
            $cardrows[local_ncasign_verify_text('verificationstatus', $lang)] =
                s(local_ncasign_format_public_signer_status((string)$signer->status, $lang));
        }
        $cardrows[local_ncasign_verify_text('signedat', $lang)] = !empty($signer->signedat)
            ? local_ncasign_format_public_datetime((int)$signer->signedat)
            : '-';

        $signercards .= local_ncasign_render_signer_card($cardrows);
    }

    echo html_writer::div($signercards, 'ncasign-signer-grid');

    echo html_writer::tag('h3', local_ncasign_verify_text('cryptodetails', $lang));
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
            local_ncasign_verify_text('fullname', $lang) => s($signername),
            local_ncasign_verify_text('position', $lang) => s($position),
            local_ncasign_verify_text('iinidentifier', $lang) => s(local_ncasign_extract_signer_identifier($signer, $certificate)),
            local_ncasign_verify_text('certsubject', $lang) => !empty($certificate['subject']['dn']) ? s((string)$certificate['subject']['dn']) : '-',
            local_ncasign_verify_text('certserial', $lang) => !empty($certificate['serialNumber']) ? s((string)$certificate['serialNumber']) : '-',
            local_ncasign_verify_text('certperiod', $lang) => local_ncasign_format_certificate_period($certificate),
            local_ncasign_verify_text('revocationstatus', $lang) => s(local_ncasign_format_revocation_status($verification, $lang)),
            local_ncasign_verify_text('ocspcount', $lang) => (string)(int)($ocsp['count'] ?? 0),
            local_ncasign_verify_text('ocspurls', $lang) => !empty($ocsp['urls']) && is_array($ocsp['urls'])
                ? s(implode(', ', array_map('strval', $ocsp['urls'])))
                : '-',
            local_ncasign_verify_text('tsapresent', $lang) => !empty($tsa['present']) ? local_ncasign_verify_text('yes', $lang) : local_ncasign_verify_text('no', $lang),
            local_ncasign_verify_text('tsaauthority', $lang) => !empty($tsa['authority']) ? s((string)$tsa['authority']) : '-',
            local_ncasign_verify_text('tsagentime', $lang) => !empty($tsa['genTime']) ? s((string)$tsa['genTime']) : '-',
        ];

        echo html_writer::tag(
            'h4',
            s(((int)($signer->signorder ?: ($index + 1))) . '. ' . $signername),
            ['class' => 'ncasign-subheading']
        );
        echo local_ncasign_render_verify_table($rows);
    }
}

$integrityrows = [
    local_ncasign_verify_text('integrity', $lang) => s($integritylabel),
    local_ncasign_verify_text('hash', $lang) => $storedhash !== '' ? s($storedhash) : '-',
    local_ncasign_verify_text('currenthash', $lang) => $currenthash !== '' ? s($currenthash) : '-',
];
echo html_writer::tag('h3', local_ncasign_verify_text('integrity', $lang));
echo local_ncasign_render_verify_table($integrityrows);

echo html_writer::end_div();
echo $OUTPUT->footer();

/**
 * Render responsive verification page CSS.
 *
 * @return string
 */
function local_ncasign_verify_styles(): string {
    return html_writer::tag('style', '
.local-ncasign-verify {
    max-width: 980px;
    margin: 0 auto;
    padding: 0 12px 28px;
}
.local-ncasign-verify * {
    box-sizing: border-box;
}
.ncasign-verify-hero {
    padding: 18px 0 8px;
}
.ncasign-verify-title {
    font-size: 1.65rem;
    font-weight: 700;
    line-height: 1.2;
}
.ncasign-verify-id {
    margin-top: 8px;
    color: #5f6368;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    overflow-wrap: anywhere;
}
.ncasign-language-switcher {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin: 8px 0 14px;
}
.ncasign-language-switcher a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 44px;
    padding: 7px 10px;
    border: 1px solid #c7d0d9;
    border-radius: 6px;
    color: #1f2937;
    text-decoration: none;
    font-weight: 600;
}
.ncasign-language-switcher a.is-active {
    background: #1f6f4a;
    border-color: #1f6f4a;
    color: #fff;
}
.ncasign-status {
    padding: 14px 16px;
    border-radius: 8px;
    margin: 14px 0 18px;
    font-size: 1rem;
}
.ncasign-status-success {
    background: #e8f5e9;
    border: 1px solid #2e7d32;
    color: #1b5e20;
}
.ncasign-status-error {
    background: #fdecea;
    border: 1px solid #c62828;
    color: #b71c1c;
}
.ncasign-status-warning {
    background: #fff4e5;
    border: 1px solid #ef6c00;
    color: #8d4b00;
}
.local-ncasign-verify h3 {
    margin: 24px 0 10px;
    font-size: 1.18rem;
}
.ncasign-subheading {
    margin: 18px 0 8px;
    font-size: 1rem;
}
.ncasign-table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid #d9dee3;
    border-radius: 8px;
    margin-bottom: 14px;
    background: #fff;
}
.local-ncasign-verify .ncasign-verify-table {
    width: 100%;
    margin: 0;
    table-layout: fixed;
}
.local-ncasign-verify .ncasign-verify-table td {
    padding: 10px 12px;
    vertical-align: top;
    overflow-wrap: anywhere;
    word-break: break-word;
}
.local-ncasign-verify .ncasign-verify-table td:first-child {
    width: 34%;
    color: #4b5563;
}
.ncasign-signer-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 12px;
}
.ncasign-signer-card {
    border: 1px solid #d9dee3;
    border-radius: 8px;
    padding: 12px;
    background: #fff;
}
.ncasign-signer-row {
    display: grid;
    grid-template-columns: 42% 58%;
    gap: 8px;
    padding: 5px 0;
    overflow-wrap: anywhere;
}
.ncasign-signer-label {
    color: #4b5563;
    font-weight: 600;
}
@media (max-width: 640px) {
    .local-ncasign-verify {
        padding: 0 4px 22px;
    }
    .ncasign-language-switcher {
        justify-content: flex-start;
    }
    .ncasign-verify-title {
        font-size: 1.25rem;
    }
    .ncasign-status {
        padding: 12px;
        margin: 12px 0 16px;
    }
    .local-ncasign-verify h3 {
        font-size: 1.05rem;
        margin-top: 20px;
    }
    .local-ncasign-verify .ncasign-verify-table,
    .local-ncasign-verify .ncasign-verify-table tbody,
    .local-ncasign-verify .ncasign-verify-table tr,
    .local-ncasign-verify .ncasign-verify-table td {
        display: block;
        width: 100%;
    }
    .local-ncasign-verify .ncasign-verify-table tr {
        border-bottom: 1px solid #edf0f2;
        padding: 8px 0;
    }
    .local-ncasign-verify .ncasign-verify-table tr:last-child {
        border-bottom: 0;
    }
    .local-ncasign-verify .ncasign-verify-table td {
        border: 0;
        padding: 4px 10px;
    }
    .local-ncasign-verify .ncasign-verify-table td:first-child {
        width: 100%;
    }
    .ncasign-signer-grid {
        grid-template-columns: 1fr;
    }
    .ncasign-signer-row {
        grid-template-columns: 1fr;
        gap: 2px;
    }
}', ['type' => 'text/css']);
}

/**
 * Resolve selected public verification language.
 *
 * @return string
 */
function local_ncasign_verify_language(): string {
    $lang = optional_param('lang', 'kz', PARAM_ALPHA);
    return in_array($lang, ['kz', 'ru', 'en'], true) ? $lang : 'kz';
}

/**
 * Render KZ/RU/EN language switcher.
 *
 * @param string $documentuuid
 * @param string $checksum
 * @param string $currentlang
 * @return string
 */
function local_ncasign_render_language_switcher(string $documentuuid, string $checksum, string $currentlang): string {
    $links = [];
    foreach (['kz' => 'KZ', 'ru' => 'RU', 'en' => 'EN'] as $lang => $label) {
        $url = new moodle_url('/local/ncasign/verify.php', [
            'id' => $documentuuid,
            'hash' => $checksum,
            'lang' => $lang,
        ]);
        $attrs = ['class' => $lang === $currentlang ? 'is-active' : ''];
        $links[] = html_writer::link($url, $label, $attrs);
    }

    return html_writer::div(implode('', $links), 'ncasign-language-switcher');
}

/**
 * Return localised public verification page labels.
 *
 * @param string $key
 * @param string $lang
 * @return string
 */
function local_ncasign_verify_text(string $key, string $lang): string {
    $strings = [
        'kz' => [
            'title' => 'Расталған',
            'invalidlink' => 'Тексеру сілтемесі жарамсыз.',
            'notfound' => 'Сұралған құжат табылмады.',
            'authentic' => 'ТҮПНҰСҚА',
            'modified' => 'ҚҰЖАТ ӨЗГЕРТІЛГЕН',
            'unavailable' => 'ТЕКСЕРУ ҚОЛЖЕТІМСІЗ',
            'documentinfo' => 'Құжат туралы ақпарат',
            'userinfo' => 'Пайдаланушы туралы ақпарат',
            'signatures' => 'Қолтаңбалар блогы',
            'cryptodetails' => 'Қол қоюшылардың криптографиялық деректері',
            'integrity' => 'Тұтастық күйі',
            'documenttype' => 'Құжат түрі',
            'documenttitle' => 'Құжат атауы',
            'coursename' => 'Курс атауы',
            'issuedate' => 'Берілген күні',
            'organisation' => 'Беруші ұйым',
            'fullname' => 'Аты-жөні',
            'completiondate' => 'Аяқталу күні',
            'position' => 'Лауазымы',
            'iinidentifier' => 'ЖСН / субъект идентификаторы',
            'verificationstatus' => 'Тексеру мәртебесі',
            'signedat' => 'Қол қойылған уақыты',
            'hash' => 'Сақталған SHA-256',
            'currenthash' => 'Ағымдағы SHA-256',
            'nosigners' => 'Құжат үшін қол қоюшылар жазбалары табылмады.',
            'publicid' => 'Құжаттың жария ID-і',
            'certsubject' => 'Сертификат иесі',
            'certserial' => 'Сертификаттың сериялық нөмірі',
            'certperiod' => 'Сертификаттың жарамдылық мерзімі',
            'revocationstatus' => 'Қайтарып алу мәртебесі',
            'ocspcount' => 'OCSP жауаптарының саны',
            'ocspurls' => 'OCSP сервисінің URL мекенжайы',
            'tsapresent' => 'TSA уақыт белгісінің болуы',
            'tsaauthority' => 'TSA қызметі',
            'tsagentime' => 'TSA уақыты',
            'yes' => 'Иә',
            'no' => 'Жоқ',
            'status_signed' => 'Қол қойылды',
            'status_pending' => 'Күтуде',
            'status_skipped' => 'Өткізіліп жіберілді',
            'revocation_good' => 'Жарамды',
            'revocation_revoked' => 'Қайтарылған',
        ],
        'ru' => [
            'title' => 'Верифицирован',
            'invalidlink' => 'Недействительная ссылка проверки.',
            'notfound' => 'Запрошенный документ не найден.',
            'authentic' => 'ПОДЛИННЫЙ',
            'modified' => 'ДОКУМЕНТ ИЗМЕНЕН',
            'unavailable' => 'ПРОВЕРКА НЕДОСТУПНА',
            'documentinfo' => 'Информация о документе',
            'userinfo' => 'Информация о пользователе',
            'signatures' => 'Блок подписей',
            'cryptodetails' => 'Криптографические данные подписантов',
            'integrity' => 'Статус целостности',
            'documenttype' => 'Тип документа',
            'documenttitle' => 'Название документа',
            'coursename' => 'Название курса',
            'issuedate' => 'Дата выдачи',
            'organisation' => 'Организация-издатель',
            'fullname' => 'ФИО',
            'completiondate' => 'Дата завершения',
            'position' => 'Должность',
            'iinidentifier' => 'ИИН / идентификатор субъекта',
            'verificationstatus' => 'Статус проверки',
            'signedat' => 'Время подписи',
            'hash' => 'Сохраненный SHA-256',
            'currenthash' => 'Текущий SHA-256',
            'nosigners' => 'Для документа не найдено записей о подписантах.',
            'publicid' => 'Публичный ID документа',
            'certsubject' => 'Субъект сертификата',
            'certserial' => 'Серийный номер сертификата',
            'certperiod' => 'Срок действия сертификата',
            'revocationstatus' => 'Статус отзыва',
            'ocspcount' => 'Количество ответов OCSP',
            'ocspurls' => 'URL сервиса OCSP',
            'tsapresent' => 'Наличие TSA-метки времени',
            'tsaauthority' => 'Служба TSA',
            'tsagentime' => 'Время TSA',
            'yes' => 'Да',
            'no' => 'Нет',
            'status_signed' => 'Подписано',
            'status_pending' => 'Ожидается',
            'status_skipped' => 'Пропущено',
            'revocation_good' => 'Действителен',
            'revocation_revoked' => 'Отозван',
        ],
        'en' => [
            'title' => 'Verified',
            'invalidlink' => 'Invalid verification link.',
            'notfound' => 'The requested document was not found.',
            'authentic' => 'AUTHENTIC',
            'modified' => 'DOCUMENT MODIFIED',
            'unavailable' => 'VERIFICATION UNAVAILABLE',
            'documentinfo' => 'Document information',
            'userinfo' => 'User information',
            'signatures' => 'Signature block',
            'cryptodetails' => 'Signer cryptographic data',
            'integrity' => 'Integrity status',
            'documenttype' => 'Document type',
            'documenttitle' => 'Document title',
            'coursename' => 'Course name',
            'issuedate' => 'Issue date',
            'organisation' => 'Issuing organisation',
            'fullname' => 'Full name',
            'completiondate' => 'Completion date',
            'position' => 'Position',
            'iinidentifier' => 'IIN / subject identifier',
            'verificationstatus' => 'Verification status',
            'signedat' => 'Signed at',
            'hash' => 'Stored SHA-256',
            'currenthash' => 'Current SHA-256',
            'nosigners' => 'No signer records were found for this document.',
            'publicid' => 'Public document ID',
            'certsubject' => 'Certificate subject',
            'certserial' => 'Certificate serial number',
            'certperiod' => 'Certificate validity period',
            'revocationstatus' => 'Revocation status',
            'ocspcount' => 'OCSP response count',
            'ocspurls' => 'OCSP service URL',
            'tsapresent' => 'TSA timestamp present',
            'tsaauthority' => 'TSA authority',
            'tsagentime' => 'TSA time',
            'yes' => 'Yes',
            'no' => 'No',
            'status_signed' => 'Signed',
            'status_pending' => 'Pending',
            'status_skipped' => 'Skipped',
            'revocation_good' => 'Good',
            'revocation_revoked' => 'Revoked',
        ],
    ];

    return $strings[$lang][$key] ?? $strings['kz'][$key] ?? $key;
}

/**
 * Render a responsive verification details table.
 *
 * @param array $rows
 * @return string
 */
function local_ncasign_render_verify_table(array $rows): string {
    return html_writer::div(html_writer::table(local_ncasign_build_verify_table($rows)), 'ncasign-table-wrap');
}

/**
 * Render a signer summary card.
 *
 * @param array $rows
 * @return string
 */
function local_ncasign_render_signer_card(array $rows): string {
    $content = '';
    foreach ($rows as $label => $value) {
        $content .= html_writer::div(
            html_writer::div(s((string)$label), 'ncasign-signer-label') .
            html_writer::div($value, 'ncasign-signer-value'),
            'ncasign-signer-row'
        );
    }

    return html_writer::div($content, 'ncasign-signer-card');
}

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
 * Format public timestamps without language-specific month names.
 *
 * @param int $timestamp
 * @return string
 */
function local_ncasign_format_public_datetime(int $timestamp): string {
    return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '-';
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
function local_ncasign_format_revocation_status(array $verification, string $lang): string {
    $ocsp = !empty($verification['ocsp']) && is_array($verification['ocsp']) ? $verification['ocsp'] : [];
    if (!empty($ocsp['details']) && is_array($ocsp['details'])) {
        $statuses = [];
        foreach ($ocsp['details'] as $detail) {
            if (!is_array($detail) || empty($detail['status'])) {
                continue;
            }
            $status = strtolower((string)$detail['status']);
            if ($status === 'good') {
                $status = local_ncasign_verify_text('revocation_good', $lang);
            } else if ($status === 'revoked') {
                $status = local_ncasign_verify_text('revocation_revoked', $lang);
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
            $line .= !empty($revocation['revoked'])
                ? local_ncasign_verify_text('revocation_revoked', $lang)
                : local_ncasign_verify_text('revocation_good', $lang);
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
function local_ncasign_format_public_signer_status(string $status, string $lang): string {
    return match (trim($status)) {
        'signed' => local_ncasign_verify_text('status_signed', $lang),
        'pending' => local_ncasign_verify_text('status_pending', $lang),
        'skipped' => local_ncasign_verify_text('status_skipped', $lang),
        default => $status !== '' ? $status : '-',
    };
}
