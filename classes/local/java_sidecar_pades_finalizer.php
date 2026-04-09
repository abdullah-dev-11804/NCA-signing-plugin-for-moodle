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
 * External Java sidecar finalizer client.
 *
 * This backend delegates detached CMS -> embedded PDF finalization to a Java
 * service that will combine a PAdES-capable PDF library with Kalkan-backed
 * evidence handling.
 */
class java_sidecar_pades_finalizer implements pades_finalizer_interface {
    /**
     * Normalize PHP arrays into JSON object-compatible payloads when needed.
     *
     * @param mixed $value
     * @return mixed
     */
    private function json_map($value) {
        if ($value instanceof \stdClass) {
            return $value;
        }
        if (!is_array($value)) {
            return new \stdClass();
        }
        if ($value === []) {
            return new \stdClass();
        }
        return $value;
    }

    /**
     * @inheritDoc
     */
    public function get_backend_name(): string {
        return 'java_sidecar_pades_finalizer';
    }

    /**
     * @inheritDoc
     */
    public function supports_embedded_pades(): bool {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function supports_prepare_phase(): bool {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function prepare(array $context): array {
        $pdfbytes = (string)($context['originalpdf'] ?? '');
        $signer = $context['signer'] ?? null;
        if ($pdfbytes === '' || !$signer) {
            throw new \moodle_exception('invalidpayload', 'local_ncasign');
        }

        $request = $this->build_prepare_request_payload($context);
        $response = $this->post_json('/api/v1/pades/prepare', $request);

        $status = strtolower((string)($response['status'] ?? ''));
        if ($status !== 'ok') {
            $message = (string)($response['message'] ?? 'PAdES sidecar prepare returned a non-success response.');
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', $message);
        }

        $payloadb64 = preg_replace('/\s+/', '', (string)($response['signablePayloadBase64'] ?? '')) ?? '';
        if ($payloadb64 === '') {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'PAdES sidecar did not return signablePayloadBase64.');
        }

        return [
            'sessionid' => (string)($response['sessionId'] ?? ''),
            'fieldname' => (string)($response['fieldName'] ?? ''),
            'payloadmode' => (string)($response['payloadMode'] ?? ''),
            'signablepayloadb64' => $payloadb64,
            'signablepayloadsha256' => (string)($response['signablePayloadSha256'] ?? ''),
            'signingtime' => (string)($response['signingTime'] ?? ''),
            'backend' => $this->get_backend_name(),
            'evidence' => is_array($response['evidence'] ?? null) ? $response['evidence'] : [],
        ];
    }

    /**
     * @inheritDoc
     */
    public function finalize(array $context): array {
        $pdfbytes = (string)($context['originalpdf'] ?? '');
        if ($pdfbytes === '') {
            throw new \moodle_exception('invalidpayload', 'local_ncasign');
        }

        $request = $this->build_request_payload($context);
        $response = $this->post_json('/api/v1/pades/finalize', $request);

        $status = strtolower((string)($response['status'] ?? ''));
        if ($status !== 'ok') {
            $message = (string)($response['message'] ?? 'PAdES sidecar returned a non-success response.');
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', $message);
        }

        $pdfbase64 = preg_replace('/\s+/', '', (string)($response['pdfBase64'] ?? '')) ?? '';
        if ($pdfbase64 === '') {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'PAdES sidecar did not return pdfBase64.');
        }

        $signedpdf = base64_decode($pdfbase64, true);
        if ($signedpdf === false || $signedpdf === '') {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'PAdES sidecar returned invalid pdfBase64.');
        }

