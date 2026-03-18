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

$token = required_param('token', PARAM_ALPHANUMEXT);
$type = optional_param('type', 'original', PARAM_ALPHA);
$manager = new \local_ncasign\local\job_manager();
$row = $manager->get_signer_by_token($token);

if (!$row) {
    throw new moodle_exception('invalidtoken', 'local_ncasign');
}

$jobid = (int)$row['job']->id;
$file = null;
if ($type === 'signedpdf') {
    $context = context_system::instance();
    $fs = get_file_storage();
    $files = $fs->get_area_files(
        $context->id,
        'local_ncasign',
        \local_ncasign\local\job_manager::FILEAREA_SIGNEDPDF,
        $jobid,
        'id DESC',
        false
    );
    if ($files) {
        $file = reset($files);
    }
} else {
    $file = $manager->get_job_original_file($jobid);
}

if (!$file) {
    throw new moodle_exception('filenotfound');
}

send_stored_file($file, 0, 0, true);
