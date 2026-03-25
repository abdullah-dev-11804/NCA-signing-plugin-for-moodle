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
 * NCANode-backed detached CMS verification backend.
 */
class ncanode_signature_backend implements signature_backend_interface {
    /**
     * Verify detached CMS using NCANode REST API.
     *
     * @param string $cmsb64
     * @param string $documentbytes
     * @param string|null $expectediin
     * @return array<string, mixed>
     */
    public function verify_detached_cms(string $cmsb64, string $documentbytes, ?string $expectediin = null): array {
        $cmsb64 = $this->normalise_base64($cmsb64);
        if ($cmsb64 === '') {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'CMS payload is empty.');
        }

        $payload = [
            'cms' => $cmsb64,
            'data' => base64_encode($documentbytes),
        ];
        $revocationchecks = $this->get_revocation_checks();
        if ($revocationchecks) {
            $payload['revocationCheck'] = $revocationchecks;
        }

        $response = $this->post_json('/cms/verify', $payload);
        if ((int)($response['status'] ?? 0) !== 200) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', (string)($response['message'] ?? 'NCANode returned a non-success status.'));
        }
        if (empty($response['valid'])) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', (string)($response['message'] ?? 'NCANode marked the CMS as invalid.'));
        }

        $signer = $this->select_signer($response['signers'] ?? []);
        $certificate = $this->select_certificate($signer['certificates'] ?? []);
        $signeriin = preg_replace('/\D+/', '', (string)($certificate['subject']['iin'] ?? ''));
        $expectediin = preg_replace('/\D+/', '', (string)($expectediin ?? ''));
        if ($expectediin !== '' && $signeriin !== $expectediin) {
            throw new \moodle_exception('signeriinmismatch', 'local_ncasign');
        }

        $revocations = $certificate['revocations'] ?? [];
        foreach ($revocations as $revocation) {
            if (!empty($revocation['revoked'])) {
                $reason = (string)($revocation['reason'] ?? 'revoked');
                throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'NCANode reported a revoked certificate: ' . $reason);
            }
        }
        if (array_key_exists('valid', $certificate) && !$certificate['valid']) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'NCANode reported an invalid signer certificate.');
        }

        return [
            'cms_base64' => $cmsb64,
            'certificate' => json_encode($certificate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'signeriin' => $signeriin,
            'signingtime' => $signer['tsp']['genTime'] ?? null,
            'verifyinfo' => (string)($response['message'] ?? 'OK'),
            'certificateinfo' => $certificate,
            'validation' => [
                'revocationchecks' => $revocationchecks,
                'revocations' => $revocations,
                'tsp' => $signer['tsp'] ?? null,
                'response' => $response,
            ],
        ];
    }

    /**
     * Health check NCANode.
     *
     * @return array<string, mixed>
     */
    public function healthcheck(): array {
        return $this->get_json('/actuator/health');
    }

    /**
     * POST JSON to NCANode.
     *
     * @param string $path
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post_json(string $path, array $payload): array {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'Failed to encode NCANode request payload.');
        }

        return $this->request_json('POST', $path, $body);
    }

    /**
     * GET JSON from NCANode.
     *
     * @param string $path
     * @return array<string, mixed>
     */
    private function get_json(string $path): array {
        return $this->request_json('GET', $path, null);
    }

    /**
     * Execute an HTTP request against NCANode.
     *
     * @param string $method
     * @param string $path
     * @param string|null $body
     * @return array<string, mixed>
     */
    private function request_json(string $method, string $path, ?string $body): array {
        $baseurl = rtrim(trim((string)get_config('local_ncasign', 'ncanodebaseurl')), '/');
        if ($baseurl === '') {
            throw new \moodle_exception('verificationunavailable', 'local_ncasign');
        }

        $timeout = (int)get_config('local_ncasign', 'ncanodetimeout');
        if ($timeout <= 0) {
            $timeout = 20;
        }

        $ch = curl_init($baseurl . $path);
        if ($ch === false) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'Unable to initialise HTTP client for NCANode.');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpcode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'NCANode HTTP error: ' . $error);
        }
        if (!is_string($raw) || trim($raw) === '') {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'NCANode returned an empty response.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'NCANode returned invalid JSON: ' . $raw);
        }
        if ($httpcode >= 400) {
            $message = (string)($decoded['message'] ?? ('HTTP ' . $httpcode));
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'NCANode error: ' . $message);
        }

        return $decoded;
    }

    /**
     * Select the signer block from NCANode response.
     *
     * @param array<int, mixed> $signers
     * @return array<string, mixed>
     */
    private function select_signer(array $signers): array {
        if (!$signers) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'NCANode did not return signer information.');
        }

        $signer = $signers[0];
        if (!is_array($signer)) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'NCANode signer payload is malformed.');
        }

        return $signer;
    }

    /**
     * Select the signer certificate from NCANode response.
     *
     * @param array<int, mixed> $certificates
     * @return array<string, mixed>
     */
    private function select_certificate(array $certificates): array {
        if (!$certificates) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'NCANode did not return certificate information.');
        }

        foreach ($certificates as $certificate) {
            if (!is_array($certificate)) {
                continue;
            }
            if (!empty($certificate['subject']['iin'])) {
                return $certificate;
            }
        }

        $certificate = $certificates[0];
        if (!is_array($certificate)) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'NCANode certificate payload is malformed.');
        }

        return $certificate;
    }

    /**
     * Build revocation check list from plugin settings.
     *
     * @return string[]
     */
    private function get_revocation_checks(): array {
        $checks = [];
        if ((int)get_config('local_ncasign', 'ncanodecheckocsp')) {
            $checks[] = 'OCSP';
        }
        if ((int)get_config('local_ncasign', 'ncanodecheckcrl')) {
            $checks[] = 'CRL';
        }

        return $checks;
    }

    /**
     * Normalize base64 input.
     *
     * @param string $value
     * @return string
     */
    private function normalise_base64(string $value): string {
        return preg_replace('/\s+/', '', trim($value)) ?? '';
    }
}
