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
 * Local PDF draft generator using FPDI + TCPDF.
 */
class document_generator {
    /** @var string */
    public const DOC_ENGINEER_PROTOCOL = 'engineer_protocol';
    /** @var string|null */
    private $resolvedfontfamily = null;

    /**
     * Generate a draft document.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $doctype
     * @param array $options
     * @return array
     */
    public function generate_draft(
        int $userid,
        int $courseid,
        string $doctype = self::DOC_ENGINEER_PROTOCOL,
        array $options = []
    ): array {
        return $this->generate_draft_from_profile($userid, $courseid, [
            'renderer' => $doctype,
            'documenttype' => 'protocol',
            'documenttitle' => 'Industrial Safety Protocol (BiOT ITR)',
            'templatepath' => '',
            'layoutconfig' => [],
        ], $options);
    }

    /**
     * Generate a draft from a resolved template profile.
     *
     * @param int $userid
     * @param int $courseid
     * @param array<string, mixed> $profile
     * @param array $options
     * @return array
     */
    public function generate_draft_from_profile(
        int $userid,
        int $courseid,
        array $profile,
        array $options = []
    ): array {
        $renderer = (string)($profile['renderer'] ?? self::DOC_ENGINEER_PROTOCOL);
        if ($renderer !== self::DOC_ENGINEER_PROTOCOL) {
            throw new \RuntimeException('Unsupported template renderer: ' . $renderer);
        }

        return $this->generate_engineer_protocol($userid, $courseid, $options, $profile);
    }

    /**
     * Generate engineer protocol draft from the supplied PDF template.
     *
     * @param int $userid
     * @param int $courseid
     * @param array $options
     * @return array
     */
    private function generate_engineer_protocol(int $userid, int $courseid, array $options, array $profile = []): array {
        global $DB;

        $templatepath = $this->get_engineer_protocol_template_path($profile);
        $layoutconfig = $this->merge_engineer_protocol_layout_config((array)($profile['layoutconfig'] ?? []));
        $this->load_pdf_dependencies();

        if (!class_exists('\setasign\Fpdi\Tcpdf\Fpdi')) {
            throw new \RuntimeException('FPDI for TCPDF is not installed on this Moodle server.');
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,middlename,alternatename', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname', MUST_EXIST);
        $completiondate = (int)$DB->get_field('course_completions', 'timecompleted', [
            'course' => $courseid,
            'userid' => $userid,
        ], IGNORE_MISSING);
        if ($completiondate <= 0) {
            $completiondate = time();
        }
        $documentdata = $this->build_engineer_protocol_data(
            $userid,
            $courseid,
            $completiondate,
            $user,
            $options,
            $profile,
            $layoutconfig
        );

        $pdf = new safe_fpdi('P', 'pt');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0, true);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(0);
        $pdf->SetCreator('local_ncasign');
        $pdf->SetAuthor('local_ncasign');
        $pdf->SetTitle((string)($profile['documenttitle'] ?? 'Industrial Safety Protocol (BiOT ITR)'));

        $generationexception = null;
        ob_start();
        try {
            $pagecount = $pdf->setSourceFile($templatepath);
            $signaturemanifest = [];
            for ($pageno = 1; $pageno <= $pagecount; $pageno++) {
                $templateid = $pdf->importPage($pageno);
                $size = $pdf->getTemplateSize($templateid);
                $width = (float)($size['width'] ?? $size['w'] ?? 595.0);
                $height = (float)($size['height'] ?? $size['h'] ?? 842.0);
                $orientation = ($width > $height) ? 'L' : 'P';

                $pdf->AddPage($orientation, [$width, $height]);
                $pdf->useTemplate($templateid, 0, 0, $width, $height, true);

                if ($pageno === 1) {
                    $this->overlay_engineer_protocol_page($pdf, $documentdata, $layoutconfig);
                    $signaturemanifest = $this->overlay_signature_blocks(
                        $pdf,
                        $width,
                        $height,
                        1,
                        (string)($options['verifyurl'] ?? ''),
                        is_array($options['signers'] ?? null) ? $options['signers'] : []
                    );
                }
            }
        } catch (\Throwable $e) {
            $generationexception = $e;
        } finally {
            $unexpectedoutput = trim((string)ob_get_clean());
            if ($unexpectedoutput !== '' && !$generationexception) {
                throw new \RuntimeException('Unexpected PDF library output: ' . trim(strip_tags($unexpectedoutput)));
            }
        }

