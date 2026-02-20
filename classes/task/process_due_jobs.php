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

namespace local_ncasign\task;

defined('MOODLE_INTERNAL') || die();

use local_ncasign\local\job_manager;

/**
 * Scheduled task to auto-sign overdue jobs.
 */
class process_due_jobs extends \core\task\scheduled_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('taskprocessjobs', 'local_ncasign');
    }

    /**
     * Execute task.
     *
     * @return void
     */
    public function execute(): void {
        $manager = new job_manager();
        $count = $manager->process_due_jobs(200);
        mtrace("local_ncasign: processed {$count} overdue jobs");
    }
}
