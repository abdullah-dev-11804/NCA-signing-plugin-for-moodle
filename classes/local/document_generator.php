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
        if ($doctype !== self::DOC_ENGINEER_PROTOCOL) {
            throw new \RuntimeException('Unsupported document type: ' . $doctype);
        }

        return $this->generate_engineer_protocol($userid, $courseid, $options);
    }

    /**
     * Generate engineer protocol draft from the supplied PDF template.
     *
     * @param int $userid
     * @param int $courseid
     * @param array $options
     * @return array
     */
    private function generate_engineer_protocol(int $userid, int $courseid, array $options): array {
        global $DB;

        $templatepath = $this->get_engineer_protocol_template_path();
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

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'pt');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetCreator('local_ncasign');
        $pdf->SetAuthor('local_ncasign');
        $pdf->SetTitle('Industrial Safety Protocol (Engineer)');

        $pagecount = $pdf->setSourceFile($templatepath);
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
                    fullname($user),
                    $occupation,
                    $education,
                    $conclusion,
                    $protocolnumber,
                    $completiondate
                );
            }
        }

        return [
            'filename' => 'engineer_protocol_' . $courseid . '_' . $userid . '.pdf',
            'content' => $pdf->Output('', 'S'),
            'documenttype' => 'protocol',
            'documenttitle' => 'Industrial Safety Protocol (Engineer)',
            'protocolnumber' => $protocolnumber,
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
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetXY(392, 33);
        $pdf->Cell(92, 12, $protocolnumber, 0, 0, 'L');

        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(92, 106);
        $pdf->Cell(120, 12, userdate($completiondate, '%d.%m.%Y'), 0, 0, 'L');

        $this->write_cell_text($pdf, 62, 615, 18, 18, '1', 'C', 9);
        $this->write_cell_text($pdf, 89, 615, 152, 28, $fullname, 'L', 9);
        $this->write_cell_text($pdf, 270, 615, 56, 28, $occupation, 'C', 9);
        $this->write_cell_text($pdf, 357, 615, 72, 28, $education, 'C', 9);
        $this->write_cell_text($pdf, 441, 615, 116, 28, $conclusion, 'C', 9);
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
        $pdf->SetFont('dejavusans', '', $fontsize);
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

        $loaded = true;
    }

    /**
     * Resolve engineer protocol template path from plugin settings.
     *
     * @return string
     */
    private function get_engineer_protocol_template_path(): string {
        $path = trim((string)get_config('local_ncasign', 'engineerprotocoltemplatepath'));
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
}
