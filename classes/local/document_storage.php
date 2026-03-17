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

namespace local_ncasign\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Filesystem storage for generated draft documents.
 */
class document_storage {
    /**
     * Store a pending draft under moodledata/sental_docs/pending.
     *
     * @param int $jobid
     * @param string $filename
     * @param string $content
     * @return string absolute stored path
     */
    public function store_pending_draft(int $jobid, string $filename, string $content): string {
        global $CFG;

        $filename = clean_filename($filename);
        if ($filename === '') {
            $filename = 'draft_' . $jobid . '.pdf';
        }

        $relative = 'sental_docs/pending/' . date('Y/m');
        $directory = $CFG->dataroot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        check_dir_exists($directory, true, true);

        $path = $directory . DIRECTORY_SEPARATOR . 'job_' . $jobid . '_' . $filename;
        file_put_contents($path, $content);

        return $path;
    }
}
