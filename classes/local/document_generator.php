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
    /** @var string */
    public const DOC_STRUCTURED_PROTOCOL = 'structured_protocol_html';
    /** @var string */
    public const DOC_CUSTOMCERT_TEMPLATE = 'customcert_template';
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
        if ($renderer === self::DOC_ENGINEER_PROTOCOL) {
            return $this->generate_engineer_protocol($userid, $courseid, $options, $profile);
        }

        if ($renderer === self::DOC_STRUCTURED_PROTOCOL) {
            return $this->generate_structured_protocol($userid, $courseid, $options, $profile);
        }

        if ($renderer === self::DOC_CUSTOMCERT_TEMPLATE) {
            return $this->generate_customcert_template_document($userid, $courseid, $options, $profile);
        }

        throw new \RuntimeException('Unsupported template renderer: ' . $renderer);
    }

    /**
     * Overlay visible signer QR blocks onto an existing PDF and return a compatible manifest.
     *
     * This is used when the draft PDF comes from another generator, e.g. mod_customcert,
     * but still needs the same visible QR/signature slot layer expected by the ncasign flow.
     *
     * @param string $pdfbytes
     * @param string $verifyurl
     * @param array<int,array<string,mixed>> $signers
     * @param string $profilerenderer
     * @return array{content:string,finalizationmanifest:array<string,mixed>}
     */
    public function overlay_signature_qr_slots_on_pdf(
        string $pdfbytes,
        string $verifyurl,
        array $signers = [],
        string $profilerenderer = 'external_pdf'
    ): array {
        if ($pdfbytes === '') {
            throw new \RuntimeException('No PDF bytes were provided for QR/signature overlay.');
        }

        $this->load_pdf_dependencies();

        if (!class_exists('\setasign\Fpdi\Tcpdf\Fpdi')) {
            throw new \RuntimeException('FPDI for TCPDF is not installed on this Moodle server.');
        }

        $pdf = new safe_fpdi('P', 'pt');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0, true);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(0);
        $pdf->SetCreator('local_ncasign');
        $pdf->SetAuthor('local_ncasign');

        $signaturemanifest = [];
        $overlayexception = null;

        ob_start();
        try {
            $tmpdir = make_request_directory();
            if (!$tmpdir) {
                throw new \RuntimeException('Unable to create a temporary directory for PDF overlay.');
            }

            $sourcepath = $tmpdir . DIRECTORY_SEPARATOR . 'ncasign_external_source_' . md5($pdfbytes) . '.pdf';
            file_put_contents($sourcepath, $pdfbytes);

            $pagecount = $pdf->setSourceFile($sourcepath);
            for ($pageno = 1; $pageno <= $pagecount; $pageno++) {
                $templateid = $pdf->importPage($pageno);
                $size = $pdf->getTemplateSize($templateid);
                $width = (float)($size['width'] ?? $size['w'] ?? 595.0);
                $height = (float)($size['height'] ?? $size['h'] ?? 842.0);
                $orientation = ($width > $height) ? 'L' : 'P';

                $pdf->AddPage($orientation, [$width, $height]);
                $pdf->useTemplate($templateid, 0, 0, $width, $height, true);

                if ($pageno === 1) {
                    $signaturemanifest = $this->overlay_signature_blocks(
                        $pdf,
                        $width,
                        $height,
                        1,
                        $verifyurl,
                        $signers
                    );
                }
            }
        } catch (\Throwable $e) {
            $overlayexception = $e;
        } finally {
            $unexpectedoutput = trim((string)ob_get_clean());
            if ($unexpectedoutput !== '' && !$overlayexception) {
                throw new \RuntimeException('Unexpected PDF library output: ' . trim(strip_tags($unexpectedoutput)));
            }
        }

        if ($overlayexception) {
            throw $overlayexception;
        }

        return [
            'content' => $pdf->Output('', 'S'),
            'finalizationmanifest' => [
                'version' => 1,
                'reservationmode' => 'visual_signature_slots_only',
                'profile_renderer' => $profilerenderer,
                'signature_slots' => $signaturemanifest,
            ],
        ];
    }

    /**
     * Generate a PDF from a customcert template using runtime text overrides.
     *
     * @param int $userid
     * @param int $courseid
     * @param array<string,mixed> $options
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function generate_customcert_template_document(
        int $userid,
        int $courseid,
        array $options,
        array $profile = []
    ): array {
        global $DB;

        if (!class_exists('\mod_customcert\template')) {
            throw new \RuntimeException('mod_customcert is not installed on this Moodle server.');
        }

        $layoutconfig = (array)($profile['layoutconfig'] ?? []);
        $templateid = $this->get_customcert_template_id($profile);
        if ($templateid <= 0) {
            throw new \RuntimeException('No customcert template id is configured for this profile.');
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,middlename,alternatename', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname', MUST_EXIST);
        $completiondate = !empty($options['completiontimestamp']) ? (int)$options['completiontimestamp'] : 0;
        if ($completiondate <= 0) {
            $completiondate = (int)$DB->get_field('course_completions', 'timecompleted', [
                'course' => $courseid,
                'userid' => $userid,
            ], IGNORE_MISSING);
        }
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

        $overrides = $this->build_customcert_text_overrides($documentdata, $layoutconfig);
        $template = $this->load_customcert_template_instance($templateid);
        $content = '';

        customcert_runtime_overrides::push($overrides);
        try {
            if (class_exists('\mod_customcert\service\pdf_generation_service')) {
                $pdfservice = \mod_customcert\service\pdf_generation_service::create();
                $content = (string)$pdfservice->generate_pdf($template, false, $userid, true);
            } else if (method_exists($template, 'generate_pdf')) {
                $content = (string)$template->generate_pdf(false, $userid, true);
            }
        } finally {
            customcert_runtime_overrides::pop();
        }

        if ($content === '') {
            throw new \RuntimeException('customcert did not return any PDF bytes.');
        }

        $overlay = $this->overlay_signature_qr_slots_on_pdf(
            $content,
            (string)($options['verifyurl'] ?? ''),
            is_array($options['signers'] ?? null) ? $options['signers'] : [],
            self::DOC_CUSTOMCERT_TEMPLATE
        );

        $templatename = (string)$DB->get_field('customcert_templates', 'name', ['id' => $templateid], IGNORE_MISSING);
        $documenttitle = trim((string)($profile['documenttitle'] ?? '')) !== ''
            ? (string)$profile['documenttitle']
            : (trim($templatename) !== '' ? trim($templatename) : 'Custom certificate');

        return [
            'filename' => 'customcert_' . $templateid . '_' . $courseid . '_' . $userid . '.pdf',
            'content' => (string)$overlay['content'],
            'documenttype' => (string)($profile['documenttype'] ?? 'certificate'),
            'documenttitle' => $documenttitle,
            'previewdata' => [
                'templateid' => $templateid,
                'overrides' => $overrides,
            ] + $documentdata,
            'finalizationmanifest' => array_replace_recursive(
                (array)$overlay['finalizationmanifest'],
                [
                    'source' => 'customcert_runtime_template',
                    'customcert_templateid' => $templateid,
                    'customcert_text_overrides' => array_keys($overrides),
                ]
            ),
        ];
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
        $completiondate = !empty($options['completiontimestamp']) ? (int)$options['completiontimestamp'] : 0;
        if ($completiondate <= 0) {
            $completiondate = (int)$DB->get_field('course_completions', 'timecompleted', [
                'course' => $courseid,
                'userid' => $userid,
            ], IGNORE_MISSING);
        }
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
            'previewdata' => $documentdata,
            'finalizationmanifest' => [
                'version' => 1,
                'reservationmode' => 'visual_signature_slots_only',
                'profile_renderer' => self::DOC_ENGINEER_PROTOCOL,
                'signature_slots' => $signaturemanifest,
            ],
        ];
    }

    /**
     * Generate a structured HTML/CSS document and render it to PDF.
     *
     * This keeps PDF as the signed artifact while moving template authoring away
     * from coordinate-based PDF overlays.
     *
     * @param int $userid
     * @param int $courseid
     * @param array $options
     * @param array $profile
     * @return array<string, mixed>
     */
    private function generate_structured_protocol(int $userid, int $courseid, array $options, array $profile = []): array {
        global $DB;

        $layoutconfig = $this->merge_structured_protocol_layout_config((array)($profile['layoutconfig'] ?? []));
        $this->load_pdf_dependencies();

        if (!class_exists('\setasign\Fpdi\Tcpdf\Fpdi')) {
            throw new \RuntimeException('FPDI for TCPDF is not installed on this Moodle server.');
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,middlename,alternatename', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname', MUST_EXIST);
        $completiondate = !empty($options['completiontimestamp']) ? (int)$options['completiontimestamp'] : 0;
        if ($completiondate <= 0) {
            $completiondate = (int)$DB->get_field('course_completions', 'timecompleted', [
                'course' => $courseid,
                'userid' => $userid,
            ], IGNORE_MISSING);
        }
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

        $html = $this->render_structured_protocol_html($documentdata, $layoutconfig, $profile, $course);

        $pdf = new safe_fpdi('P', 'pt', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(true, 28);
        $pdf->SetMargins(28, 24, 28, true);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(0);
        $pdf->SetCreator('local_ncasign');
        $pdf->SetAuthor('local_ncasign');
        $pdf->SetTitle((string)($profile['documenttitle'] ?? 'Industrial Safety Protocol (BiOT ITR)'));
        $this->set_document_font($pdf, '', 10.0);
        $pdf->AddPage('P', 'A4');
        $pdf->writeHTML($html, true, false, true, false, '');

        $page = $pdf->getPage();
        $pagesizes = $pdf->getPageDimensions();
        $pagewidth = (float)($pagesizes['wk'] ?? 595.0);
        $pageheight = (float)($pagesizes['hk'] ?? 842.0);
        $signaturemanifest = $this->overlay_signature_blocks(
            $pdf,
            $pagewidth,
            $pageheight,
            $page,
            (string)($options['verifyurl'] ?? ''),
            is_array($options['signers'] ?? null) ? $options['signers'] : []
        );

        return [
            'filename' => 'structured_protocol_' . $courseid . '_' . $userid . '.pdf',
            'content' => $pdf->Output('', 'S'),
            'documenttype' => (string)($profile['documenttype'] ?? 'protocol'),
            'documenttitle' => (string)($profile['documenttitle'] ?? 'Industrial Safety Protocol (BiOT ITR)'),
            'protocolnumber' => (string)$documentdata['protocolnumber'],
            'previewdata' => $documentdata + ['renderedhtml' => $html],
            'finalizationmanifest' => [
                'version' => 1,
                'reservationmode' => 'visual_signature_slots_only',
                'profile_renderer' => self::DOC_STRUCTURED_PROTOCOL,
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
        $masks = (array)($layoutconfig['placeholder_masks'] ?? []);
        $staticmasks = (array)($layoutconfig['static_masks'] ?? []);
        if (!empty($staticmasks)) {
            $this->erase_placeholder_masks($pdf, $staticmasks);
        }
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
            $text = $this->normalise_render_text($text);
            if ($text === '' || empty($positions[$fieldname]) || !is_array($positions[$fieldname])) {
                continue;
            }

            if (!empty($masks[$fieldname]) && is_array($masks[$fieldname])) {
                $this->erase_placeholder_masks($pdf, $masks[$fieldname]);
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
        $count = 3;
        $style = [
            'border' => 0,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ];
        $qrsize = 26.0;
        $positions = [
            ['x' => 18.0, 'y' => 792.0],
            ['x' => 50.0, 'y' => 792.0],
            ['x' => 82.0, 'y' => 792.0],
        ];

        for ($i = 0; $i < $count; $i++) {
            $signer = $signers[$i] ?? [];
            $payload = $verifyurl !== '' ? ($verifyurl . '&signer=' . ($i + 1)) : '';
            $x = (float)($positions[$i]['x'] ?? max(8.0, $pagewidth - 140.0));
            $y = min((float)($positions[$i]['y'] ?? max(8.0, $pageheight - 40.0)), $pageheight - $qrsize - 8.0);

            if ($payload !== '') {
                $pdf->write2DBarcode($payload, 'QRCODE,H', $x, $y, $qrsize, $qrsize, $style, 'N');
            }

            $slots[] = [
                'name' => 'sig_slot_' . ($i + 1),
                'page' => $page,
                'x' => round($x, 2),
                'y' => round($y, 2),
                'w' => round($qrsize, 2),
                'h' => round($qrsize, 2),
                'type' => 'signature_qr',
                'label' => trim((string)($signer['name'] ?? ('Signer ' . ($i + 1)))),
                'payload' => $payload,
                'reserved_bytes' => null,
            ];
        }
        $pdf->SetTextColor(0, 0, 0);

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
        $outputlanguage = $this->resolve_template_output_language($metadata);
        $documenttimestamp = !empty($options['documenttimestamp']) ? (int)$options['documenttimestamp'] : time();
        $dailysequence = !empty($options['dailysequence'])
            ? max(1, (int)$options['dailysequence'])
            : $this->build_daily_sequence_number($documenttimestamp, 'protocol');
        $protocolnumber = (string)($options['protocolnumber'] ?? $this->build_protocol_number($courseid, $userid, $documenttimestamp, $dailysequence));
        $certificatenumber = (string)($options['certificatenumber'] ?? $this->build_certificate_number($courseid, $userid, $documenttimestamp, $dailysequence));
        $sentalcompanyname = trim((string)($options['sentalcompanyname'] ?? ($metadata['sentalcompanyname'] ?? ''))) !== ''
            ? trim((string)($options['sentalcompanyname'] ?? $metadata['sentalcompanyname']))
            : 'ТОО "SENTAL"';
        $clientcompanyname = $this->resolve_client_company_name(
            $userid,
            (string)($options['clientcompanyoverride'] ?? ($metadata['clientcompanyoverride'] ?? '')),
            $outputlanguage,
            $sentalcompanyname
        );
        $userfullname = (string)($options['userfullname'] ?? $this->resolve_user_full_name($userid, $user));
        $userjobtitle = (string)($options['userjobtitle'] ?? $this->resolve_user_profile_value($userid, [
            'job_title',
            'jobtitle',
            'position',
            'occupation',
            'dolzhnost',
            'lauazym',
        ], $outputlanguage === 'ru' ? 'Сотрудник' : 'Қызметкер / Сотрудник'));
        $protocoltype = $this->resolve_protocol_type_pair($userid, $courseid, $completiondate, $metadata);
        $status = $this->resolve_completion_status_text($completiondate, $metadata);
        $orderref = $this->build_order_reference_pair($metadata, $outputlanguage);
        $signers = is_array($options['signers'] ?? null) ? $options['signers'] : [];

        $issuedatekz = !empty($options['issuedatekz']) ? (string)$options['issuedatekz'] : $this->format_date_kz($completiondate);
        $issuedateru = !empty($options['issuedateru']) ? (string)$options['issuedateru'] : $this->format_date_ru($completiondate);
        if ($outputlanguage === 'ru') {
            $issuedatekz = $issuedateru;
            $protocoltype['kz'] = (string)($protocoltype['ru'] ?? '');
            $protocoltype['ru'] = (string)($protocoltype['ru'] ?? '');
            $status = $this->resolve_localised_text_variant($status, 'ru');
        }

        return [
            'clientcompanyname' => $clientcompanyname,
            'protocolnumber' => $protocolnumber,
            'issuedatekz' => $issuedatekz,
            'issuedateru' => $issuedateru,
            'chairfull' => !empty($options['chairfull'])
                ? (string)$options['chairfull']
                : $this->format_commission_full_line($signers[0] ?? [], $sentalcompanyname),
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
     * Merge structured HTML/CSS template defaults with template config.
     *
     * @param array<string, mixed> $layoutconfig
     * @return array<string, mixed>
     */
    private function merge_structured_protocol_layout_config(array $layoutconfig): array {
        return array_replace_recursive($this->get_structured_protocol_layout_defaults(), $layoutconfig);
    }

    /**
     * Default coordinate and metadata set for the finalized BiOT ITR protocol.
     *
     * These values are maintained against the editable DOCX template field map
     * in docs/protocol-docx-field-map.md rather than by PDF screenshot guessing alone.
     *
     * @return array<string, mixed>
     */
    private function get_engineer_protocol_layout_defaults(): array {
        return [
            'metadata' => [
                'outputlanguage' => 'bilingual',
                'clientcompanyoverride' => '',
                'sentalcompanyname' => 'ТОО "SENTAL"',
                'orderdate' => '',
                'ordernumber' => '',
                'protocoltype_initial_kz' => 'алғашқы',
                'protocoltype_initial_ru' => 'первичный',
                'protocoltype_repeat_kz' => 'қайталама',
                'protocoltype_repeat_ru' => 'повторный',
                'status_passed' => 'өтті / прошел',
                'status_failed' => 'білім тексеруден өтпеді / проверку знаний не прошел',
            ],
            'positions' => [
                'companyheader' => ['x' => 108.0, 'y' => 168.0, 'w' => 325.0, 'h' => 16.0, 'align' => 'C', 'size' => 11.0, 'style' => 'B', 'fit' => true, 'minsize' => 8.2],
                'protocolnumber' => ['x' => 370.0, 'y' => 95.0, 'w' => 150.0, 'h' => 11.0, 'align' => 'L', 'size' => 7.0, 'style' => 'B', 'fit' => true, 'minsize' => 5.6],
                'issuedatekz' => ['x' => 18.0, 'y' => 194.0, 'w' => 186.0, 'h' => 12.0, 'align' => 'L', 'size' => 8.6, 'style' => '', 'fit' => true, 'minsize' => 7.2],
                'issuedateru' => ['x' => 194.0, 'y' => 194.0, 'w' => 190.0, 'h' => 12.0, 'align' => 'L', 'size' => 8.6, 'style' => '', 'fit' => true, 'minsize' => 7.2],
                'chairfull' => ['x' => 196.0, 'y' => 245.0, 'w' => 322.0, 'h' => 16.0, 'align' => 'L', 'size' => 8.2, 'style' => '', 'fit' => true, 'minsize' => 6.8],
                'member1full' => ['x' => 196.0, 'y' => 279.0, 'w' => 332.0, 'h' => 16.0, 'align' => 'L', 'size' => 8.2, 'style' => '', 'fit' => true, 'minsize' => 6.8],
                'member2full' => ['x' => 196.0, 'y' => 307.0, 'w' => 342.0, 'h' => 16.0, 'align' => 'L', 'size' => 8.2, 'style' => '', 'fit' => true, 'minsize' => 6.8],
                'orderkz' => ['x' => 14.0, 'y' => 337.0, 'w' => 228.0, 'h' => 16.0, 'align' => 'L', 'size' => 8.0, 'style' => '', 'fit' => true, 'minsize' => 6.6],
                'orderru' => ['x' => 196.0, 'y' => 337.0, 'w' => 262.0, 'h' => 16.0, 'align' => 'L', 'size' => 8.0, 'style' => '', 'fit' => true, 'minsize' => 6.6],
                'protocoltypekz' => ['x' => 195.0, 'y' => 453.0, 'w' => 88.0, 'h' => 10.0, 'align' => 'C', 'size' => 8.0, 'style' => ''],
                'protocoltyperu' => ['x' => 364.0, 'y' => 453.0, 'w' => 90.0, 'h' => 10.0, 'align' => 'C', 'size' => 8.0, 'style' => ''],
                'rownumber' => ['x' => 58.0, 'y' => 629.0, 'w' => 20.0, 'h' => 20.0, 'align' => 'C', 'size' => 8.0, 'style' => ''],
                'userfullname' => ['x' => 89.0, 'y' => 628.0, 'w' => 105.0, 'h' => 22.0, 'align' => 'L', 'size' => 7.6, 'style' => '', 'fit' => true, 'minsize' => 6.4],
                'companytable' => ['x' => 216.0, 'y' => 628.0, 'w' => 104.0, 'h' => 22.0, 'align' => 'C', 'size' => 6.8, 'style' => '', 'fit' => true, 'minsize' => 5.6],
                'userjobtitle' => ['x' => 333.0, 'y' => 628.0, 'w' => 52.0, 'h' => 22.0, 'align' => 'C', 'size' => 7.0, 'style' => '', 'fit' => true, 'minsize' => 5.8],
                'completionstatus' => ['x' => 433.0, 'y' => 628.0, 'w' => 48.0, 'h' => 22.0, 'align' => 'C', 'size' => 7.0, 'style' => '', 'fit' => true, 'minsize' => 5.8],
                'certificatenumber' => ['x' => 493.0, 'y' => 628.0, 'w' => 44.0, 'h' => 22.0, 'align' => 'C', 'size' => 6.8, 'style' => '', 'fit' => true, 'minsize' => 5.6],
                'chairinitials' => ['x' => 386.0, 'y' => 754.0, 'w' => 138.0, 'h' => 10.0, 'align' => 'L', 'size' => 8.0, 'style' => '', 'fit' => true, 'minsize' => 6.8],
                'member1initials' => ['x' => 386.0, 'y' => 780.0, 'w' => 138.0, 'h' => 10.0, 'align' => 'L', 'size' => 8.0, 'style' => '', 'fit' => true, 'minsize' => 6.8],
                'member2initials' => ['x' => 386.0, 'y' => 806.0, 'w' => 138.0, 'h' => 10.0, 'align' => 'L', 'size' => 8.0, 'style' => '', 'fit' => true, 'minsize' => 6.8],
            ],
            'placeholder_masks' => [
                'companyheader' => [[104.00, 165.00, 436.00, 186.00]],
                'protocolnumber' => [[360.00, 91.00, 495.00, 104.00]],
                'issuedatekz' => [[13.00, 191.00, 183.00, 206.00]],
                'issuedateru' => [[190.00, 191.00, 370.00, 206.00]],
                'chairfull' => [[192.00, 243.00, 520.00, 262.00]],
                'member1full' => [[192.00, 277.00, 530.00, 296.00]],
                'member2full' => [[192.00, 305.00, 540.00, 324.00]],
                'orderkz' => [[12.00, 334.00, 244.00, 354.00]],
                'orderru' => [[194.00, 334.00, 464.00, 354.00]],
                'protocoltypekz' => [[178.98, 454.74, 279.33, 468.43]],
                'protocoltyperu' => [[342.03, 454.74, 449.42, 468.43]],
                'userfullname' => [[92.12, 633.83, 183.61, 667.22]],
                'companytable' => [[221.25, 633.83, 311.40, 667.22]],
                'userjobtitle' => [[334.83, 633.83, 382.82, 667.22]],
                'completionstatus' => [[432.46, 633.83, 478.61, 667.22]],
                'certificatenumber' => [[490.30, 633.83, 537.17, 667.22]],
                'chairinitials' => [[381.44, 755.24, 496.10, 767.74]],
                'member1initials' => [[381.44, 780.47, 496.10, 792.97]],
                'member2initials' => [[381.44, 805.70, 496.10, 818.20]],
            ],
            'static_masks' => [
                [188.00, 795.00, 290.00, 816.00],
            ],
        ];
    }

    /**
     * Default layout config for the structured HTML/CSS renderer.
     *
     * @return array<string, mixed>
     */
    private function get_structured_protocol_layout_defaults(): array {
        return [
            'metadata' => [
                'outputlanguage' => 'bilingual',
                'clientcompanyoverride' => '',
                'sentalcompanyname' => 'ТОО "SENTAL"',
                'orderdate' => '',
                'ordernumber' => '',
                'protocoltype_initial_kz' => 'алғашқы',
                'protocoltype_initial_ru' => 'первичный',
                'protocoltype_repeat_kz' => 'қайталама',
                'protocoltype_repeat_ru' => 'повторный',
                'status_passed' => 'өтті / прошел',
                'status_failed' => 'білім тексеруден өтпеді / проверку знаний не прошел',
            ],
            'structuredcss' => $this->get_default_structured_protocol_css(),
            'structuredtemplate' => $this->get_default_structured_protocol_template(),
        ];
    }

    /**
     * Erase known placeholder spans from the source template.
     *
     * @param \setasign\Fpdi\Tcpdf\Fpdi $pdf
     * @param array<int, array<int, float|int>> $masks
     * @return void
     */
    private function erase_placeholder_masks(\setasign\Fpdi\Tcpdf\Fpdi $pdf, array $masks): void {
        $pdf->SetFillColor(255, 255, 255);
        foreach ($masks as $mask) {
            if (!is_array($mask) || count($mask) < 4) {
                continue;
            }
            $x0 = max(0.0, (float)$mask[0] - 1.5);
            $y0 = max(0.0, (float)$mask[1] - 1.0);
            $x1 = (float)$mask[2] + 1.5;
            $y1 = (float)$mask[3] + 1.0;
            $pdf->Rect($x0, $y0, max(0.0, $x1 - $x0), max(0.0, $y1 - $y0), 'F');
        }
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
        $text = $this->normalise_render_text($text);
        $x = (float)($position['x'] ?? 0.0);
        $y = (float)($position['y'] ?? 0.0);
        $w = (float)($position['w'] ?? 0.0);
        $h = (float)($position['h'] ?? 0.0);
        $align = (string)($position['align'] ?? 'L');
        $size = (float)($position['size'] ?? 8.0);
        $minsize = (float)($position['minsize'] ?? 5.5);
        $style = (string)($position['style'] ?? '');
        $fit = !empty($position['fit']);

        if ($w <= 0 || $h <= 0) {
            return;
        }

        $this->set_document_font($pdf, $style, $size);
        if ($fit) {
            while ($size > $minsize && $pdf->GetStringWidth($text) > ($w - 2.0)) {
                $size -= 0.2;
                $this->set_document_font($pdf, $style, $size);
            }
        }
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($w, max(3.0, $h / 2), $text, 0, $align, false, 1, $x, $y, true, 0, false, true, $h, 'M');
    }

    /**
     * Render the structured HTML template with document data.
     *
     * @param array<string, string> $documentdata
     * @param array<string, mixed> $layoutconfig
     * @param array<string, mixed> $profile
     * @param \stdClass $course
     * @return string
     */
    private function render_structured_protocol_html(
        array $documentdata,
        array $layoutconfig,
        array $profile,
        \stdClass $course
    ): string {
        $template = (string)($layoutconfig['structuredtemplate'] ?? '');
        if (trim($template) === '') {
            $template = $this->get_default_structured_protocol_template();
        }
        $css = (string)($layoutconfig['structuredcss'] ?? '');
        if (trim($css) === '') {
            $css = $this->get_default_structured_protocol_css();
        }

        $tokens = [
            'documenttitle' => (string)($profile['documenttitle'] ?? ''),
            'coursename' => (string)($course->fullname ?? ''),
        ];
        foreach ($documentdata as $key => $value) {
            $tokens[$key] = $value;
        }

        $html = preg_replace_callback('/{{\s*([a-z0-9_]+)\s*}}/i', function(array $matches) use ($tokens): string {
            $key = strtolower((string)$matches[1]);
            $value = (string)($tokens[$key] ?? '');
            return htmlspecialchars($this->normalise_render_text($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $template);
        $html = is_string($html) ? $html : $template;

        return '<style>' . $css . '</style>' . $html;
    }

    /**
     * Default CSS for structured protocol templates.
     *
     * @return string
     */
    private function get_default_structured_protocol_css(): string {
        return <<<'CSS'
body { font-family: dejavusans, serif; font-size: 10pt; color: #111111; }
.doc { line-height: 1.25; }
.doc__title { text-align: center; font-weight: bold; font-size: 13pt; margin-bottom: 10px; }
.doc__org { text-align: center; font-weight: bold; font-size: 12pt; margin-bottom: 10px; }
.doc__meta { width: 100%; margin-bottom: 12px; }
.doc__meta td { font-size: 10pt; vertical-align: top; }
.doc__section { margin-top: 10px; margin-bottom: 6px; font-weight: bold; font-size: 11pt; }
.doc__line { margin-bottom: 6px; }
.doc__line-label { display: inline-block; width: 170px; font-weight: bold; }
.doc__table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.doc__table th, .doc__table td { border: 1px solid #222222; padding: 4px; font-size: 9pt; vertical-align: top; }
.doc__table th { text-align: center; font-weight: bold; }
.doc__footer { margin-top: 18px; }
.doc__sigline { margin-top: 12px; width: 100%; }
.doc__sigline td { font-size: 10pt; vertical-align: top; }
.doc__siglabel { width: 180px; font-weight: bold; }
.doc__sigvalue { border-bottom: 1px solid #222222; }
CSS;
    }

    /**
     * Default HTML template for structured protocol templates.
     *
     * @return string
     */
    private function get_default_structured_protocol_template(): string {
        return <<<'HTML'
<div class="doc">
    <div class="doc__org">{{clientcompanyname}}</div>
    <div class="doc__title">????????? / ???????? ? {{protocolnumber}}</div>
    <div class="doc__title">?????????????? ????? ???????????? ???? ??????? ?????? ??????? ?????????? ??????? ????????? ??????? ?????????? ?????????? / ????????? ??????????????? ???????? ?? ???????? ?????? ?? ???????????? ? ?????? ????? ??????????</div>

    <table class="doc__meta">
        <tr>
            <td>{{issuedatekz}}</td>
            <td>/</td>
            <td>{{issuedateru}}</td>
        </tr>
    </table>

    <div class="doc__section">???????? ?????? / ???????? ? ???????:</div>
    <div class="doc__line"><span class="doc__line-label">?????? / ????????????:</span> {{chairfull}}</div>
    <div class="doc__line"><span class="doc__line-label">???????? ???????? / ????? ????????:</span> {{member1full}}</div>
    <div class="doc__line"><span class="doc__line-label"></span> {{member2full}}</div>

    <div class="doc__line">{{orderkz}} / {{orderru}}</div>
    <div class="doc__line">??????? ??????? ???? ({{protocoltypekz}}) / ??? ???????? ?????? ({{protocoltyperu}})</div>

    <table class="doc__table">
        <tr>
            <th>?</th>
            <th>????, ???, ???????? ??? / ???</th>
            <th>???? / ???????????</th>
            <th>???????? / ?????????</th>
            <th>?????? / ?????????</th>
            <th>?????????? ?</th>
        </tr>
        <tr>
            <td>1</td>
            <td>{{userfullname}}</td>
            <td>{{companytable}}</td>
            <td>{{userjobtitle}}</td>
            <td>{{completionstatus}}</td>
            <td>{{certificatenumber}}</td>
        </tr>
    </table>

    <div class="doc__footer">
        <table class="doc__sigline">
            <tr>
                <td class="doc__siglabel">???????? ???????? / ????????????:</td>
                <td class="doc__sigvalue">{{chairinitials}}</td>
            </tr>
            <tr>
                <td class="doc__siglabel">???????? ???????? / ????? ????????:</td>
                <td class="doc__sigvalue">{{member1initials}}</td>
            </tr>
            <tr>
                <td class="doc__siglabel"></td>
                <td class="doc__sigvalue">{{member2initials}}</td>
            </tr>
        </table>
    </div>
</div>
HTML;
    }

    /**
     * Normalize display text before rendering into PDF.
     *
     * @param string $text
     * @return string
     */
    private function normalise_render_text(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        if (preg_match('/[ÃÐÑÒÓ]/u', $text)) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }

        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
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
     * Resolve the client company name from override, profile, or IOMAD membership.
     *
     * @param int $userid
     * @param string $override
     * @param string $outputlanguage
     * @param string $fallbackcompany
     * @return string
     */
    private function resolve_client_company_name(
        int $userid,
        string $override = '',
        string $outputlanguage = 'bilingual',
        string $fallbackcompany = ''
    ): string {
        if (trim($override) !== '') {
            return html_entity_decode(trim($override), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $profilecompany = $this->resolve_user_company_profile_value($userid);
        if ($profilecompany !== '') {
            return html_entity_decode($profilecompany, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $iomadcompany = $this->resolve_iomad_company_name($userid);
        if ($iomadcompany !== '') {
            return html_entity_decode($iomadcompany, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (trim($fallbackcompany) !== '') {
            return html_entity_decode(trim($fallbackcompany), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $outputlanguage === 'ru' ? 'Организация' : 'Ұйым / Организация';
    }

    /**
     * Resolve the client company from a user custom profile field.
     *
     * Primary match is by shortname "Company". If not found, fall back to field id 31.
     *
     * @param int $userid
     * @return string
     */
    private function resolve_user_company_profile_value(int $userid): string {
        global $DB;

        $manager = $DB->get_manager();
        $fieldtable = new \xmldb_table('user_info_field');
        $datatable = new \xmldb_table('user_info_data');
        if (!$manager->table_exists($fieldtable) || !$manager->table_exists($datatable)) {
            return '';
        }

        $sqlbyshortname = "SELECT d.data
                             FROM {user_info_data} d
                             JOIN {user_info_field} f ON f.id = d.fieldid
                            WHERE d.userid = :userid
                              AND LOWER(f.shortname) = LOWER(:shortname)
                         ORDER BY d.id ASC";
        $value = $DB->get_field_sql($sqlbyshortname, [
            'userid' => $userid,
            'shortname' => 'Company',
        ], IGNORE_MISSING);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $value = $DB->get_field('user_info_data', 'data', [
            'userid' => $userid,
            'fieldid' => 31,
        ], IGNORE_MISSING);

        return is_string($value) ? trim($value) : '';
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
    private function build_order_reference_pair(array $metadata, string $outputlanguage = 'bilingual'): array {
        $ordernumber = trim((string)($metadata['ordernumber'] ?? ''));
        $orderdate = trim((string)($metadata['orderdate'] ?? ''));
        if ($ordernumber === '' && $orderdate === '') {
            return ['kz' => '', 'ru' => ''];
        }

        $timestamp = $orderdate !== '' ? strtotime($orderdate . ' 00:00:00') : time();
        $ru = trim($this->format_date_ru($timestamp) . ($ordernumber !== '' ? ' ' . $ordernumber : ''));
        if ($outputlanguage === 'ru') {
            return ['kz' => $ru, 'ru' => $ru];
        }

        return [
            'kz' => trim($this->format_date_kz($timestamp) . ($ordernumber !== '' ? ' ' . $ordernumber : '')),
            'ru' => $ru,
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
                'kz' => trim((string)($metadata['protocoltype_repeat_kz'] ?? '?????????')),
                'ru' => trim((string)($metadata['protocoltype_repeat_ru'] ?? '?????????')),
            ];
        }

        return [
            'kz' => trim((string)($metadata['protocoltype_initial_kz'] ?? '???????')),
            'ru' => trim((string)($metadata['protocoltype_initial_ru'] ?? '?????????')),
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
            return trim((string)($metadata['status_passed'] ?? '???? / ??????'));
        }

        return trim((string)($metadata['status_failed'] ?? '????? ?????????? ?????? / ???????? ?????? ?? ??????'));
    }

    /**
     * Resolve template output language mode.
     *
     * @param array<string, mixed> $metadata
     * @return string
     */
    private function resolve_template_output_language(array $metadata): string {
        $language = strtolower(trim((string)($metadata['outputlanguage'] ?? 'bilingual')));
        return $language === 'ru' ? 'ru' : 'bilingual';
    }

    /**
     * Resolve a bilingual "kz / ru" value to the desired output language.
     *
     * @param string $value
     * @param string $outputlanguage
     * @return string
     */
    private function resolve_localised_text_variant(string $value, string $outputlanguage): string {
        $value = trim($value);
        if ($outputlanguage !== 'ru') {
            return $value;
        }

        $parts = preg_split('/\s*\/\s*/u', $value, 2);
        if (is_array($parts) && !empty($parts[1])) {
            return trim((string)$parts[1]);
        }

        return $value;
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
        $position = html_entity_decode(trim((string)($signer['position'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $companyname = html_entity_decode(trim($companyname), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $companysegment = $companyname;
        if ($position !== '' && $companyname !== '' && mb_stripos($position, $companyname, 0, 'UTF-8') !== false) {
            $companysegment = '';
        }

        $parts = array_filter([
            $initials,
            $position !== '' ? '- ' . $position : '',
            $companysegment,
        ]);

        return trim((string)preg_replace('/\s+/u', ' ', implode(' ', $parts)));
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

        if (preg_match('/^[^\s]+\s+\p{L}\.\p{L}\.?$/u', $name)) {
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
            1 => '??????',
            2 => '?????',
            3 => '??????',
            4 => '?????',
            5 => '?????',
            6 => '??????',
            7 => '?????',
            8 => '?????',
            9 => '????????',
            10 => '?????',
            11 => '??????',
            12 => '?????????',
        ];
        $datetime = $this->get_protocol_datetime($timestamp);

        return sprintf(
            '%s ????? "%s" %s',
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
            1 => '??????',
            2 => '???????',
            3 => '?????',
            4 => '??????',
            5 => '???',
            6 => '????',
            7 => '????',
            8 => '???????',
            9 => '????????',
            10 => '???????',
            11 => '??????',
            12 => '???????',
        ];
        $datetime = $this->get_protocol_datetime($timestamp);

        return sprintf(
            '"%s" %s %s ????',
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
     * Resolve the customcert template id from profile layout config.
     *
     * @param array<string,mixed> $profile
     * @return int
     */
    private function get_customcert_template_id(array $profile): int {
        $layoutconfig = (array)($profile['layoutconfig'] ?? []);
        $customcert = (array)($layoutconfig['customcert'] ?? []);
        return (int)($customcert['templateid'] ?? 0);
    }

    /**
     * Load a customcert template object across plugin versions.
     *
     * @param int $templateid
     * @return object
     */
    private function load_customcert_template_instance(int $templateid): object {
        global $DB;

        if ($templateid <= 0) {
            throw new \RuntimeException('Invalid customcert template id.');
        }

        try {
            if (method_exists('\mod_customcert\template', 'load')) {
                return \mod_customcert\template::load($templateid);
            }
            if (method_exists('\mod_customcert\template', 'instance')) {
                return \mod_customcert\template::instance($templateid);
            }
            $templaterecord = $DB->get_record('customcert_templates', ['id' => $templateid], '*', MUST_EXIST);
            return new \mod_customcert\template($templaterecord);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to load customcert template ' . $templateid . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build the runtime override map for named customcert text elements.
     *
     * @param array<string,string> $documentdata
     * @param array<string,mixed> $layoutconfig
     * @return array<string,string>
     */
    private function build_customcert_text_overrides(array $documentdata, array $layoutconfig): array {
        $aliases = [
            'protocol_number' => 'protocolnumber',
            'company_name' => 'clientcompanyname',
            'issue_date_kazakh' => 'issuedatekz',
            'issue_date_russian' => 'issuedateru',
            'comission_chair' => 'chairfull',
            'commission_chair' => 'chairfull',
            'commision_member_1' => 'member1full',
            'commission_member_1' => 'member1full',
            'commision_member_2' => 'member2full',
            'comission_member_2' => 'member2full',
            'commission_member_2' => 'member2full',
            'order_date_kazakh' => 'orderkz',
            'order_date_russian' => 'orderru',
            'protocol_type_kazakh' => 'protocoltypekz',
            'protocol_type_russian' => 'protocoltyperu',
            'user_full_name' => 'userfullname',
            'user_job_title' => 'userjobtitle',
            'course_completion_status' => 'completionstatus',
            'certificate_number' => 'certificatenumber',
            'commision_chair_initials_ss' => 'chairinitials',
            'comission_chair_initials_ss' => 'chairinitials',
            'commission_chair_initials_ss' => 'chairinitials',
            'commision_member_1_initials_ss' => 'member1initials',
            'commission_member_1_initials_ss' => 'member1initials',
            'comission_member_2_initials_ss' => 'member2initials',
            'commision_member_2_initials_ss' => 'member2initials',
            'commission_member_2_initials_ss' => 'member2initials',
        ];

        $customfieldmap = (array)((array)($layoutconfig['customcert'] ?? [])['fieldmap'] ?? []);
        foreach ($customfieldmap as $elementname => $sourcefield) {
            $elementname = trim((string)$elementname);
            $sourcefield = trim((string)$sourcefield);
            if ($elementname !== '' && $sourcefield !== '') {
                $aliases[\core_text::strtolower($elementname)] = $sourcefield;
            }
        }

        $overrides = [];
        foreach ($aliases as $elementname => $sourcefield) {
            if (!array_key_exists($sourcefield, $documentdata)) {
                continue;
            }
            $overrides[$elementname] = (string)$documentdata[$sourcefield];
        }

        return $overrides;
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
