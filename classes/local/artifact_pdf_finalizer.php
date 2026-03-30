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
 * Current non-PAdES PDF finalizer.
 *
 * Produces a progress/final artifact PDF with QR blocks and embedded evidence pages.
 * This backend does not embed CMS into PDF signatures; it exists so the workflow layer
 * can later swap to a real embedded-PAdES backend without rewriting orchestration.
 */
class artifact_pdf_finalizer implements pades_finalizer_interface {
    /**
     * @inheritDoc
     */
    public function get_backend_name(): string {
        return 'artifact_pdf_finalizer';
    }

    /**
     * @inheritDoc
     */
    public function supports_embedded_pades(): bool {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function supports_prepare_phase(): bool {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function prepare(array $context): array {
        throw new \coding_exception(
            'artifact_pdf_finalizer does not support a PAdES prepare/finalize flow. ' .
            'Use java_sidecar finalizer with an external PDF-signing backend.'
        );
    }

    /**
     * @inheritDoc
     */
    public function finalize(array $context): array {
        global $CFG;

        $job = $context['job'];
        $originalpdf = (string)$context['originalpdf'];
        $verifyurl = (string)$context['verifyurl'];
        $originalsha256 = (string)$context['originalsha256'];
        $isfinal = !empty($context['isfinal']);
        $completedsigners = $context['completedsignerblocks'] ?? [];
        $signers = $context['signers'] ?? [];
        $manifest = is_array($context['manifest'] ?? null) ? $context['manifest'] : [];

        $this->maybe_load_plugin_vendor_autoload();
        if (class_exists('\setasign\Fpdi\Tcpdf\Fpdi')) {
            try {
                $tmpdir = make_request_directory();
                if (!$tmpdir) {
                    return $this->build_fallback_result($job, $verifyurl, $originalsha256, $signers, $manifest, $isfinal);
                }

                $sourcepath = $tmpdir . DIRECTORY_SEPARATOR . "ncasign_job_{$job->id}_source.pdf";
                file_put_contents($sourcepath, $originalpdf);

                $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
                $pdf->SetAutoPageBreak(false, 0);

                $pagecount = $pdf->setSourceFile($sourcepath);
                $style = [
                    'border' => 0,
                    'padding' => 0,
                    'fgcolor' => [0, 0, 0],
                    'bgcolor' => false,
                ];

                for ($pageno = 1; $pageno <= $pagecount; $pageno++) {
                    $templateid = $pdf->importPage($pageno);
                    $size = $pdf->getTemplateSize($templateid);
                    $w = (float)($size['width'] ?? $size['w'] ?? 210.0);
                    $h = (float)($size['height'] ?? $size['h'] ?? 297.0);
                    $orientation = ($w > $h) ? 'L' : 'P';

                    $pdf->AddPage($orientation, [$w, $h]);
                    $pdf->useTemplate($templateid, 0, 0, $w, $h, true);

                    $margin = 6.0;
                    $availableblocks = $completedsigners ?: [[
                        'label' => 'Verification',
                        'payload' => $verifyurl,
                        'signedat' => '',
                    ]];
                    $blockcount = min(3, count($availableblocks));
                    $blockwidth = min(40.0, max(26.0, ($w - (($blockcount + 1) * $margin)) / max(1, $blockcount)));
                    $qrsize = min(18.0, max(12.0, $blockwidth * 0.45));
                    $blockheight = max(18.0, $qrsize + 10.0);
                    $y = max($margin, $h - $blockheight - $margin);

                    for ($i = 0; $i < $blockcount; $i++) {
                        $block = $availableblocks[$i];
                        $x = $margin + ($i * ($blockwidth + $margin));
                        $pdf->write2DBarcode((string)$block['payload'], 'QRCODE,H', $x, $y + 4, $qrsize, $qrsize, $style, 'N');
                        $pdf->SetFont('helvetica', 'B', 6);
                        $pdf->SetXY($x + $qrsize + 2, $y + 3);
                        $pdf->MultiCell(max(8.0, $blockwidth - $qrsize - 2), 4, (string)$block['label'], 0, 'L', false, 1);
                        if (!empty($block['signedat'])) {
                            $pdf->SetFont('helvetica', '', 5);
                            $pdf->SetX($x + $qrsize + 2);
                            $pdf->MultiCell(max(8.0, $blockwidth - $qrsize - 2), 3, (string)$block['signedat'], 0, 'L', false, 1);
                        }
                    }
                }

                if (!$isfinal) {
                    $this->append_signing_evidence_page($pdf, $job, $verifyurl, $originalsha256, $signers, $manifest, $isfinal);
                }
                $content = $pdf->Output('', 'S');

                return [
                    'filename' => $isfinal ? "signed_final_job_{$job->id}.pdf" : "signed_progress_job_{$job->id}.pdf",
                    'content' => $content,
                    'source' => $isfinal ? 'ncasign_qr_overlay_final' : 'ncasign_qr_overlay_progress',
                    'backend' => $this->get_backend_name(),
                    'mode' => 'artifact_pdf',
                    'supports_embedded_pades' => false,
                    'finalhash' => $isfinal ? hash('sha256', $content) : null,
                    'evidence' => [
                        'manifest' => $manifest,
                        'originalsha256' => $originalsha256,
                        'verifyurl' => $verifyurl,
                    ],
                ];
            } catch (\Throwable $e) {
                error_log('local_ncasign: artifact finalizer FPDI overlay failed, using fallback PDF. ' . $e->getMessage());
            }
        }

        return $this->build_fallback_result($job, $verifyurl, $originalsha256, $signers, $manifest, $isfinal);
    }

    /**
     * @inheritDoc
     */
    public function get_required_embedding_capabilities(): array {
        return [
            'Expose a prepare endpoint that returns the exact PDF signature revision digest/DTBS bytes for a signer slot.',
            'Accept a draft PDF plus reserved signature field/slot manifest for three sequential signatures.',
            'Embed externally produced detached CMS into named PDF signature fields as incremental updates.',
            'Preserve previous signatures while applying later signatures.',
            'Embed TSA, certificate chain, and OCSP/CRL evidence into PDF LTV structures for PAdES-LT.',
            'Return a final PDF verifiable as signed in standard PDF validators such as Adobe Reader.',
        ];
    }

    /**
     * Build fallback artifact result.
     *
     * @param \stdClass $job
     * @param string $verifyurl
     * @param string $originalsha256
     * @param array $signers
     * @param array $manifest
     * @param bool $isfinal
     * @return array<string,mixed>
     */
    private function build_fallback_result(\stdClass $job, string $verifyurl, string $originalsha256, array $signers, array $manifest, bool $isfinal): array {
        $content = $this->build_qr_fallback_pdf($job, $verifyurl, $originalsha256, $signers, $manifest, $isfinal);
        return [
            'filename' => $isfinal ? "signed_final_job_{$job->id}.pdf" : "signed_progress_job_{$job->id}.pdf",
            'content' => $content,
            'source' => $isfinal ? 'ncasign_qr_overlay_final' : 'ncasign_qr_overlay_progress',
            'backend' => $this->get_backend_name(),
            'mode' => 'artifact_pdf',
            'supports_embedded_pades' => false,
            'finalhash' => $isfinal ? hash('sha256', $content) : null,
            'evidence' => [
                'manifest' => $manifest,
                'originalsha256' => $originalsha256,
                'verifyurl' => $verifyurl,
            ],
        ];
    }

    /**
     * Build fallback one-page signed PDF with QR and audit details.
     *
     * @param \stdClass $job
     * @param string $verifyurl
     * @param string $originalsha256
     * @param array $signers
     * @param array $manifest
     * @param bool $isfinal
     * @return string
     */
    private function build_qr_fallback_pdf(\stdClass $job, string $verifyurl, string $originalsha256, array $signers, array $manifest, bool $isfinal): string {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');
        $pdf = new \pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Write(0, 'NCA Signed Document Verification', '', 0, 'L', true, 0, false, false, 0);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Ln(2);
        $pdf->Write(0, 'Document: ' . (string)$job->documenttitle, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Document type: ' . ucfirst((string)$job->documenttype), '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Job ID: ' . (int)$job->id, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Student User ID: ' . (int)$job->userid, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Course ID: ' . (int)$job->courseid, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Stage: ' . ($isfinal ? 'Final signed artifact' : 'Signed PDF progress'), '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Original PDF SHA256: ' . $originalsha256, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Verify URL: ' . $verifyurl, '', 0, 'L', true, 0, false, false, 0);

        $style = [
            'border' => 0,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ];
        $blocks = $this->build_completed_signer_blocks($job, $signers, $verifyurl);
        $blocks = $blocks ?: [[
            'label' => 'Verification',
            'payload' => $verifyurl,
            'signedat' => '',
        ]];
        $pdf->Ln(5);
        foreach (array_slice($blocks, 0, 3) as $index => $block) {
            $blockx = 15 + ($index * 62);
            $pdf->write2DBarcode((string)$block['payload'], 'QRCODE,H', $blockx, 70, 22, 22, $style, 'N');
            $pdf->SetXY($blockx + 24, 70);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->MultiCell(34, 4, (string)$block['label'], 0, 'L', false, 1);
            $pdf->SetX($blockx + 24);
            $pdf->SetFont('helvetica', '', 7);
            $pdf->MultiCell(34, 4, (string)($block['signedat'] ?: 'Pending verification'), 0, 'L', false, 1);
        }
        $pdf->SetXY(15, 120);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(180, 20, 'Scan any QR code or open the verification URL to check document authenticity, signer details, and integrity status.');

        if (!$isfinal) {
            $this->append_signing_evidence_page($pdf, $job, $verifyurl, $originalsha256, $signers, $manifest, $isfinal);
        }
        return $pdf->Output('', 'S');
    }

    /**
     * Append signer evidence page to the generated PDF.
     *
     * @param \TCPDF $pdf
     * @param \stdClass $job
     * @param string $verifyurl
     * @param string $originalsha256
     * @param array $signers
     * @param array $manifest
     * @param bool $isfinal
     * @return void
     */
    private function append_signing_evidence_page(\TCPDF $pdf, \stdClass $job, string $verifyurl, string $originalsha256, array $signers, array $manifest, bool $isfinal): void {
        $signedcount = 0;
        foreach ($signers as $signer) {
            if (($signer->status ?? '') === job_manager::SIGNER_SIGNED) {
                $signedcount++;
            }
        }

        $pdf->AddPage();
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Write(0, 'Signing Progress Summary', '', 0, 'L', true, 0, false, false, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Write(0, 'Stage: ' . ($isfinal ? 'Final signed artifact' : 'Signed PDF progress'), '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Signer progress: ' . $signedcount . '/' . max(1, count($signers)), '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Document: ' . (string)$job->documenttitle, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Document type: ' . ucfirst((string)$job->documenttype), '', 0, 'L', true, 0, false, false, 0);
        $pdf->Write(0, 'Verify URL: ' . $verifyurl, '', 0, 'L', true, 0, false, false, 0);
        if (!empty($manifest['signature_slots']) && is_array($manifest['signature_slots'])) {
            $pdf->Write(0, 'Reserved signature slots: ' . count($manifest['signature_slots']), '', 0, 'L', true, 0, false, false, 0);
        }
        $pdf->Ln(3);

        foreach ($signers as $signer) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Write(0, 'Signer #' . (int)($signer->signorder ?? 0) . ': ' . trim((string)(($signer->signername ?? '') ?: ($signer->signeremail ?? ''))), '', 0, 'L', true, 0, false, false, 0);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Write(0, 'Position: ' . (string)($signer->signerposition ?? ''), '', 0, 'L', true, 0, false, false, 0);
            $pdf->Write(0, 'Workflow status: ' . (string)($signer->status ?? ''), '', 0, 'L', true, 0, false, false, 0);
            if (!empty($signer->signedat)) {
                $pdf->Write(0, 'Signed at: ' . userdate((int)$signer->signedat), '', 0, 'L', true, 0, false, false, 0);
            }
            if (!empty($signer->expectediin) || !empty($signer->signeriin)) {
                $pdf->Write(0, 'IIN expected/verified: ' . (string)($signer->expectediin ?: '-') . ' / ' . (string)($signer->signeriin ?: '-'), '', 0, 'L', true, 0, false, false, 0);
            }
            if (!empty($signer->verificationstatus)) {
                $pdf->Write(0, 'Verification status: ' . (string)$signer->verificationstatus, '', 0, 'L', true, 0, false, false, 0);
            }
            $verification = json_decode((string)($signer->verificationinfo ?? ''), true);
            if (is_array($verification)) {
                if (!empty($verification['signingtime'])) {
                    $pdf->Write(0, 'Verifier signing time: ' . (string)$verification['signingtime'], '', 0, 'L', true, 0, false, false, 0);
                }
                if (!empty($verification['validation']['revocations']) && is_array($verification['validation']['revocations'])) {
                    foreach ($verification['validation']['revocations'] as $revocation) {
                        if (!is_array($revocation) || empty($revocation['revoked'])) {
                            continue;
                        }
                        $reason = !empty($revocation['reason']) ? ' (' . (string)$revocation['reason'] . ')' : '';
                        $pdf->Write(0, 'Revocation: ' . (string)($revocation['by'] ?? 'UNKNOWN') . $reason, '', 0, 'L', true, 0, false, false, 0);
                        break;
                    }
                }
            }
            $pdf->Ln(2);
        }
    }

    /**
     * Build visible signer QR blocks from completed signers.
     *
     * @param \stdClass $job
     * @param array $signers
     * @param string $verifyurl
     * @return array<int,array<string,string>>
     */
    private function build_completed_signer_blocks(\stdClass $job, array $signers, string $verifyurl): array {
        $blocks = [];
        foreach ($signers as $signer) {
            if (($signer->status ?? '') !== job_manager::SIGNER_SIGNED) {
                continue;
            }
            $blocks[] = [
                'label' => trim((string)(($signer->signername ?? '') ?: ('Signer ' . (int)($signer->signorder ?? 0)))),
                'payload' => $verifyurl . '&signer=' . (int)($signer->signorder ?? 0),
                'signedat' => !empty($signer->signedat) ? userdate((int)$signer->signedat) : '',
            ];
        }
        return $blocks;
    }

    /**
     * Load plugin-local composer autoload if present.
     *
     * @return void
     */
    private function maybe_load_plugin_vendor_autoload(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_readable($autoload)) {
            require_once($autoload);
        }
        $loaded = true;
    }
}
