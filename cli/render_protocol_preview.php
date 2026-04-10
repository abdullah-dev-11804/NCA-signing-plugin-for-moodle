<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'userid' => null,
        'courseid' => null,
        'templateid' => null,
        'outfile' => '',
        'verifyurl' => 'https://example.test/local/ncasign/verify.php?id=preview&hash=preview',
        'documenttimestamp' => null,
        'completiontimestamp' => null,
        'dailysequence' => null,
        'protocolnumber' => '',
        'clientcompanyoverride' => '',
        'sentalcompanyname' => '',
        'chairfull' => '',
        'userfullname' => '',
        'userjobtitle' => '',
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($options['help']) || !empty($unrecognized) || empty($options['userid']) || empty($options['courseid'])) {
    $help = "Render local_ncasign protocol preview PDF

Options:
--userid=INT               Student user id (required)
--courseid=INT             Course id (required)
--templateid=INT           Optional template profile id; otherwise first mapped active template for the course
--outfile=/path/file.pdf   Optional output path; defaults to local/ncasign/preview_output/ncasign_preview_<userid>_<courseid>.pdf
--verifyurl=URL            Optional verification URL encoded into QR previews
--documenttimestamp=INT    Optional timestamp used for protocol/certificate numbering
--completiontimestamp=INT  Optional timestamp used for issue date / completion-based fields
--dailysequence=INT        Optional zero-padded daily sequence override for protocol/certificate numbers
--protocolnumber=TEXT      Optional direct protocol number override
--clientcompanyoverride    Optional client company name override
--sentalcompanyname=TEXT   Optional Sental legal name override for commission lines
--chairfull=TEXT           Optional direct override for the chair display line
--userfullname=TEXT        Optional learner full name override
--userjobtitle=TEXT        Optional learner position override
-h, --help                 Print this help
";
    cli_writeln($help);
    exit(0);
}

$userid = (int)$options['userid'];
$courseid = (int)$options['courseid'];
$templateid = !empty($options['templateid']) ? (int)$options['templateid'] : 0;

$templatemanager = new \local_ncasign\local\template_manager();
$generator = new \local_ncasign\local\document_generator();

if ($templateid > 0) {
    $profile = $templatemanager->get_profile($templateid);
} else {
    $profiles = $templatemanager->get_course_template_profiles($courseid);
    $profile = $profiles[0] ?? null;
}

if (!$profile) {
    cli_error('No active template profile found for the requested course/template.');
}

$renderoptions = [
    'verifyurl' => (string)$options['verifyurl'],
    'signers' => is_array($profile['signers'] ?? null) ? $profile['signers'] : [],
];
if (!empty($options['documenttimestamp'])) {
    $renderoptions['documenttimestamp'] = (int)$options['documenttimestamp'];
}
if (!empty($options['completiontimestamp'])) {
    $renderoptions['completiontimestamp'] = (int)$options['completiontimestamp'];
}
if (!empty($options['dailysequence'])) {
    $renderoptions['dailysequence'] = (int)$options['dailysequence'];
}
foreach ([
    'protocolnumber',
    'clientcompanyoverride',
    'sentalcompanyname',
    'chairfull',
    'userfullname',
    'userjobtitle',
] as $key) {
    if (!empty($options[$key])) {
        $renderoptions[$key] = (string)$options[$key];
    }
}

$draft = $generator->generate_draft_from_profile($userid, $courseid, $profile, $renderoptions);

$outfile = trim((string)$options['outfile']);
if ($outfile === '') {
    $previewdir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'preview_output';
    if (!is_dir($previewdir)) {
        mkdir($previewdir, 0775, true);
    }
    $outfile = $previewdir . DIRECTORY_SEPARATOR . 'ncasign_preview_' . $userid . '_' . $courseid . '.pdf';
}

file_put_contents($outfile, $draft['content']);

cli_writeln('Preview PDF written: ' . $outfile);
cli_writeln('Protocol number: ' . (string)($draft['protocolnumber'] ?? ''));
if (!empty($draft['previewdata']) && is_array($draft['previewdata'])) {
    $previewdata = $draft['previewdata'];
    foreach ([
        'clientcompanyname' => 'Client company',
        'issuedatekz' => 'Issue date KZ',
        'issuedateru' => 'Issue date RU',
        'chairfull' => 'Commission chair',
        'userfullname' => 'Learner name',
        'userjobtitle' => 'Learner position',
    ] as $key => $label) {
        cli_writeln($label . ': ' . (string)($previewdata[$key] ?? ''));
    }
}
