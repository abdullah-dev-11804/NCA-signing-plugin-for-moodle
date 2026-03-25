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
$url = new moodle_url('/local/ncasign/sign.php', ['token' => $token]);
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('signtitle', 'local_ncasign'));
$PAGE->set_heading(get_string('signtitle', 'local_ncasign'));

$manager = new \local_ncasign\local\job_manager();
$row = $manager->get_signer_by_token($token);

echo $OUTPUT->header();

if (!$row) {
    echo $OUTPUT->notification(get_string('invalidtoken', 'local_ncasign'), \core\output\notification::NOTIFY_ERROR);
    echo $OUTPUT->footer();
    exit;
}

$signer = $row['signer'];
$job = $row['job'];
$activesigner = $manager->get_active_pending_signer((int)$job->id);
$isactive = ($signer->status === \local_ncasign\local\job_manager::SIGNER_PENDING)
    && $activesigner
    && ((int)$activesigner->id === (int)$signer->id);

if ($signer->status !== \local_ncasign\local\job_manager::SIGNER_PENDING) {
    echo $OUTPUT->notification(get_string('alreadysigned', 'local_ncasign'), \core\output\notification::NOTIFY_INFO);
} else if (!$isactive) {
    echo $OUTPUT->notification(get_string('signernotactive', 'local_ncasign'), \core\output\notification::NOTIFY_WARNING);
}

echo html_writer::tag('p', get_string('signinstructions', 'local_ncasign'));
echo html_writer::tag('p', get_string('ncarunninghint', 'local_ncasign'));
echo html_writer::start_tag('ul');
echo html_writer::tag('li', 'Signer email: ' . s($signer->signeremail));
echo html_writer::tag('li', 'Signer name: ' . s((string)($signer->signername ?? $signer->signeremail)));
echo html_writer::tag('li', 'Signer position: ' . s((string)($signer->signerposition ?? 'Commission member')));
echo html_writer::tag('li', 'Signer order: ' . (int)$signer->signorder . ' of ' . max(1, count($manager->get_signer_records((int)$job->id))));
echo html_writer::tag('li', 'Student user ID: ' . (int)$job->userid);
echo html_writer::tag('li', 'Course ID: ' . (int)$job->courseid);
echo html_writer::tag('li', 'Document: ' . s((string)$job->documenttitle));
echo html_writer::tag('li', 'Document type: ' . s(ucfirst((string)$job->documenttype)));
if ($manager->has_job_original_pdf((int)$job->id)) {
    $drafturl = new moodle_url('/local/ncasign/draft.php', ['token' => $token]);
    echo html_writer::tag('li', get_string('draftpdf', 'local_ncasign') . ': ' . html_writer::link($drafturl, 'Open draft PDF'));
}
if ($manager->has_job_signed_pdf((int)$job->id)) {
    $signedurl = new moodle_url('/local/ncasign/draft.php', ['token' => $token, 'type' => 'signedpdf']);
    echo html_writer::tag('li', get_string('currentsignedpdf', 'local_ncasign') . ': ' . html_writer::link($signedurl, 'Open current signed PDF'));
}
echo html_writer::tag('li', 'Stored source: ' . s((string)$job->certificateurl));
echo html_writer::tag('li', 'Manual deadline: ' . userdate((int)$job->manualdeadline));
echo html_writer::end_tag('ul');

