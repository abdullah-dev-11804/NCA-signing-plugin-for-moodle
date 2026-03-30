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

require_once(__DIR__ . '/../../config.php');

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/ncasign/complete_nca_sign.php'));

$token = required_param('token', PARAM_ALPHANUMEXT);
$payloadb64 = required_param('payloadb64', PARAM_RAW_TRIMMED);
$payloadmode = optional_param('payloadmode', 'job_metadata', PARAM_ALPHANUMEXT);
$payloadmeta = optional_param('payloadmeta', '{}', PARAM_RAW_TRIMMED);
$cmssignature = required_param('cmssignature', PARAM_RAW);
$storageused = optional_param('storageused', 'UNKNOWN', PARAM_TEXT);
$ncamodule = optional_param('ncamodule', 'UNKNOWN', PARAM_TEXT);
$ncamessage = optional_param('ncamessage', '', PARAM_RAW_TRIMMED);
$ncaresponsecode = optional_param('ncaresponsecode', '', PARAM_RAW_TRIMMED);

$manager = new \local_ncasign\local\job_manager();
$row = $manager->get_signer_by_token($token);
if (!$row) {
    throw new moodle_exception('invalidtoken', 'local_ncasign');
}

$signer = $row['signer'];
$job = $row['job'];
if ($signer->status !== \local_ncasign\local\job_manager::SIGNER_PENDING) {
    redirect(
        new moodle_url('/local/ncasign/sign.php', ['token' => $token]),
        get_string('alreadysigned', 'local_ncasign'),
        2
    );
}
if (!$manager->is_signer_active($signer)) {
    throw new moodle_exception('signernotactive', 'local_ncasign');
}

if (trim($cmssignature) === '') {
    throw new moodle_exception('emptysignature', 'local_ncasign');
}

$cmssignature = preg_replace('/-----BEGIN CMS-----|-----END CMS-----|\s+/u', '', trim($cmssignature)) ?? '';
if ($cmssignature === '') {
    throw new moodle_exception('emptysignature', 'local_ncasign');
}

$decodedmeta = json_decode($payloadmeta, true);
if (!is_array($decodedmeta)) {
    $decodedmeta = [];
}

$normalisedpayloadb64 = preg_replace('/\s+/', '', trim($payloadb64)) ?? '';
$payloadbytes = base64_decode($normalisedpayloadb64, true);
if ($payloadbytes === false || $normalisedpayloadb64 === '') {
    throw new moodle_exception('invalidpayload', 'local_ncasign');
}

$document = $manager->get_job_signing_payload_binary((int)$job->id);
if (!$document) {
    throw new moodle_exception('invalidpayload', 'local_ncasign');
}

$payloadsha256 = hash('sha256', $payloadbytes);
if ($payloadmode === 'certificate_pdf' || $payloadmode === 'document_pdf') {
    if ($document['sha256'] !== $payloadsha256) {
        throw new moodle_exception('invalidpayload', 'local_ncasign');
    }
} else if ($payloadmode === 'prepared_pdf_digest' || $payloadmode === 'prepared_pdf_dtbs') {
    $finalizer = \local_ncasign\local\pades_finalizer_factory::create();
    if (!$finalizer->supports_prepare_phase()) {
        throw new moodle_exception('invalidpayload', 'local_ncasign');
    }
    $prepared = $finalizer->prepare([
        'job' => $job,
        'originalpdf' => $document['content'],
        'originalfilename' => $document['filename'],
        'originalsha256' => $document['sha256'],
        'manifest' => $manager->get_job_finalization_manifest($job),
        'signer' => $signer,
        'signers' => $manager->get_signer_records((int)$job->id),
    ]);
    $expectedpayloadb64 = preg_replace('/\s+/', '', (string)($prepared['signablepayloadb64'] ?? '')) ?? '';
    if ($expectedpayloadb64 === '' || !hash_equals($expectedpayloadb64, $normalisedpayloadb64)) {
        throw new moodle_exception('invalidpayload', 'local_ncasign');
    }
    $payloadbytes = base64_decode($expectedpayloadb64, true);
    if ($payloadbytes === false) {
        throw new moodle_exception('invalidpayload', 'local_ncasign');
    }
    $payloadsha256 = hash('sha256', $payloadbytes);
    $decodedmeta['prepare'] = array_merge((array)($decodedmeta['prepare'] ?? []), [
        'sessionid' => (string)($prepared['sessionid'] ?? ''),
        'fieldname' => (string)($prepared['fieldname'] ?? ''),
        'payloadsha256' => (string)($prepared['signablepayloadsha256'] ?? ''),
        'signingtime' => (string)($prepared['signingtime'] ?? ''),
        'backend' => (string)($prepared['backend'] ?? ''),
    ]);
} else {
    throw new moodle_exception('invalidpayload', 'local_ncasign');
}

