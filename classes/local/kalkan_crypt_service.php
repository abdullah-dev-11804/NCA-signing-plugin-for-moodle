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
 * Server-side KalkanCrypt wrapper for detached CMS verification.
 */
class kalkan_crypt_service {
    /** @var int */
    private const KC_USE_CRL = 0x402;
    /** @var int */
    private const KC_USE_OCSP = 0x404;
    /** @var int */
    private const KC_CERTPROP_SUBJECT_COMMONNAME = 0x80a;
    /** @var int */
    private const KC_CERTPROP_SUBJECT_SERIALNUMBER = 0x80d;
    /** @var int */
    private const KC_CERTPROP_NOTBEFORE = 0x813;
    /** @var int */
    private const KC_CERTPROP_NOTAFTER = 0x814;
    /** @var int */
    private const KC_CERTPROP_CERT_SN = 0x819;
    /** @var int */
    private const KC_CERTPROP_SUBJECT_DN = 0x81b;
    /** @var int */
    private const KC_SIGN_CMS = 0x2;
    /** @var int */
    private const KC_IN_BASE64 = 0x10;
    /** @var int */
    private const KC_IN2_BASE64 = 0x20;
    /** @var int */
    private const KC_DETACHED_DATA = 0x40;
    /** @var int */
    private const KC_OUT_BASE64 = 0x800;
    /** @var int */
    private const KC_GET_OCSP_RESPONSE = 0x80000;

    /**
     * Whether the Kalkan PHP extension is available.
     *
     * @return bool
     */
    public function is_available(): bool {
        return function_exists('KalkanCrypt_Init')
            && function_exists('KalkanCrypt_VerifyData')
            && function_exists('KalkanCrypt_X509ValidateCertificate');
    }

    /**
     * Verify detached CMS over raw document bytes.
     *
     * @param string $documentbytes
     * @param string $cms
     * @param string $expectediin
     * @return array<string, mixed>
     */
    public function verify_detached_cms(string $documentbytes, string $cms, string $expectediin = ''): array {
        if (!$this->is_available()) {
            throw new \moodle_exception('verificationunavailable', 'local_ncasign');
        }

        $this->initialise();

        $documentb64 = base64_encode($documentbytes);
        $cmsb64 = $this->normalise_base64($cms);
        $verifyflags = self::KC_SIGN_CMS | self::KC_IN_BASE64 | self::KC_IN2_BASE64 | self::KC_OUT_BASE64 | self::KC_DETACHED_DATA;

        $outdata = '';
        $outverifyinfo = '';
        $outcert = '';
        $err = \KalkanCrypt_VerifyData('', $verifyflags, $documentb64, 0, $cmsb64, $outdata, $outverifyinfo, $outcert);
        if ($err > 0) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', $this->get_last_error('KalkanCrypt_VerifyData', $err));
        }

