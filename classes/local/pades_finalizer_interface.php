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
 * Input context contract for prepare():
 * - job: \stdClass signing job record
 * - originalpdf: string current draft/progress PDF bytes
 * - originalfilename: string
 * - originalsha256: string SHA-256 of current signable PDF bytes
 * - manifest: array<string,mixed> reserved signature slot/finalization metadata
 * - signer: \stdClass active signer record
 * - signers: array<int,\stdClass> signer job records including raw CMS/evidence
 *
 * Output contract for prepare():
 * - sessionid: string backend prepare session identifier
 * - fieldname: string signature field/slot to be signed
 * - signablepayloadb64: string base64 signable payload/digest that the desktop signer must sign
 * - signablepayloadsha256: string SHA-256 of signablepayloadb64 decoded bytes
 * - payloadmode: string raw_pdf_bytes|prepared_pdf_digest|prepared_pdf_dtbs
 * - signingtime: string|null backend-controlled signing time that finalize() must reuse
 * - backend: string backend name
 * - evidence: array<string,mixed>
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
     * Whether backend supports a two-phase prepare/finalize flow for true PDF signatures.
     *
     * @return bool
     */
    public function supports_prepare_phase(): bool;

    /**
     * Prepare a specific signer slot and return the exact payload/digest that must be signed.
     *
     * A real embedded-PAdES backend generally cannot use "raw current PDF bytes" as the signable
     * payload. It must first prepare a PDF revision/signature field and then return the prepared
     * digest or DTBS bytes to the desktop signer.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function prepare(array $context): array;

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