$signingmethod = ($payloadmode === 'prepared_pdf_digest' || $payloadmode === 'prepared_pdf_dtbs')
    ? 'ncalayer_basics_detached_hash_for_pades+ncanode_verify'
    : 'ncalayer_basics_detached_cms_tsa_requested+ncanode_verify';
$verificationservice = \local_ncasign\local\signature_backend_factory::create();
$expectediin = preg_replace('/\D+/', '', (string)($signer->expectediin ?? ''));
$verification = $verificationservice->verify_detached_cms($cmssignature, $payloadbytes, $expectediin);
$signaturefilename = $manager->store_signer_cms_signature((int)$job->id, (int)$signer->id, $cmssignature);

$meta = [
    'mode' => 'ncalayer_real_cms_detached_verified',
    'signer_order' => (int)$signer->signorder,
    'signer_name' => (string)($signer->signername ?? $signer->signeremail),
    'signer_position' => (string)($signer->signerposition ?? ''),
    'expected_iin' => $expectediin,
    'verified_signer_iin' => (string)($verification['signeriin'] ?? ''),
    'payload_mode' => $payloadmode,
    'storage' => $storageused,
    'module' => $ncamodule,
    'ip' => getremoteaddr(null),
    'nca_response_code' => $ncaresponsecode,
    'nca_message' => $ncamessage,
    'payload_sha256' => $payloadsha256,
    'payload_meta' => $decodedmeta,
    'cms_sha256' => hash('sha256', $cmssignature),
    'cms_length' => core_text::strlen($cmssignature),
    'cms_preview' => core_text::substr($cmssignature, 0, 120),
    'signature_filename' => $signaturefilename,
    'verification_info' => $verification['verifyinfo'] ?? '',
    'certificate_info' => $verification['certificateinfo'] ?? [],
    'certificate_validation' => $verification['validation'] ?? [],
    'signing_time' => $verification['signingtime'] ?? null,
    'server_received_at' => time(),
];

if (!$manager->mark_signer_signed($token, 'ncalayer_real', $meta, [
    'rawcms' => $verification['cms_base64'] ?? trim($cmssignature),
    'signercertificate' => $verification['certificate'] ?? null,
    'signeriin' => $verification['signeriin'] ?? null,
    'ocspresponse' => !empty($verification['validation']['revocations'])
        ? json_encode($verification['validation']['revocations'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null,
    'signingmethod' => $signingmethod,
    'verificationstatus' => 'verified',
    'verificationinfo' => json_encode([
        'verifyinfo' => $verification['verifyinfo'] ?? '',
        'certificateinfo' => $verification['certificateinfo'] ?? [],
        'validation' => $verification['validation'] ?? [],
        'signingtime' => $verification['signingtime'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
])) {
    throw new moodle_exception('signernotactive', 'local_ncasign');
}

redirect(
    new moodle_url('/local/ncasign/sign.php', ['token' => $token]),
    get_string('signedok', 'local_ncasign'),
    1,
    \core\output\notification::NOTIFY_SUCCESS
);
