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

$token = required_param('token', PARAM_ALPHANUMEXT);
$payloadb64 = required_param('payloadb64', PARAM_RAW_TRIMMED);
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
if ($signer->status !== \local_ncasign\local\job_manager::SIGNER_PENDING) {
    redirect(
        new moodle_url('/local/ncasign/sign.php', ['token' => $token]),
        get_string('alreadysigned', 'local_ncasign'),
        2
    );
}

$payloadjson = base64_decode($payloadb64, true);
$payload = $payloadjson !== false ? json_decode($payloadjson, true) : null;

if (!is_array($payload) || (($payload['token'] ?? '') !== $token)) {
    throw new moodle_exception('invalidpayload', 'local_ncasign');
}

if (trim($cmssignature) === '') {
    throw new moodle_exception('emptysignature', 'local_ncasign');
}

$meta = [
    'mode' => 'ncalayer_real_cms',
    'storage' => $storageused,
    'module' => $ncamodule,
    'ip' => getremoteaddr(null),
    'nca_response_code' => $ncaresponsecode,
    'nca_message' => $ncamessage,
    'payload_sha256' => hash('sha256', $payloadjson),
    'payload' => $payload,
    'cms_sha256' => hash('sha256', $cmssignature),
    'cms_length' => core_text::strlen($cmssignature),
    'cms_preview' => core_text::substr($cmssignature, 0, 120),
    'server_received_at' => time(),
];

$manager->mark_signer_signed($token, 'ncalayer_real', $meta);

redirect(
    new moodle_url('/local/ncasign/sign.php', ['token' => $token]),
    get_string('signedok', 'local_ncasign'),
    1,
    \core\output\notification::NOTIFY_SUCCESS
);