        $isfinal = !empty($context['isfinal']);
        return [
            'filename' => (string)($response['filename'] ?? ($isfinal ? "signed_final_job_{$context['job']->id}.pdf" : "signed_progress_job_{$context['job']->id}.pdf")),
            'content' => $signedpdf,
            'source' => (string)($response['source'] ?? 'java_pades_sidecar'),
            'backend' => $this->get_backend_name(),
            'mode' => (string)($response['mode'] ?? 'embedded_pades'),
            'supports_embedded_pades' => true,
            'finalhash' => (string)($response['finalHash'] ?? hash('sha256', $signedpdf)),
            'evidence' => is_array($response['evidence'] ?? null) ? $response['evidence'] : [],
            'signerevidence' => is_array($response['signerEvidence'] ?? null) ? $response['signerEvidence'] : [],
        ];
    }

    /**
     * Health check the sidecar.
     *
     * @return array<string,mixed>
     */
    public function healthcheck(): array {
        return $this->request_json('GET', '/health', null);
    }

    /**
     * Verify an already signed PDF through the Java sidecar.
     *
     * @param string $pdfbytes
     * @param string $filename
     * @return array<string,mixed>
     */
    public function verify_pdf(string $pdfbytes, string $filename = ''): array {
        if ($pdfbytes === '') {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'No PDF bytes were provided for verification.');
        }
        return $this->post_json('/api/v1/pades/verify', [
            'pdfBase64' => base64_encode($pdfbytes),
            'filename' => $filename,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function get_required_embedding_capabilities(): array {
        return [
            'Java sidecar must expose POST /api/v1/pades/prepare.',
            'Java sidecar must expose POST /api/v1/pades/finalize.',
            'Prepare endpoint must return the exact PDF signature revision payload/digest for a specific slot.',
            'Sidecar must accept draft PDF bytes plus reserved signature slot manifest.',
            'Sidecar must embed detached CMS as incremental PDF signature updates.',
            'Sidecar must preserve previous signatures across signer 1/2/3.',
            'Sidecar must package OCSP/CRL/TSP evidence into the final PDF for LTV.',
        ];
    }

    /**
     * Build outbound request payload.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function build_request_payload(array $context): array {
        $job = $context['job'];
        $signers = [];
        foreach (($context['signers'] ?? []) as $signer) {
            $verificationinfo = [];
            if (!empty($signer->verificationinfo) && is_string($signer->verificationinfo)) {
                $decoded = json_decode($signer->verificationinfo, true);
                if (is_array($decoded)) {
                    $verificationinfo = $decoded;
                }
            }
            $signmeta = [];
            if (!empty($signer->signmeta) && is_string($signer->signmeta)) {
                $decoded = json_decode($signer->signmeta, true);
                if (is_array($decoded)) {
                    $signmeta = $decoded;
                }
            }

            $signers[] = [
                'signerRecordId' => (int)$signer->id,
                'order' => (int)($signer->signorder ?? 0),
                'name' => (string)($signer->signername ?? ''),
                'email' => (string)($signer->signeremail ?? ''),
                'position' => (string)($signer->signerposition ?? ''),
                'workflowStatus' => (string)($signer->status ?? ''),
                'expectedIin' => (string)($signer->expectediin ?? ''),
                'verifiedIin' => (string)($signer->signeriin ?? ''),
                'signedAt' => !empty($signer->signedat) ? (int)$signer->signedat : null,
                'rawCmsBase64' => $this->normalise_base64((string)($signer->rawcms ?? '')),
                'signerCertificateJson' => (string)($signer->signercertificate ?? ''),
                'ocspResponseJson' => (string)($signer->ocspresponse ?? ''),
                'signingMethod' => (string)($signer->signingmethod ?? ''),
                'verificationStatus' => (string)($signer->verificationstatus ?? ''),
                'verificationInfo' => $this->json_map($verificationinfo),
                'signMeta' => $this->json_map($signmeta),
            ];
        }

        return [
            'job' => [
                'id' => (int)$job->id,
                'documentUuid' => (string)($job->documentuuid ?? ''),
                'documentType' => (string)($job->documenttype ?? ''),
                'documentTitle' => (string)($job->documenttitle ?? ''),
                'courseId' => (int)($job->courseid ?? 0),
                'userId' => (int)($job->userid ?? 0),
                'draftHash' => (string)($job->drafthash ?? ''),
                'finalHash' => (string)($job->finalhash ?? ''),
            ],
            'draftPdfBase64' => base64_encode((string)$context['originalpdf']),
            'draftFileName' => (string)($context['originalfilename'] ?? ''),
            'draftSha256' => (string)($context['originalsha256'] ?? ''),
            'verifyUrl' => (string)($context['verifyurl'] ?? ''),
            'isFinal' => !empty($context['isfinal']),
            'manifest' => $this->json_map(is_array($context['manifest'] ?? null) ? $context['manifest'] : []),
            'signers' => $signers,
        ];
    }

    /**
     * Build outbound prepare request payload.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function build_prepare_request_payload(array $context): array {
        $signer = $context['signer'];

        return [
            'job' => [
                'id' => (int)$context['job']->id,
                'documentUuid' => (string)($context['job']->documentuuid ?? ''),
                'documentType' => (string)($context['job']->documenttype ?? ''),
                'documentTitle' => (string)($context['job']->documenttitle ?? ''),
                'courseId' => (int)($context['job']->courseid ?? 0),
                'userId' => (int)($context['job']->userid ?? 0),
                'draftHash' => (string)($context['job']->drafthash ?? ''),
                'finalHash' => (string)($context['job']->finalhash ?? ''),
            ],
            'draftPdfBase64' => base64_encode((string)$context['originalpdf']),
            'draftFileName' => (string)($context['originalfilename'] ?? ''),
            'draftSha256' => (string)($context['originalsha256'] ?? ''),
            'manifest' => $this->json_map(is_array($context['manifest'] ?? null) ? $context['manifest'] : []),
            'activeSigner' => [
                'signerRecordId' => (int)$signer->id,
                'order' => (int)($signer->signorder ?? 0),
                'name' => (string)($signer->signername ?? ''),
                'email' => (string)($signer->signeremail ?? ''),
                'position' => (string)($signer->signerposition ?? ''),
                'expectedIin' => (string)($signer->expectediin ?? ''),
            ],
            'signedSigners' => array_values(array_map(static function($record) {
                return [
                    'signerRecordId' => (int)$record->id,
                    'order' => (int)($record->signorder ?? 0),
                    'workflowStatus' => (string)($record->status ?? ''),
                    'rawCmsBase64' => (string)($record->rawcms ?? ''),
                    'verificationStatus' => (string)($record->verificationstatus ?? ''),
                ];
            }, array_filter((array)($context['signers'] ?? []), static function($record) {
                return !empty($record->rawcms);
            }))),
        ];
    }

    /**
     * POST JSON request.
     *
     * @param string $path
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post_json(string $path, array $payload): array {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'Failed to encode PAdES sidecar request payload.');
        }
        return $this->request_json('POST', $path, $body);
    }

    /**
     * Perform HTTP request.
     *
     * @param string $method
     * @param string $path
     * @param string|null $body
     * @return array<string,mixed>
     */
    private function request_json(string $method, string $path, ?string $body): array {
        $baseurl = rtrim(trim((string)get_config('local_ncasign', 'padesembedderbaseurl')), '/');
        if ($baseurl === '') {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'PAdES sidecar base URL is not configured.');
        }

        $timeout = (int)get_config('local_ncasign', 'padesembeddertimeout');
        if ($timeout <= 0) {
            $timeout = 30;
        }

        $ch = curl_init($baseurl . $path);
        if ($ch === false) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'Unable to initialise HTTP client for the PAdES sidecar.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
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
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'PAdES sidecar HTTP error: ' . $error);
        }
        if (!is_string($raw) || trim($raw) === '') {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'PAdES sidecar returned an empty response.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'PAdES sidecar returned invalid JSON: ' . $raw);
        }
        if ($httpcode >= 400) {
            $message = (string)($decoded['message'] ?? ('HTTP ' . $httpcode));
            if ($message === '' || $message === 'HTTP ' . $httpcode) {
                $message = $raw;
            }
            throw new \moodle_exception('verificationfailed', 'local_ncasign', '', 'PAdES sidecar error: ' . $message);
        }

        return $decoded;
    }

    /**
     * Normalize CMS base64 value.
     *
     * @param string $value
     * @return string
     */
    private function normalise_base64(string $value): string {
        $value = preg_replace('/-----BEGIN CMS-----|-----END CMS-----/u', '', trim($value)) ?? '';
        return preg_replace('/\s+/', '', $value) ?? '';
    }
}