        if ($generationexception) {
            throw $generationexception;
        }

        return [
            'filename' => 'engineer_protocol_' . $courseid . '_' . $userid . '.pdf',
            'content' => $pdf->Output('', 'S'),
            'documenttype' => (string)($profile['documenttype'] ?? 'protocol'),
            'documenttitle' => (string)($profile['documenttitle'] ?? 'Industrial Safety Protocol (BiOT ITR)'),
            'protocolnumber' => (string)$documentdata['protocolnumber'],
            'finalizationmanifest' => [
                'version' => 1,
                'reservationmode' => 'visual_signature_slots_only',
                'profile_renderer' => self::DOC_ENGINEER_PROTOCOL,
                'signature_slots' => $signaturemanifest,
            ],
        ];
    }

    /**
     * Overlay dynamic values onto the engineer protocol page.
     *
     * @param \setasign\Fpdi\Tcpdf\Fpdi $pdf
     * @param array<string, string> $documentdata
     * @param array<string, mixed> $layoutconfig
     * @return void
     */
    private function overlay_engineer_protocol_page(
        \setasign\Fpdi\Tcpdf\Fpdi $pdf,
        array $documentdata,
        array $layoutconfig
    ): void {
        $pdf->SetTextColor(0, 0, 0);
        $positions = (array)($layoutconfig['positions'] ?? []);
        $fieldmap = [
            'companyheader' => (string)($documentdata['clientcompanyname'] ?? ''),
            'protocolnumber' => (string)($documentdata['protocolnumber'] ?? ''),
            'issuedatekz' => (string)($documentdata['issuedatekz'] ?? ''),
            'issuedateru' => (string)($documentdata['issuedateru'] ?? ''),
            'chairfull' => (string)($documentdata['chairfull'] ?? ''),
            'member1full' => (string)($documentdata['member1full'] ?? ''),
            'member2full' => (string)($documentdata['member2full'] ?? ''),
            'orderkz' => (string)($documentdata['orderkz'] ?? ''),
            'orderru' => (string)($documentdata['orderru'] ?? ''),
            'protocoltypekz' => (string)($documentdata['protocoltypekz'] ?? ''),
            'protocoltyperu' => (string)($documentdata['protocoltyperu'] ?? ''),
            'rownumber' => '1',
            'userfullname' => (string)($documentdata['userfullname'] ?? ''),
            'companytable' => (string)($documentdata['clientcompanyname'] ?? ''),
            'userjobtitle' => (string)($documentdata['userjobtitle'] ?? ''),
            'completionstatus' => (string)($documentdata['completionstatus'] ?? ''),
            'certificatenumber' => (string)($documentdata['certificatenumber'] ?? ''),
            'chairinitials' => (string)($documentdata['chairinitials'] ?? ''),
            'member1initials' => (string)($documentdata['member1initials'] ?? ''),
            'member2initials' => (string)($documentdata['member2initials'] ?? ''),
        ];

        foreach ($fieldmap as $fieldname => $text) {
            if ($text === '' || empty($positions[$fieldname]) || !is_array($positions[$fieldname])) {
                continue;
            }

            $position = $positions[$fieldname];
            $this->write_layout_text($pdf, $position, $text);
        }
    }

    /**
     * Draw signer QR/label blocks that will be part of the signed PDF.
     *
     * @param \setasign\Fpdi\Tcpdf\Fpdi $pdf
     * @param float $pagewidth
     * @param float $pageheight
     * @param int $page
     * @param string $verifyurl
     * @param array<int,array<string,mixed>> $signers
     * @return array<int,array<string,mixed>>
     */
    private function overlay_signature_blocks(
        \setasign\Fpdi\Tcpdf\Fpdi $pdf,
        float $pagewidth,
        float $pageheight,
        int $page,
        string $verifyurl,
        array $signers
    ): array {
        $slots = [];
        $margin = 6.0;
        $count = 3;
        $slotwidth = min(40.0, max(26.0, ($pagewidth - (($count + 1) * $margin)) / $count));
        $slotheight = 24.0;
        $y = max($margin, $pageheight - $slotheight - $margin);
        $style = [
            'border' => 0,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ];

        for ($i = 0; $i < $count; $i++) {
            $x = $margin + ($i * ($slotwidth + $margin));
            $signer = $signers[$i] ?? [];
            $label = trim((string)($signer['name'] ?? ('Signer ' . ($i + 1))));
            $position = trim((string)($signer['position'] ?? ''));
            $payload = $verifyurl !== '' ? ($verifyurl . '&signer=' . ($i + 1)) : '';
            $qrsize = min(13.5, max(10.0, $slotwidth * 0.40));

            $pdf->SetDrawColor(120, 120, 120);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Rect($x, $y, $slotwidth, $slotheight);
            if ($payload !== '') {
                $pdf->write2DBarcode($payload, 'QRCODE,H', $x + 1.5, $y + 1.5, $qrsize, $qrsize, $style, 'N');
            }
            $this->set_document_font($pdf, 'B', 5.2);
            $pdf->SetXY($x + $qrsize + 3, $y + 2);
            $pdf->MultiCell(max(8.0, $slotwidth - $qrsize - 4), 4, $label, 0, 'L', false, 1);
            if ($position !== '') {
                $this->set_document_font($pdf, '', 4.6);
                $pdf->SetX($x + $qrsize + 3);
                $pdf->MultiCell(max(8.0, $slotwidth - $qrsize - 4), 3.5, $position, 0, 'L', false, 1);
            }

            $slots[] = [
                'name' => 'sig_slot_' . ($i + 1),
                'page' => $page,
                'x' => round($x, 2),
                'y' => round($y, 2),
                'w' => round($slotwidth, 2),
                'h' => round($slotheight, 2),
                'type' => 'qr_signature_block',
                'label' => $label,
                'payload' => $payload,
                'reserved_bytes' => null,
            ];
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);

        return $slots;
    }

    /**
     * Build all finalized protocol field values from DB/profile/template config.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $completiondate
     * @param \stdClass $user
     * @param array<string, mixed> $options
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $layoutconfig
     * @return array<string, string>
     */
    private function build_engineer_protocol_data(
        int $userid,
        int $courseid,
        int $completiondate,
        \stdClass $user,
        array $options,
        array $profile,
        array $layoutconfig
    ): array {
        $metadata = (array)($layoutconfig['metadata'] ?? []);
        $dailysequence = $this->build_daily_sequence_number($completiondate, 'protocol');
        $protocolnumber = (string)($options['protocolnumber'] ?? $this->build_protocol_number($courseid, $userid, $completiondate, $dailysequence));
        $certificatenumber = (string)($options['certificatenumber'] ?? $this->build_certificate_number($courseid, $userid, $completiondate, $dailysequence));
        $clientcompanyname = $this->resolve_client_company_name($userid, (string)($metadata['clientcompanyoverride'] ?? ''));
        $userfullname = $this->resolve_user_full_name($userid, $user);
        $userjobtitle = $this->resolve_user_profile_value($userid, [
            'job_title',
            'jobtitle',
            'position',
            'occupation',
            'dolzhnost',
            'lauazym',
        ], 'Employee');
        $protocoltype = $this->resolve_protocol_type_pair($userid, $courseid, $completiondate, $metadata);
        $status = $this->resolve_completion_status_text($completiondate, $metadata);
        $orderref = $this->build_order_reference_pair($metadata);
        $signers = is_array($options['signers'] ?? null) ? $options['signers'] : [];
        $sentalcompanyname = trim((string)($metadata['sentalcompanyname'] ?? '')) !== ''
            ? trim((string)$metadata['sentalcompanyname'])
            : 'ТОО "SENTAL"';

        return [
            'clientcompanyname' => $clientcompanyname,
            'protocolnumber' => $protocolnumber,
            'issuedatekz' => $this->format_date_kz($completiondate),
            'issuedateru' => $this->format_date_ru($completiondate),
            'chairfull' => $this->format_commission_full_line($signers[0] ?? [], $sentalcompanyname),
            'member1full' => $this->format_commission_full_line($signers[1] ?? [], $sentalcompanyname),
            'member2full' => $this->format_commission_full_line($signers[2] ?? [], $sentalcompanyname),
            'orderkz' => $orderref['kz'],
            'orderru' => $orderref['ru'],
            'protocoltypekz' => $protocoltype['kz'],
            'protocoltyperu' => $protocoltype['ru'],
            'userfullname' => $userfullname,
            'userjobtitle' => $userjobtitle,
            'completionstatus' => $status,
            'certificatenumber' => $certificatenumber,
            'chairinitials' => $this->format_signer_initials($signers[0] ?? []),
            'member1initials' => $this->format_signer_initials($signers[1] ?? []),
            'member2initials' => $this->format_signer_initials($signers[2] ?? []),
        ];
    }

    /**
     * Merge finalized protocol layout defaults with template config.
     *
     * @param array<string, mixed> $layoutconfig
     * @return array<string, mixed>
     */
    private function merge_engineer_protocol_layout_config(array $layoutconfig): array {
        return array_replace_recursive($this->get_engineer_protocol_layout_defaults(), $layoutconfig);
    }

    /**
     * Default coordinate and metadata set for the finalized BiOT ITR protocol.
     *
     * @return array<string, mixed>
     */
    private function get_engineer_protocol_layout_defaults(): array {
        return [
            'metadata' => [
                'clientcompanyoverride' => '',
                'sentalcompanyname' => 'ТОО "SENTAL"',
                'orderdate' => '',
                'ordernumber' => '',
                'protocoltype_initial_kz' => 'бастапқы',
                'protocoltype_initial_ru' => 'первичный',
                'protocoltype_repeat_kz' => 'қайталама',
                'protocoltype_repeat_ru' => 'повторный',
                'status_passed' => 'өтті / прошел',
                'status_failed' => 'қайта тексеруге жатады / подлежит повторной проверке знаний',
            ],
            'positions' => [
                'companyheader' => ['x' => 155.0, 'y' => 46.0, 'w' => 190.0, 'h' => 18.0, 'align' => 'C', 'size' => 10.0, 'style' => 'B'],
                'protocolnumber' => ['x' => 404.0, 'y' => 79.0, 'w' => 42.0, 'h' => 12.0, 'align' => 'L', 'size' => 8.0, 'style' => 'B'],
                'issuedatekz' => ['x' => 58.0, 'y' => 178.0, 'w' => 148.0, 'h' => 12.0, 'align' => 'L', 'size' => 8.4, 'style' => ''],
                'issuedateru' => ['x' => 209.0, 'y' => 178.0, 'w' => 168.0, 'h' => 12.0, 'align' => 'L', 'size' => 8.4, 'style' => ''],
                'chairfull' => ['x' => 223.0, 'y' => 249.0, 'w' => 260.0, 'h' => 14.0, 'align' => 'L', 'size' => 8.2, 'style' => ''],
                'member1full' => ['x' => 223.0, 'y' => 285.0, 'w' => 280.0, 'h' => 14.0, 'align' => 'L', 'size' => 8.2, 'style' => ''],
                'member2full' => ['x' => 223.0, 'y' => 317.0, 'w' => 290.0, 'h' => 14.0, 'align' => 'L', 'size' => 8.2, 'style' => ''],
                'orderkz' => ['x' => 27.0, 'y' => 349.0, 'w' => 210.0, 'h' => 16.0, 'align' => 'L', 'size' => 8.0, 'style' => ''],
                'orderru' => ['x' => 239.0, 'y' => 349.0, 'w' => 204.0, 'h' => 16.0, 'align' => 'L', 'size' => 8.0, 'style' => ''],
                'protocoltypekz' => ['x' => 195.0, 'y' => 453.0, 'w' => 88.0, 'h' => 10.0, 'align' => 'C', 'size' => 8.0, 'style' => ''],
                'protocoltyperu' => ['x' => 364.0, 'y' => 453.0, 'w' => 90.0, 'h' => 10.0, 'align' => 'C', 'size' => 8.0, 'style' => ''],
                'rownumber' => ['x' => 58.0, 'y' => 629.0, 'w' => 20.0, 'h' => 20.0, 'align' => 'C', 'size' => 8.0, 'style' => ''],
                'userfullname' => ['x' => 89.0, 'y' => 628.0, 'w' => 105.0, 'h' => 22.0, 'align' => 'L', 'size' => 7.6, 'style' => ''],
                'companytable' => ['x' => 216.0, 'y' => 628.0, 'w' => 104.0, 'h' => 22.0, 'align' => 'C', 'size' => 7.0, 'style' => ''],
                'userjobtitle' => ['x' => 333.0, 'y' => 628.0, 'w' => 52.0, 'h' => 22.0, 'align' => 'C', 'size' => 7.2, 'style' => ''],
                'completionstatus' => ['x' => 433.0, 'y' => 628.0, 'w' => 48.0, 'h' => 22.0, 'align' => 'C', 'size' => 7.2, 'style' => ''],
                'certificatenumber' => ['x' => 526.0, 'y' => 626.0, 'w' => 50.0, 'h' => 24.0, 'align' => 'C', 'size' => 6.8, 'style' => ''],
                'chairinitials' => ['x' => 462.0, 'y' => 693.0, 'w' => 100.0, 'h' => 10.0, 'align' => 'L', 'size' => 8.8, 'style' => ''],
                'member1initials' => ['x' => 462.0, 'y' => 732.0, 'w' => 100.0, 'h' => 10.0, 'align' => 'L', 'size' => 8.8, 'style' => ''],
                'member2initials' => ['x' => 462.0, 'y' => 769.0, 'w' => 100.0, 'h' => 10.0, 'align' => 'L', 'size' => 8.8, 'style' => ''],
            ],
        ];
    }

    /**
     * Write one configured field.
     *
     * @param \setasign\Fpdi\Tcpdf\Fpdi $pdf
     * @param array<string, mixed> $position
     * @param string $text
     * @return void
     */
    private function write_layout_text(\setasign\Fpdi\Tcpdf\Fpdi $pdf, array $position, string $text): void {
        $x = (float)($position['x'] ?? 0.0);
        $y = (float)($position['y'] ?? 0.0);
        $w = (float)($position['w'] ?? 0.0);
        $h = (float)($position['h'] ?? 0.0);
        $align = (string)($position['align'] ?? 'L');
        $size = (float)($position['size'] ?? 8.0);
        $style = (string)($position['style'] ?? '');

        if ($w <= 0 || $h <= 0) {
            return;
        }

        $this->set_document_font($pdf, $style, $size);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($w, max(3.0, $h / 2), $text, 0, $align, false, 1, $x, $y, true, 0, false, true, $h, 'M');
    }

    /**
     * Write text into a bounded cell area.
     *
     * @param \setasign\Fpdi\Tcpdf\Fpdi $pdf
     * @param float $x
     * @param float $y
     * @param float $width
     * @param float $height
     * @param string $text
     * @param string $align
     * @param int $fontsize
     * @return void
     */
    private function write_cell_text(
        \setasign\Fpdi\Tcpdf\Fpdi $pdf,
        float $x,
        float $y,
        float $width,
        float $height,
        string $text,
        string $align,
        int $fontsize
    ): void {
        $this->set_document_font($pdf, '', $fontsize);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($width, $height / 2, $text, 0, $align, false, 1, $x, $y, true, 0, false, true, $height, 'M');
    }

    /**
     * Build a stable protocol number for the draft.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $timestamp
     * @param int|null $sequence
     * @return string
     */
    private function build_protocol_number(int $courseid, int $userid, int $timestamp, ?int $sequence = null): string {
        return $this->build_document_number('PRO', $courseid, $userid, $timestamp, $sequence);
    }

    /**
     * Build a stable certificate number for the protocol row.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $timestamp
     * @param int|null $sequence
     * @return string
     */
    private function build_certificate_number(int $courseid, int $userid, int $timestamp, ?int $sequence = null): string {
        return $this->build_document_number('CER', $courseid, $userid, $timestamp, $sequence);
    }

    /**
     * Build a numbered document reference.
     *
     * @param string $prefix
     * @param int $courseid
     * @param int $userid
     * @param int $timestamp
     * @param int|null $sequence
     * @return string
     */
    private function build_document_number(string $prefix, int $courseid, int $userid, int $timestamp, ?int $sequence = null): string {
        $datetime = $this->get_protocol_datetime($timestamp);
        $sequence = $sequence ?? $this->build_daily_sequence_number($timestamp, strtolower($prefix));

        return sprintf(
            '%s-%d-%04d-%s-%04d',
            strtoupper($prefix),
            $userid,
            $courseid,
            $datetime->format('Ymd'),
            $sequence
        );
    }

    /**
     * Resolve the client company name from IOMAD, with optional template override.
     *
     * @param int $userid
     * @param string $override
     * @return string
     */
    private function resolve_client_company_name(int $userid, string $override = ''): string {
        if (trim($override) !== '') {
            return trim($override);
        }

        $iomadcompany = $this->resolve_iomad_company_name($userid);
        if ($iomadcompany !== '') {
            return $iomadcompany;
        }

        return 'Organisation';
    }

    /**
     * Resolve the user's company from IOMAD company membership if available.
     *
     * @param int $userid
     * @return string
     */
    private function resolve_iomad_company_name(int $userid): string {
        global $DB;

        $manager = $DB->get_manager();
        $companytable = new \xmldb_table('company');
        $companyuserstable = new \xmldb_table('company_users');
        if (!$manager->table_exists($companytable) || !$manager->table_exists($companyuserstable)) {
            return '';
        }

        $sql = "SELECT c.name
                  FROM {company_users} cu
                  JOIN {company} c ON c.id = cu.companyid
                 WHERE cu.userid = :userid
              ORDER BY cu.id ASC";
        $name = $DB->get_field_sql($sql, ['userid' => $userid], IGNORE_MISSING);
        return is_string($name) ? trim($name) : '';
    }

    /**
     * Determine the daily sequence counter for generated protocol numbers.
     *
     * @param int $timestamp
     * @param string $documenttype
     * @return int
     */
    private function build_daily_sequence_number(int $timestamp, string $documenttype): int {
        global $DB;

        $daystart = $this->get_protocol_datetime($timestamp)->setTime(0, 0, 0);
        $dayend = $daystart->modify('+1 day');
        $count = (int)$DB->count_records_select('local_ncasign_jobs', 'documenttype = :documenttype AND timecreated >= :start AND timecreated < :end', [
            'documenttype' => $documenttype === 'certificate' ? 'certificate' : 'protocol',
            'start' => $daystart->getTimestamp(),
            'end' => $dayend->getTimestamp(),
        ]);

        return $count + 1;
    }

    /**
     * Build Kazakh and Russian order references from template metadata.
     *
     * @param array<string, mixed> $metadata
     * @return array{kz:string,ru:string}
     */
    private function build_order_reference_pair(array $metadata): array {
        $ordernumber = trim((string)($metadata['ordernumber'] ?? ''));
        $orderdate = trim((string)($metadata['orderdate'] ?? ''));
        if ($ordernumber === '' && $orderdate === '') {
            return ['kz' => '', 'ru' => ''];
        }

        $timestamp = $orderdate !== '' ? strtotime($orderdate . ' 00:00:00') : time();
        return [
            'kz' => trim($this->format_date_kz($timestamp) . ($ordernumber !== '' ? ' ' . $ordernumber : '')),
            'ru' => trim($this->format_date_ru($timestamp) . ($ordernumber !== '' ? ' ' . $ordernumber : '')),
        ];
    }

    /**
     * Resolve the protocol type pair based on whether the user has a previous completion.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $completiondate
     * @param array<string, mixed> $metadata
     * @return array{kz:string,ru:string}
     */
    private function resolve_protocol_type_pair(int $userid, int $courseid, int $completiondate, array $metadata): array {
        $isrepeat = $this->user_has_previous_completion($userid, $courseid, $completiondate);
        if ($isrepeat) {
            return [
                'kz' => trim((string)($metadata['protocoltype_repeat_kz'] ?? 'қайталама')),
                'ru' => trim((string)($metadata['protocoltype_repeat_ru'] ?? 'повторный')),
            ];
        }

        return [
            'kz' => trim((string)($metadata['protocoltype_initial_kz'] ?? 'бастапқы')),
            'ru' => trim((string)($metadata['protocoltype_initial_ru'] ?? 'первичный')),
        ];
    }

    /**
     * Resolve bilingual completion status text.
     *
     * @param int $completiondate
     * @param array<string, mixed> $metadata
     * @return string
     */
    private function resolve_completion_status_text(int $completiondate, array $metadata): string {
        if ($completiondate > 0) {
            return trim((string)($metadata['status_passed'] ?? 'өтті / прошел'));
        }

        return trim((string)($metadata['status_failed'] ?? 'қайта тексеруге жатады / подлежит повторной проверке знаний'));
    }

    /**
     * Resolve the user's printable full name.
     *
     * @param int $userid
     * @param \stdClass $user
     * @return string
     */
    private function resolve_user_full_name(int $userid, \stdClass $user): string {
        $parts = [];
        foreach (['lastname', 'firstname'] as $field) {
            if (!empty($user->{$field})) {
                $parts[] = trim((string)$user->{$field});
            }
        }

        $patronymic = $this->resolve_user_profile_value($userid, [
            'patronymic',
            'middlename',
            'middle_name',
            'otchestvo',
        ], trim((string)($user->middlename ?? '')));
        if ($patronymic !== '') {
            $parts[] = $patronymic;
        }

        if (!$parts) {
            return $this->format_person_name($user);
        }

        return implode(' ', $parts);
    }

    /**
     * Format a commission line as "Surname I.P. - title company".
     *
     * @param array<string, mixed> $signer
     * @param string $companyname
     * @return string
     */
    private function format_commission_full_line(array $signer, string $companyname): string {
        $initials = $this->format_signer_initials($signer);
        $position = trim((string)($signer['position'] ?? ''));
        $parts = array_filter([$initials, $position !== '' ? '- ' . $position : '', $companyname]);
        return trim(implode(' ', $parts));
    }

    /**
     * Format signer name as "Surname I.P.".
     *
     * @param array<string, mixed> $signer
     * @return string
     */
    private function format_signer_initials(array $signer): string {
        $name = trim((string)($signer['name'] ?? ''));
        if ($name === '' && !empty($signer['email'])) {
            $name = (string)$signer['email'];
        }

        if ($name === '') {
            return '';
        }

        if (preg_match('/^[^\\s]+\\s+[A-ZА-ЯӘІҢҒҮҰҚӨҺЁ]\\.[A-ZА-ЯӘІҢҒҮҰҚӨҺЁ]\\.?$/u', $name)) {
            return $name;
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return $name;
        }

        $surname = array_shift($parts);
        if (!$parts) {
            return $surname;
        }

        $initials = [];
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials[] = mb_substr($part, 0, 1, 'UTF-8') . '.';
        }

        return trim($surname . ' ' . implode('', $initials));
    }

    /**
     * Check whether the user completed this course before the current completion.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $completiondate
     * @return bool
     */
    private function user_has_previous_completion(int $userid, int $courseid, int $completiondate): bool {
        global $DB;

        if ($completiondate <= 0) {
            return false;
        }

        return $DB->record_exists_select('course_completions', 'userid = :userid AND course = :courseid AND timecompleted > 0 AND timecompleted < :timecompleted', [
            'userid' => $userid,
            'courseid' => $courseid,
            'timecompleted' => $completiondate,
        ]);
    }

    /**
     * Format a timestamp as a Kazakh issue/order date.
     *
     * @param int $timestamp
     * @return string
     */
    private function format_date_kz(int $timestamp): string {
        $months = [
            1 => 'қаңтар',
            2 => 'ақпан',
            3 => 'наурыз',
            4 => 'сәуір',
            5 => 'мамыр',
            6 => 'маусым',
            7 => 'шілде',
            8 => 'тамыз',
            9 => 'қыркүйек',
            10 => 'қазан',
            11 => 'қараша',
            12 => 'желтоқсан',
        ];
        $datetime = $this->get_protocol_datetime($timestamp);

        return sprintf(
            '%s жылғы "%s" %s',
            $datetime->format('Y'),
            $datetime->format('d'),
            $months[(int)$datetime->format('n')] ?? $datetime->format('m')
        );
    }

    /**
     * Format a timestamp as a Russian issue/order date.
     *
     * @param int $timestamp
     * @return string
     */
    private function format_date_ru(int $timestamp): string {
        $months = [
            1 => 'января',
            2 => 'февраля',
            3 => 'марта',
            4 => 'апреля',
            5 => 'мая',
            6 => 'июня',
            7 => 'июля',
            8 => 'августа',
            9 => 'сентября',
            10 => 'октября',
            11 => 'ноября',
            12 => 'декабря',
        ];
        $datetime = $this->get_protocol_datetime($timestamp);

        return sprintf(
            '"%s" %s %s года',
            $datetime->format('d'),
            $months[(int)$datetime->format('n')] ?? $datetime->format('m'),
            $datetime->format('Y')
        );
    }

    /**
     * Build a date-time object in the UTC+5 business timezone required by the client spec.
     *
     * @param int $timestamp
     * @return \DateTimeImmutable
     */
    private function get_protocol_datetime(int $timestamp): \DateTimeImmutable {
        $datetime = new \DateTimeImmutable('@' . $timestamp);
        return $datetime->setTimezone(new \DateTimeZone('+05:00'));
    }

    /**
     * Load TCPDF and plugin-local composer autoload if present.
     *
     * @return void
     */
    private function load_pdf_dependencies(): void {
        global $CFG;

        static $loaded = false;
        if ($loaded) {
            return;
        }

        require_once($CFG->libdir . '/tcpdf/tcpdf.php');
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_readable($autoload)) {
            require_once($autoload);
        }
        if (class_exists('\setasign\Fpdi\Tcpdf\Fpdi') && !class_exists('\local_ncasign\local\safe_fpdi')) {
            require_once(__DIR__ . '/safe_fpdi.php');
        }

        $loaded = true;
    }

    /**
     * Resolve engineer protocol template path from plugin settings.
     *
     * @return string
     */
    private function get_engineer_protocol_template_path(array $profile = []): string {
        $path = trim((string)($profile['templatepath'] ?? ''));
        if ($path === '' || !is_readable($path)) {
            throw new \RuntimeException('Engineer protocol template PDF path is not configured or not readable.');
        }
        return $path;
    }

    /**
     * Resolve a value from Moodle profile fields with a fallback.
     *
     * @param int $userid
     * @param array $shortnames
     * @param string $default
     * @return string
     */
    private function resolve_user_profile_value(int $userid, array $shortnames, string $default): string {
        global $DB;

        foreach ($shortnames as $shortname) {
            $sql = "SELECT d.data
                      FROM {user_info_data} d
                      JOIN {user_info_field} f ON f.id = d.fieldid
                     WHERE d.userid = :userid
                       AND " . $DB->sql_compare_text('f.shortname') . " = :shortname";
            $value = $DB->get_field_sql($sql, [
                'userid' => $userid,
                'shortname' => $shortname,
            ], IGNORE_MISSING);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $default;
    }

    /**
     * Build a safe display name without calling fullname() on partial records.
     *
     * @param \stdClass $user
     * @return string
     */
    private function format_person_name(\stdClass $user): string {
        $parts = [];
        foreach (['firstname', 'middlename', 'lastname'] as $field) {
            if (!empty($user->{$field})) {
                $parts[] = trim((string)$user->{$field});
            }
        }

        if (!$parts && !empty($user->alternatename)) {
            $parts[] = trim((string)$user->alternatename);
        }

        return $parts ? implode(' ', $parts) : 'Student';
    }

    /**
     * Set a working font available in this TCPDF installation.
     *
     * @param \setasign\Fpdi\Tcpdf\Fpdi $pdf
     * @param string $style
     * @param float $size
     * @return void
     */
    private function set_document_font(\setasign\Fpdi\Tcpdf\Fpdi $pdf, string $style, float $size): void {
        if ($this->resolvedfontfamily !== null) {
            $pdf->SetFont($this->resolvedfontfamily, $style, $size);
            return;
        }

        foreach (['freesans', 'dejavusans', 'helvetica'] as $candidate) {
            try {
                $pdf->SetFont($candidate, $style, $size);
                $this->resolvedfontfamily = $candidate;
                return;
            } catch (\Throwable $e) {
                continue;
            }
        }

        throw new \RuntimeException('No usable TCPDF font found. Tried: freesans, dejavusans, helvetica.');
    }
}
