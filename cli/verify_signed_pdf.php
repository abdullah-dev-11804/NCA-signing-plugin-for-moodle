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
        'help' => false,
        'file' => null,
    ],
    [
        'h' => 'help',
        'f' => 'file',
    ]
);

if (!empty($options['help']) || !empty($unrecognized) || empty($options['file'])) {
    $help = "Verify an embedded signed PDF using the Java PAdES sidecar + Kalkan\n\n"
        . "Options:\n"
        . "-h, --help           Print this help\n"
        . "-f, --file=PATH      Absolute or relative path to the signed PDF\n";
    cli_writeln($help);
    exit(empty($options['help']) ? 1 : 0);
}

$filepath = (string)$options['file'];
if (!is_file($filepath) || !is_readable($filepath)) {
    cli_error('PDF file not found or not readable: ' . $filepath, 1);
}

$pdfbytes = file_get_contents($filepath);
if ($pdfbytes === false || $pdfbytes === '') {
    cli_error('Failed to read PDF file: ' . $filepath, 1);
}

$backend = new \local_ncasign\local\java_sidecar_pades_finalizer();
$result = $backend->verify_pdf($pdfbytes, basename($filepath));

cli_writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
exit(0);
