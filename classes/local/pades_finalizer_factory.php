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
 * Factory for PDF finalization backends.
 */
class pades_finalizer_factory {
    /**
     * Create active finalizer backend.
     *
     * @return pades_finalizer_interface
     */
    public static function create(): pades_finalizer_interface {
        $backend = trim((string)get_config('local_ncasign', 'padesfinalizerbackend'));
        if ($backend === 'java_sidecar') {
            return new java_sidecar_pades_finalizer();
        }
        return new artifact_pdf_finalizer();
    }
}
