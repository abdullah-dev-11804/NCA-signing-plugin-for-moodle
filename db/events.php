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

$observers = [
    [
        'eventname' => '\mod_customcert\event\issue_created',
        'callback' => '\local_ncasign\observer::issue_created',
        'priority' => 9999,
    ],
    // Backward-compat hook for older/forked customcert variants.
    [
        'eventname' => '\mod_customcert\event\certificate_issued',
        'callback' => '\local_ncasign\observer::certificate_issued',
        'priority' => 9999,
    ],
];
