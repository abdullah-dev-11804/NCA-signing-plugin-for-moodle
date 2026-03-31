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
 * Interface for signature verification backends.
 */
interface signature_backend_interface {
    /**
     * Verify detached CMS against raw document bytes.
     *
     * @param string $cmsb64
     * @param string $documentbytes
     * @param string|null $expectediin
     * @param array<string,mixed> $options
     * @return array<string, mixed>
     */
    public function verify_detached_cms(string $cmsb64, string $documentbytes, ?string $expectediin = null, array $options = []): array;
}