if ($signer->status === \local_ncasign\local\job_manager::SIGNER_PENDING && $isactive) {
    $ncalayerekuraw = trim((string)get_config('local_ncasign', 'ncalayerekus'));
    $ncalayerekuoids = [];
    if ($ncalayerekuraw !== '') {
        $ncalayerekuoids = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $ncalayerekuraw))));
        $signingeku = '1.3.6.1.5.5.7.3.4';
        if (!in_array($signingeku, $ncalayerekuoids, true)) {
            array_unshift($ncalayerekuoids, $signingeku);
        }
    }
    $ncalayerekuoidsjson = json_encode($ncalayerekuoids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ncalayerusetsa = (int)get_config('local_ncasign', 'ncalayerusetsa') ? 'true' : 'false';

    $payloadmode = 'document_pdf';
    $payloadmeta = [];
    $signingpayload = $manager->get_job_signing_payload_binary((int)$job->id);
    if ($signingpayload) {
        $payloadb64 = base64_encode($signingpayload['content']);
        $payloadmeta = [
            'filename' => $signingpayload['filename'],
            'sha256' => $signingpayload['sha256'],
            'filesize' => $signingpayload['filesize'],
            'sourcearea' => $signingpayload['sourcearea'] ?? '',
        ];
    }
    $payloadmetajson = json_encode($payloadmeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    echo html_writer::start_div('mb-3');
    echo html_writer::tag('label', get_string('storage', 'local_ncasign'), ['for' => 'nca-storage']);
    echo html_writer::start_tag('div');
    echo html_writer::select(
        ['PKCS12' => 'PKCS12'],
        'storage',
        'PKCS12',
        false,
        ['id' => 'nca-storage']
    );
    echo html_writer::end_tag('div');
    echo html_writer::end_div();

    echo html_writer::start_div('mb-3');
    echo html_writer::tag('button', get_string('loadtokens', 'local_ncasign'), [
        'type' => 'button',
        'id' => 'loadtokensbtn',
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_tag('div', ['id' => 'nca-status', 'style' => 'margin-bottom:10px;color:#555;']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['id' => 'nca-bundles', 'style' => 'margin-bottom:10px;color:#555;font-size:0.95em;']);
    echo html_writer::end_tag('div');

    if ($signingpayload) {
        echo html_writer::tag(
            'div',
            'Signing mode: actual document PDF bytes (' . s($signingpayload['filename']) . ', SHA256 ' . s($signingpayload['sha256']) . ', source ' . s((string)($payloadmeta['sourcearea'] ?? '')) . ')',
            ['style' => 'margin-bottom:10px;color:#0a5a0a;']
        );
    } else {
        echo $OUTPUT->notification(get_string('nodocumentpayload', 'local_ncasign'), \core\output\notification::NOTIFY_ERROR);
    }

    if ($signingpayload) {
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new moodle_url('/local/ncasign/complete_nca_sign.php'))->out(false),
            'id' => 'nca-sign-form',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => s($token)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'payloadb64', 'id' => 'payloadb64', 'value' => s($payloadb64)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'payloadmode', 'id' => 'payloadmode', 'value' => s($payloadmode)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'payloadmeta', 'id' => 'payloadmeta', 'value' => s($payloadmetajson)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmssignature', 'id' => 'cmssignature', 'value' => '']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'storageused', 'id' => 'storageused', 'value' => '']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ncamodule', 'id' => 'ncamodule', 'value' => '']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ncamessage', 'id' => 'ncamessage', 'value' => '']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ncaresponsecode', 'id' => 'ncaresponsecode', 'value' => '']);
        echo html_writer::empty_tag('input', ['type' => 'button', 'value' => get_string('signwithnca', 'local_ncasign'), 'id' => 'signnca', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');

    $js = <<<JS
(function() {
    let ws = null;
    let pendingCallback = null;
    const modules = ['kz.gov.pki.knca.commonUtils', 'kz.gov.pki.knca.basics'];
    const signerEkuOids = {$ncalayerekuoidsjson};
    const useTsaProfile = {$ncalayerusetsa};
    const statusEl = document.getElementById('nca-status');
    const bundleEl = document.getElementById('nca-bundles');
    const storageEl = document.getElementById('nca-storage');
    const signBtn = document.getElementById('signnca');

    function setStatus(message, isError) {
        statusEl.style.color = isError ? '#b00020' : '#2f4f4f';
        statusEl.textContent = message;
    }

    function ensureWs(onReady) {
        if (ws && ws.readyState === WebSocket.OPEN) {
            onReady();
            return;
        }
        ws = new WebSocket('wss://127.0.0.1:13579/');
        ws.onopen = function() {
            setStatus('NCALayer connected.', false);
            onReady();
            loadBundleInfo();
        };
        ws.onclose = function() {
            if (!statusEl.textContent) {
                setStatus('NCALayer is not running.', true);
            }
        };
        ws.onerror = function() {
            setStatus('Failed to connect to NCALayer. Start NCALayer and refresh.', true);
        };
        ws.onmessage = function(evt) {
            let result = null;
            try {
                result = JSON.parse(evt.data);
            } catch (e) {
                setStatus('Invalid response from NCALayer.', true);
                return;
            }
            if (pendingCallback) {
                const cb = pendingCallback;
                pendingCallback = null;
                cb({
                    raw: result,
                    code: result.code,
                    message: result.message,
                    responseObject: result.responseObject,
                    status: result.status,
                    result: result.result
                });
            }
        };
    }

    function sendRequest(payload, callback) {
        ensureWs(function() {
            pendingCallback = callback;
            ws.send(JSON.stringify(payload));
        });
    }

    function safeString(v) {
        if (typeof v === 'string' && v.trim() !== '') {
            return v;
        }
        try {
            return JSON.stringify(v);
        } catch (e) {
            return String(v);
        }
    }

    function renderBundleInfo(bundles) {
        if (!bundleEl || !bundles || typeof bundles !== 'object') {
            return;
        }
        const keys = [
            'kz.gov.pki.knca.applet.knca_applet',
            'kz.gov.pki.api.layer.NCALayerServices',
            'kz.gov.pki.osgi.layer.websocket'
        ];
        const items = [];
        keys.forEach(function(key) {
            if (bundles[key]) {
                items.push(key + ': ' + bundles[key]);
            }
        });
        if (items.length) {
            bundleEl.textContent = 'NCALayer bundles: ' + items.join(' | ');
        }
    }

    function loadBundleInfo() {
        sendRequest({
            module: 'kz.gov.pki.ncalayerservices.accessory',
            method: 'getBundles'
        }, function(result) {
            const raw = result.raw || {};
            const bundles = raw.result || result.result || raw.responseObject || result.responseObject || null;
            renderBundleInfo(bundles);
        });
    }

    function callWithModuleFallback(method, args, done) {
        const errors = [];
        function tryModule(index) {
            if (index >= modules.length) {
                done({
                    ok: false,
                    module: null,
                    result: errors.length ? errors[errors.length - 1].result : null,
                    errors: errors
                });
                return;
            }

            const moduleName = modules[index];
            sendRequest({
                module: moduleName,
                method: method,
                args: args || []
            }, function(result) {
                if (String(result.code) === '200') {
                    done({
                        ok: true,
                        module: moduleName,
                        result: result,
                        errors: errors
                    });
                    return;
                }
                errors.push({module: moduleName, result: result});
                tryModule(index + 1);
            });
        }
        tryModule(0);
    }

    document.getElementById('loadtokensbtn').addEventListener('click', function() {
        setStatus('Loading available storages from NCALayer...', false);
        callWithModuleFallback('getActiveTokens', [], function(resp) {
            if (!resp.ok) {
                const last = resp.result || {};
                const detail = safeString(last.message || last);
                setStatus('NCALayer error (getActiveTokens): code=' + safeString(last.code) + ', detail=' + detail, true);
                return;
            }
            const result = resp.result;
            const items = result.responseObject || [];
            while (storageEl.options.length > 0) {
                storageEl.remove(0);
            }
            storageEl.add(new Option('PKCS12', 'PKCS12'));
            for (let i = 0; i < items.length; i++) {
                storageEl.add(new Option(items[i], items[i]));
            }
            setStatus('Storages loaded from module "' + resp.module + '". Select storage and click "Sign with NCALayer".', false);
        });
    });

    signBtn.addEventListener('click', function() {
        signBtn.disabled = true;
        setStatus('Requesting signature from NCALayer...', false);

        const storage = storageEl.value || 'PKCS12';
        const payloadb64 = document.getElementById('payloadb64').value;

        const signArgs = {
            allowedStorages: [storage],
            format: 'cms',
            data: payloadb64,
            signingParams: {
                decode: true,
                encapsulate: false,
                digested: false
            },
            signerParams: {
                extKeyUsageOids: [],
                chain: []
            },
            locale: 'ru'
        };
        if (useTsaProfile) {
            signArgs.signingParams.tsaProfile = {};
        }
        if (Array.isArray(signerEkuOids) && signerEkuOids.length > 0) {
            signArgs.signerParams.extKeyUsageOids = signerEkuOids;
        }

        sendRequest({
            module: 'kz.gov.pki.knca.basics',
            method: 'sign',
            args: signArgs
        }, function(result) {
            const raw = result.raw || {};
            const cms = raw.result || result.result || raw.responseObject || result.responseObject || '';
            const isOk = (String(raw.status || result.status).toLowerCase() === 'ok') || String(result.code) === '200';

            document.getElementById('ncaresponsecode').value = String(result.code || raw.status || '');
            document.getElementById('ncamessage').value = safeString(result.message || raw.message || raw);
            document.getElementById('storageused').value = storage;
            document.getElementById('ncamodule').value = 'kz.gov.pki.knca.basics';

            if (!isOk || !cms) {
                signBtn.disabled = false;
                setStatus(
                    'Signing failed: detail=' + safeString(result.message || raw.message || raw) +
                    '; raw=' + safeString(raw),
                    true
                );
                return;
            }

            document.getElementById('cmssignature').value = cms;
            setStatus('Detached CMS created via module "kz.gov.pki.knca.basics". Sending to server for verification...', false);
            document.getElementById('nca-sign-form').submit();
        });
    });
})();
JS;
        $PAGE->requires->js_init_code($js);
    }
}

echo $OUTPUT->footer();
