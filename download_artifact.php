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

require_login();
$syscontext = context_system::instance();

$jobid = required_param('jobid', PARAM_INT);
$type = required_param('type', PARAM_ALPHA);
$signerid = optional_param('signerid', 0, PARAM_INT);

$job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', IGNORE_MISSING);
if (!$job) {
    throw new moodle_exception('invalidrecord', 'error');
}

$ismanager = has_capability('local/ncasign:managejobs', $syscontext);
$isownerstudent = ((int)$USER->id === (int)$job->userid);
$coursecontext = context_course::instance((int)$job->courseid, IGNORE_MISSING);
$isenrolledincourse = ($coursecontext && is_enrolled($coursecontext, $USER, '', true));
if (!$ismanager && !$isownerstudent && !$isenrolledincourse) {
    require_capability('local/ncasign:managejobs', $syscontext);
}

$fs = get_file_storage();
$file = null;

if ($type === 'original') {
    $files = $fs->get_area_files(
        $syscontext->id,
        'local_ncasign',
        \local_ncasign\local\job_manager::FILEAREA_ORIGINALPDF,
        $jobid,
        'id DESC',
        false
    );
    if ($files) {
        $file = reset($files);
    }
} else if ($type === 'signedpdf') {
    $files = $fs->get_area_files(
        $syscontext->id,
        'local_ncasign',
        \local_ncasign\local\job_manager::FILEAREA_SIGNEDPDF,
        $jobid,
        'id DESC',
        false
    );
    if ($files) {
        $file = reset($files);
    }
} else if ($type === 'signature' && $signerid > 0) {
    if (!$ismanager) {
        throw new moodle_exception('nopermissions', 'error', '', 'download signer CMS');
    }
    $filename = "signer_{$signerid}.p7s";
    $file = $fs->get_file(
        $syscontext->id,
        'local_ncasign',
        \local_ncasign\local\job_manager::FILEAREA_SIGNATURES,
        $jobid,
        '/',
        $filename
    );
}

if (!$file) {
    throw new moodle_exception('filenotfound');
}

send_stored_file($file, 0, 0, true);
