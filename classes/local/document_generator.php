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
            'documenttitle' => 'Industrial Safety Protocol (Engineer)',
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

        $protocolnumber = $options['protocolnumber'] ?? $this->build_protocol_number($courseid, $userid, $completiondate);
        $occupation = $options['occupation'] ?? $this->resolve_user_profile_value($userid, [
            'position',
            'jobtitle',
            'occupation',
        ], 'Employee');
        $education = $options['education'] ?? $this->resolve_user_profile_value($userid, [
            'education',
            'degree',
        ], 'Higher');
        $conclusion = $options['conclusion'] ?? 'Passed';

        $pdf = new safe_fpdi('P', 'pt');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0, true);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(0);
        $pdf->SetCreator('local_ncasign');
        $pdf->SetAuthor('local_ncasign');
        $pdf->SetTitle('Industrial Safety Protocol (Engineer)');

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
                    $this->overlay_engineer_protocol_page(
                        $pdf,
                        $this->format_person_name($user),
                        $occupation,
                        $education,
                        $conclusion,
                        $protocolnumber,
                        $completiondate
                    );
                    $signaturemanifest = $this->overlay_reserved_signature_slots($pdf, $width, $height, 1);
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
            'documenttitle' => (string)($profile['documenttitle'] ?? 'Industrial Safety Protocol (Engineer)'),
            'protocolnumber' => $protocolnumber,
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
     * @param string $fullname
     * @param string $occupation
     * @param string $education
     * @param string $conclusion
     * @param string $protocolnumber
     * @param int $completiondate
     * @return void
     */
    private function overlay_engineer_protocol_page(
        \setasign\Fpdi\Tcpdf\Fpdi $pdf,
        string $fullname,
        string $occupation,
        string $education,
        string $conclusion,
        string $protocolnumber,
        int $completiondate
    ): void {
        $pdf->SetTextColor(0, 0, 0);
        $displaynumber = preg_replace('/^PB-/i', '', $protocolnumber) ?: $protocolnumber;
        $this->set_document_font($pdf, 'B', 7.5);
        $pdf->SetXY(438, 35);
        $pdf->Cell(74, 10, $displaynumber, 0, 0, 'L');

        $day = userdate($completiondate, '%d');
        $month = userdate($completiondate, '%m');
        $year = userdate($completiondate, '%Y');
        $this->set_document_font($pdf, '', 8.5);
        $pdf->SetXY(79, 108);
        $pdf->Cell(18, 10, $day, 0, 0, 'C');
        $pdf->SetXY(118, 108);
        $pdf->Cell(18, 10, $month, 0, 0, 'C');
        $pdf->SetXY(146, 108);
        $pdf->Cell(34, 10, $year, 0, 0, 'C');

        $this->write_cell_text($pdf, 62, 617, 18, 14, '1', 'C', 8);
        $this->write_cell_text($pdf, 89, 617, 152, 14, $fullname, 'L', 8);
        $this->write_cell_text($pdf, 270, 617, 56, 14, $occupation, 'C', 8);
        $this->write_cell_text($pdf, 357, 617, 72, 14, $education, 'C', 8);
        $this->write_cell_text($pdf, 441, 617, 116, 14, $conclusion, 'C', 8);
    }

    /**
     * Draw visible reserved signature slots for future PAdES finalization.
     *
     * These are visual placeholders plus manifest metadata only. They are not real PDF
     * signature byte ranges yet; a dedicated PAdES backend still has to consume this manifest
     * and embed detached CMS into true PDF signature fields.
     *
     * @param \setasign\Fpdi\Tcpdf\Fpdi $pdf
     * @param float $pagewidth
     * @param float $pageheight
     * @param int $page
     * @return array<int,array<string,mixed>>
     */
    private function overlay_reserved_signature_slots(
        \setasign\Fpdi\Tcpdf\Fpdi $pdf,
        float $pagewidth,
        float $pageheight,
        int $page
    ): array {
        $slots = [];
        $margin = 6.0;
        $count = 3;
        $slotwidth = min(40.0, max(26.0, ($pagewidth - (($count + 1) * $margin)) / $count));
        $slotheight = 24.0;
        $y = max($margin, $pageheight - $slotheight - $margin);

        $pdf->SetDrawColor(150, 150, 150);
        $pdf->SetTextColor(90, 90, 90);
        for ($i = 0; $i < $count; $i++) {
            $x = $margin + ($i * ($slotwidth + $margin));
            $label = 'Reserved signature slot ' . ($i + 1);
            $pdf->Rect($x, $y, $slotwidth, $slotheight);
            $this->set_document_font($pdf, '', 5.5);
            $pdf->SetXY($x + 2, $y + 2);
            $pdf->MultiCell($slotwidth - 4, 4, $label, 0, 'C', false, 1);
            $slots[] = [
                'name' => 'sig_slot_' . ($i + 1),
                'page' => $page,
                'x' => round($x, 2),
                'y' => round($y, 2),
                'w' => round($slotwidth, 2),
                'h' => round($slotheight, 2),
                'type' => 'visible_placeholder',
                'reserved_bytes' => null,
            ];
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);

        return $slots;
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
        require_once(__DIR__ . '/safe_fpdi.php');

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
     * Build a stable protocol number for the draft.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $timestamp
     * @return string
     */
    private function build_protocol_number(int $courseid, int $userid, int $timestamp): string {
        return 'PB-' . date('ymd', $timestamp) . '-' . $courseid . '-' . $userid;
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
