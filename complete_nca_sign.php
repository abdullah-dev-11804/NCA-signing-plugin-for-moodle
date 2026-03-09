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

if (trim($cmssignature) === '') {
    throw new moodle_exception('emptysignature', 'local_ncasign');
}

$decodedmeta = json_decode($payloadmeta, true);
if (!is_array($decodedmeta)) {
    $decodedmeta = [];
}

$payloadbytes = base64_decode($payloadb64, true);
if ($payloadbytes === false) {
    throw new moodle_exception('invalidpayload', 'local_ncasign');
}

$payloadsha256 = hash('sha256', $payloadbytes);
if ($payloadmode === 'job_metadata') {
    $payload = json_decode($payloadbytes, true);
    if (!is_array($payload) || (($payload['token'] ?? '') !== $token)) {
        throw new moodle_exception('invalidpayload', 'local_ncasign');
    }
} else if ($payloadmode === 'certificate_pdf') {
    $certificate = $manager->get_job_certificate_binary((int)$job->id);
    if (!$certificate || $certificate['sha256'] !== $payloadsha256) {
        throw new moodle_exception('invalidpayload', 'local_ncasign');
    }
} else {
    throw new moodle_exception('invalidpayload', 'local_ncasign');
}

$signaturefilename = $manager->store_signer_cms_signature((int)$job->id, (int)$signer->id, $cmssignature);

$meta = [
    'mode' => 'ncalayer_real_cms',
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
    'server_received_at' => time(),
];

$manager->mark_signer_signed($token, 'ncalayer_real', $meta);

redirect(
    new moodle_url('/local/ncasign/sign.php', ['token' => $token]),
    get_string('signedok', 'local_ncasign'),
    1,
    \core\output\notification::NOTIFY_SUCCESS
);
