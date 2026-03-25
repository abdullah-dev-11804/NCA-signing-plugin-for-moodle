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
        'cmsfile' => '',
        'datafile' => '',
        'expectediin' => '',
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($options['help']) || !empty($unrecognized)) {
    $help = "Test NCANode health or CMS verification

Options:
--cmsfile=PATH       Base64 CMS file to verify
--datafile=PATH      Raw data/PDF file used for detached verification
--expectediin=IIN    Optional expected signer IIN
-h, --help           Print this help

Without --cmsfile/--datafile this script only checks /actuator/health.
";
    cli_writeln($help);
    exit(0);
}

$backend = new \local_ncasign\local\ncanode_signature_backend();

if (empty($options['cmsfile']) || empty($options['datafile'])) {
    cli_writeln(json_encode($backend->healthcheck(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    exit(0);
}

$cms = file_get_contents((string)$options['cmsfile']);
$data = file_get_contents((string)$options['datafile']);
if ($cms === false || $data === false) {
    cli_error('Unable to read --cmsfile or --datafile.');
}

$result = $backend->verify_detached_cms((string)$cms, $data, (string)$options['expectediin']);
cli_writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
