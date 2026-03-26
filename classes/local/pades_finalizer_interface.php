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
 * Contract for detached CMS -> final PDF finalization backends.
 *
 * Input context contract for finalize():
 * - job: \stdClass signing job record
 * - originalpdf: string original draft PDF bytes
 * - originalfilename: string
 * - originalsha256: string SHA-256 of original draft bytes
 * - verifyurl: string public verification URL
 * - signers: array<int,\stdClass> signer job records including raw CMS/evidence
 * - completedsignerblocks: array<int,array<string,string>> current visible QR/signature blocks
 * - manifest: array<string,mixed> reserved signature slot/finalization metadata
 * - isfinal: bool whether all manual signers have completed
 *
 * Output contract:
 * - filename: string
 * - content: string PDF bytes
 * - source: string file source label
 * - backend: string backend name
 * - mode: string backend mode, e.g. artifact_pdf or embedded_pades
 * - supports_embedded_pades: bool
 * - finalhash: string|null SHA-256 of produced final PDF when applicable
 * - evidence: array<string,mixed> backend-specific evidence/meta
 */
interface pades_finalizer_interface {
    /**
     * Return backend identifier.
     *
     * @return string
     */
    public function get_backend_name(): string;

    /**
     * Whether backend produces real embedded PAdES output.
     *
     * @return bool
     */
    public function supports_embedded_pades(): bool;

    /**
     * Finalize current signing stage into a PDF artifact.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function finalize(array $context): array;

    /**
     * Describe the exact backend capability still required for true embedded PAdES-LT.
     *
     * @return array<int,string>
     */
    public function get_required_embedding_capabilities(): array;
}
