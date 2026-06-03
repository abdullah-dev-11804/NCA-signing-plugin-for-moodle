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

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/form/demo_job_form.php');

ini_set('log_errors', '1');
ini_set('error_log', '/tmp/ncasign-debug.log');
error_log('NCASIGN_CANARY forced log file active');

use local_ncasign\form\demo_job_form;
use local_ncasign\local\document_generator;
use local_ncasign\local\document_storage;
use local_ncasign\local\job_manager;
use local_ncasign\local\template_manager;

require_login();
$context = context_system::instance();
require_capability('local/ncasign:managejobs', $context);

$url = new moodle_url('/local/ncasign/create_demo_job.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('createdemojob', 'local_ncasign'));
$PAGE->set_heading(get_string('createdemojob', 'local_ncasign'));

$templatemanager = new template_manager();
$profiles = $templatemanager->get_all_profiles();
$profileoptions = [];
foreach ($profiles as $profile) {
    $courseids = !empty($profile['courseids']) && is_array($profile['courseids'])
        ? implode(',', array_map('intval', $profile['courseids']))
        : get_string('none');
    $status = !empty($profile['active'])
        ? get_string('templateactive', 'local_ncasign')
        : get_string('templateinactive', 'local_ncasign');
    $profileoptions[(int)$profile['id']] = '#' . (int)$profile['id'] . ' - ' . (string)$profile['name'] .
        ' (' . get_string('courseid', 'local_ncasign') . ': ' . $courseids . ', ' . $status . ')';
}

$mform = new demo_job_form($url, [
    'profileoptions' => $profileoptions,
]);
$mform->set_data((object)[
    'userid' => optional_param('userid', 0, PARAM_INT),
    'templateprofileid' => optional_param('templateprofileid', 0, PARAM_INT),
    'usedemodata' => 1,
]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/ncasign/index.php'));
}

if ($data = $mform->get_data()) {
    $manager = new job_manager();
    $userid = (int)$data->userid;
    $selectedprofile = resolve_demo_profile($templatemanager, (int)$data->templateprofileid);
    $courseid = resolve_first_profile_courseid($selectedprofile);
    error_log(
        'NCASIGN_CANARY create_demo_job submitted' .
        ' userid=' . $userid .
        ' profileid=' . (int)($data->templateprofileid ?? 0) .
        ' resolved_courseid=' . $courseid .
        ' usedemodata=' . (!empty($data->usedemodata) ? '1' : '0') .
        ' autosign=' . (!empty($data->autosigndemo) ? '1' : '0')
    );
    if ($courseid <= 0) {
        \core\notification::error(get_string('demotemplateprofile_nocourse', 'local_ncasign'));
    } else {
        $generationprofile = build_demo_generation_profile($selectedprofile, (string)($data->documenttitle ?? ''));
        $signers = resolve_demo_signers((string)($data->signeremails ?? ''), $selectedprofile);
        $documentuuid = $manager->create_document_uuid();
        $verifyurl = $manager->build_verification_url_for_document_uuid($documentuuid);
        $certurl = $manager->build_certificate_url($courseid, $userid);

        $attachment = null;
        if ($generationprofile) {
            try {
                $generator = new document_generator();
                error_log(
                    'NCASIGN_CANARY create_demo_job before_generate' .
                    ' userid=' . $userid .
                    ' courseid=' . $courseid .
                    ' renderer=' . (string)($generationprofile['renderer'] ?? '') .
                    ' customcerttemplateid=' . (int)($generationprofile['customcerttemplateid'] ?? 0) .
                    ' signer_count=' . count($signers)
                );
                $draft = $generator->generate_draft_from_profile(
                    $userid,
                    $courseid,
                    $generationprofile,
                    [
                        'documentuuid' => $documentuuid,
                        'verifyurl' => $verifyurl,
                        'signers' => $signers,
                        'use_demo_data' => !empty($data->usedemodata),
                    ]
                );
                error_log(
                    'NCASIGN_CANARY create_demo_job after_generate' .
                    ' userid=' . $userid .
                    ' filename=' . (string)($draft['filename'] ?? '') .
                    ' bytes=' . strlen((string)($draft['content'] ?? '')) .
                    ' preview_userfullname_present=' .
                    (!empty($draft['previewdata']['userfullname']) ? '1' : '0') .
                    ' preview_userfullname_length=' .
                    \core_text::strlen((string)($draft['previewdata']['userfullname'] ?? '')) .
                    ' preview_userfullname_hash=' .
                    (!empty($draft['previewdata']['userfullname'])
                        ? hash('sha256', (string)$draft['previewdata']['userfullname'])
                        : '-')
                );
                $attachment = [
                    'filename' => (string)$draft['filename'],
                    'content' => (string)$draft['content'],
                    'source' => 'local_generated_demo_draft',
                    'manifest' => !empty($draft['finalizationmanifest']) && is_array($draft['finalizationmanifest'])
                        ? $draft['finalizationmanifest']
                        : null,
                    'documenttype' => (string)($draft['documenttype'] ?? 'certificate'),
                    'documenttitle' => (string)($draft['documenttitle'] ?? ($data->documenttitle ?? '')),
                    'profileid' => $selectedprofile ? (int)$selectedprofile['id'] : null,
                ];
            } catch (Throwable $e) {
                \core\notification::error(get_string('demodraftgenerationfailed', 'local_ncasign', $e->getMessage()));
            }
        }

        if (!$attachment) {
            if (!$generationprofile) {
                \core\notification::error(get_string('democustomcerttemplatemissing', 'local_ncasign'));
            }
        } else {

            $jobid = $manager->create_job(
                $userid,
                $courseid,
                $certurl,
                $signers,
                null,
                (string)$attachment['documenttype'],
                (string)$attachment['documenttitle'],
                false,
                $attachment['profileid'],
                $documentuuid,
                job_manager::JOB_ORIGIN_DEMO
            );

            if ($attachment['source'] === 'local_generated_demo_draft') {
                $storage = new document_storage();
                $storedpath = $storage->store_pending_draft($jobid, (string)$attachment['filename'], (string)$attachment['content']);
                $source = 'local_generated_demo_draft:' . $storedpath;
            } else {
                $source = (string)$attachment['source'];
            }

            $manager->attach_certificate_binary_to_job(
                $jobid,
                (string)$attachment['filename'],
                (string)$attachment['content'],
                $source,
                $attachment['manifest']
            );

            $autosigned = false;
            if (!empty($data->autosigndemo) && $manager->can_server_autosign()) {
                try {
                    $autosigned = $manager->try_server_autosign_job($jobid);
                } catch (Throwable $e) {
                    error_log('local_ncasign: demo auto-sign failed for job ' . $jobid . ': ' . $e->getMessage());
                }
            }

            if (!$autosigned) {
                $manager->notify_signers_for_job($jobid);
            }

            redirect(
                new moodle_url('/local/ncasign/job.php', ['id' => $jobid]),
                get_string($autosigned ? 'demojobcreated_autosigned' : 'demojobcreated', 'local_ncasign', $jobid),
                2
            );
        }
    }
}

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();

/**
 * Resolve the selected preview profile.
 *
 * @param template_manager $templatemanager
 * @param int $templateprofileid
 * @return array<string,mixed>|null
 */
function resolve_demo_profile(template_manager $templatemanager, int $templateprofileid): ?array {
    return $templateprofileid > 0 ? $templatemanager->get_profile($templateprofileid) : null;
}

/**
 * Resolve the first mapped course id from a template profile.
 *
 * @param array<string,mixed>|null $profile
 * @return int
 */
function resolve_first_profile_courseid(?array $profile): int {
    if (!$profile || empty($profile['courseids']) || !is_array($profile['courseids'])) {
        return 0;
    }

    foreach ($profile['courseids'] as $courseid) {
        $courseid = (int)$courseid;
        if ($courseid > 0) {
            return $courseid;
        }
    }

    return 0;
}

/**
 * Build the profile used for this demo generation.
 *
 * @param array<string,mixed>|null $profile
 * @param string $documenttitle
 * @return array<string,mixed>|null
 */
function build_demo_generation_profile(?array $profile, string $documenttitle): ?array {
    if (!$profile) {
        return null;
    }

    $profile['renderer'] = document_generator::DOC_CUSTOMCERT_TEMPLATE;
    $profile['documenttype'] = 'certificate';
    if (trim($documenttitle) !== '') {
        $profile['documenttitle'] = trim($documenttitle);
    }

    return $profile;
}

/**
 * Resolve demo signers from an email override or the selected profile.
 *
 * @param string $rawemails
 * @param array<string,mixed>|null $profile
 * @return array<int,array<string,mixed>>
 */
function resolve_demo_signers(string $rawemails, ?array $profile): array {
    $emails = demo_job_form::parse_signer_emails($rawemails);
    if ($emails) {
        $signers = [];
        foreach ($emails as $email => $valid) {
            if ($valid) {
                $signers[] = ['email' => $email, 'name' => $email];
            }
        }
        return $signers;
    }

    return $profile && is_array($profile['signers'] ?? null) ? $profile['signers'] : [];
}
