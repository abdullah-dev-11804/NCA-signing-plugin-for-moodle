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

defined('MOODLE_INTERNAL') || die();

/**
 * Public helper for other plugins to queue signing jobs.
 *
 * @param int $userid student id
 * @param int $courseid course id
 * @param string $certificateurl certificate URL/path
 * @param array $signers signer descriptors
 * @return int job id
 */
function local_ncasign_queue_certificate_signing(
    int $userid,
    int $courseid,
    string $certificateurl,
    array $signers
): int {
    $manager = new \local_ncasign\local\job_manager();
    return $manager->create_job($userid, $courseid, $certificateurl, $signers);
}
