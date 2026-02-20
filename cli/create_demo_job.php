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

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'userid' => null,
        'courseid' => null,
        'emails' => '',
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($options['help']) || !empty($unrecognized) || empty($options['userid']) || empty($options['courseid'])) {
    $help = "Create local_ncasign demo job

Options:
--userid=INT       Student user id (required)
--courseid=INT     Course id (required)
--emails=CSV       Optional signer emails, comma separated
-h, --help         Print this help
";
    cli_writeln($help);
    exit(0);
}

$userid = (int)$options['userid'];
$courseid = (int)$options['courseid'];
$emails = array_filter(array_map('trim', explode(',', (string)$options['emails'])));

$manager = new \local_ncasign\local\job_manager();
$certurl = $manager->build_certificate_url($courseid, $userid);
$signers = [];
foreach ($emails as $email) {
    if (validate_email($email)) {
        $signers[] = ['email' => $email, 'name' => $email];
    }
}

if (!$signers) {
    $context = context_course::instance($courseid);
    $signers = $manager->get_signers_from_configured_roles($context);
}

$jobid = $manager->create_job($userid, $courseid, $certurl, $signers);
cli_writeln("Created local_ncasign job: {$jobid}");