        $verifiedpayload = base64_decode((string)$outdata, true);
        if ($verifiedpayload !== false && hash('sha256', $verifiedpayload) !== hash('sha256', $documentbytes)) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'Detached CMS payload hash mismatch.');
        }

        if (trim($outcert) === '' && function_exists('KalkanCrypt_getCertFromCMS')) {
            $outertfromcms = '';
            $err = \KalkanCrypt_getCertFromCMS($cmsb64, 1, $verifyflags, $outertfromcms);
            if ($err <= 0 && trim($outertfromcms) !== '') {
                $outcert = $outertfromcms;
            }
        }

        if (trim($outcert) === '') {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'Signer certificate could not be extracted from CMS.');
        }

        $certinfo = $this->extract_certificate_info($outcert);
        $signeriin = $this->extract_iin($certinfo);
        $expectediin = preg_replace('/\D+/', '', $expectediin);
        if ($expectediin !== '' && $signeriin !== $expectediin) {
            throw new \moodle_exception('signeriinmismatch', 'local_ncasign');
        }

        $validation = $this->validate_certificate($outcert);
        $signingtime = $this->extract_signing_time($cmsb64, $verifyflags);

        return [
            'cms_base64' => $cmsb64,
            'certificate' => $outcert,
            'signeriin' => $signeriin,
            'signingtime' => $signingtime,
            'verifyinfo' => $outverifyinfo,
            'certificateinfo' => $certinfo,
            'validation' => $validation,
        ];
    }

    /**
     * Initialise Kalkan and optional TSA configuration.
     *
     * @return void
     */
    private function initialise(): void {
        $result = \KalkanCrypt_Init();
        if (is_int($result) && $result > 0) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', $this->get_last_error('KalkanCrypt_Init', $result));
        }

        $tsaurl = trim((string)get_config('local_ncasign', 'kalkantsaurl'));
        if ($tsaurl !== '' && function_exists('KalkanCrypt_TSASetUrl')) {
            @\KalkanCrypt_TSASetUrl($tsaurl);
        }
    }

    /**
     * Validate the signer certificate using configured validation mode.
     *
     * @param string $certificate
     * @return array<string, mixed>
     */
    private function validate_certificate(string $certificate): array {
        $trustpath = trim((string)get_config('local_ncasign', 'kalkantrustpath'));
        $mode = trim((string)get_config('local_ncasign', 'kalkanvalidationmode'));
        if ($mode === '') {
            $mode = 'ocspcrl';
        }

        $attempts = [];
        if ($mode === 'ocsp') {
            $attempts[] = ['label' => 'ocsp', 'flags' => self::KC_USE_OCSP | self::KC_GET_OCSP_RESPONSE];
        } else if ($mode === 'crl') {
            $attempts[] = ['label' => 'crl', 'flags' => self::KC_USE_CRL];
        } else {
            $attempts[] = ['label' => 'ocsp', 'flags' => self::KC_USE_OCSP | self::KC_GET_OCSP_RESPONSE];
            $attempts[] = ['label' => 'crl', 'flags' => self::KC_USE_CRL];
        }

        $errors = [];
        foreach ($attempts as $attempt) {
            $outinfo = '';
            $ocspresponse = '';
            $err = \KalkanCrypt_X509ValidateCertificate($certificate, $attempt['flags'], $trustpath, 0, $outinfo, 0, $ocspresponse);
            if ($err <= 0) {
                return [
                    'mode' => $attempt['label'],
                    'info' => $outinfo,
                    'ocspresponse' => $ocspresponse,
                ];
            }

            $errors[] = $attempt['label'] . ': ' . $this->get_last_error('KalkanCrypt_X509ValidateCertificate', $err);
        }

        throw new \moodle_exception('verificationfailed', 'local_ncasign', '', implode(' | ', $errors));
    }

    /**
     * Extract signing time from CMS if present.
     *
     * @param string $cmsb64
     * @param int $verifyflags
     * @return int|null
     */
    private function extract_signing_time(string $cmsb64, int $verifyflags): ?int {
        if (!function_exists('KalkanCrypt_GetTimeFromSig')) {
            return null;
        }

        $outdatetime = 0;
        $err = \KalkanCrypt_GetTimeFromSig($cmsb64, 0, $verifyflags, $outdatetime);
        if ($err > 0 || empty($outdatetime)) {
            return null;
        }

        return (int)$outdatetime;
    }

    /**
     * Extract commonly used certificate properties.
     *
     * @param string $certificate
     * @return array<string, string>
     */
    private function extract_certificate_info(string $certificate): array {
        $mapping = [
            'subjectcommonname' => self::KC_CERTPROP_SUBJECT_COMMONNAME,
            'subjectserialnumber' => self::KC_CERTPROP_SUBJECT_SERIALNUMBER,
            'subjectdn' => self::KC_CERTPROP_SUBJECT_DN,
            'certserial' => self::KC_CERTPROP_CERT_SN,
            'notbefore' => self::KC_CERTPROP_NOTBEFORE,
            'notafter' => self::KC_CERTPROP_NOTAFTER,
        ];

        $info = [];
        foreach ($mapping as $key => $prop) {
            $value = '';
            $err = \KalkanCrypt_X509CertificateGetInfo($prop, $certificate, $value);
            if ($err <= 0 && trim((string)$value) !== '') {
                $info[$key] = trim((string)$value);
            }
        }

        return $info;
    }

    /**
     * Derive IIN from certificate properties.
     *
     * @param array<string, string> $certinfo
     * @return string
     */
    private function extract_iin(array $certinfo): string {
        $candidates = [
            $certinfo['subjectserialnumber'] ?? '',
            $certinfo['subjectdn'] ?? '',
            $certinfo['subjectcommonname'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            if (preg_match('/\b(\d{12})\b/', (string)$candidate, $matches)) {
                return $matches[1];
            }
        }

        return '';
    }

    /**
     * Normalise CMS input to a base64 string.
     *
     * @param string $value
     * @return string
     */
    private function normalise_base64(string $value): string {
        return preg_replace('/\s+/', '', trim($value)) ?? '';
    }

    /**
     * Return human-readable Kalkan last error text.
     *
     * @param string $context
     * @param int $code
     * @return string
     */
    private function get_last_error(string $context, int $code): string {
        $detail = function_exists('KalkanCrypt_GetLastErrorString') ? trim((string)\KalkanCrypt_GetLastErrorString()) : '';
        if ($detail === '') {
            $detail = 'error code ' . $code;
        }
        return $context . ': ' . $detail;
    }
}
