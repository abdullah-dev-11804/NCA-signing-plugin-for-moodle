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
$customcerttemplates = $templatemanager->get_available_customcert_templates();
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
    'customcerttemplates' => $customcerttemplates,
]);
$mform->set_data((object)[
    'userid' => optional_param('userid', 0, PARAM_INT),
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'usedemodata' => 1,
]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/ncasign/index.php'));
}

if ($data = $mform->get_data()) {
    $manager = new job_manager();
    $userid = (int)$data->userid;
    $courseid = (int)$data->courseid;
    $customcerttemplateid = (int)$data->customcerttemplateid;
    $selectedprofile = resolve_demo_profile($templatemanager, (int)$data->templateprofileid, $courseid);
    $generationprofile = build_demo_generation_profile($selectedprofile, $customcerttemplateid, (string)($data->documenttitle ?? ''));
    $signers = resolve_demo_signers((string)($data->signeremails ?? ''), $selectedprofile);
    $documentuuid = $manager->create_document_uuid();
    $verifyurl = $manager->build_verification_url_for_document_uuid($documentuuid);
    $certurl = $manager->build_certificate_url($courseid, $userid);

    $attachment = null;
    if ($generationprofile) {
        try {
            $generator = new document_generator();
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
            throw new moodle_exception('error', 'local_ncasign', $url, null, 'Demo draft generation failed: ' . $e->getMessage());
        }
    }

    if (!$attachment) {
        throw new moodle_exception('error', 'local_ncasign', $url, null, 'No customcert template could be resolved.');
    }

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
        $documentuuid
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

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();

/**
 * Resolve the selected preview profile.
 *
 * @param template_manager $templatemanager
 * @param int $templateprofileid
 * @param int $courseid
 * @return array<string,mixed>|null
 */
function resolve_demo_profile(template_manager $templatemanager, int $templateprofileid, int $courseid): ?array {
    if ($templateprofileid > 0) {
        return $templatemanager->get_profile($templateprofileid);
    }

    $profiles = $templatemanager->get_course_template_profiles($courseid);
    return $profiles ? reset($profiles) : null;
}

/**
 * Build the profile used for this demo generation.
 *
 * @param array<string,mixed>|null $profile
 * @param int $customcerttemplateid
 * @param string $documenttitle
 * @return array<string,mixed>
 */
function build_demo_generation_profile(?array $profile, int $customcerttemplateid, string $documenttitle): array {
    $profile = $profile ?? [
        'name' => 'Customcert demo preview',
        'renderer' => document_generator::DOC_CUSTOMCERT_TEMPLATE,
        'documenttype' => 'certificate',
        'documenttitle' => '',
        'templatepath' => '',
        'layoutconfig' => [],
        'signers' => [],
    ];

    $profile['renderer'] = document_generator::DOC_CUSTOMCERT_TEMPLATE;
    $profile['documenttype'] = 'certificate';
    if (trim($documenttitle) !== '') {
        $profile['documenttitle'] = trim($documenttitle);
    }

    $layoutconfig = is_array($profile['layoutconfig'] ?? null) ? $profile['layoutconfig'] : [];
    $layoutconfig['customcert']['templateid'] = $customcerttemplateid;
    $profile['layoutconfig'] = $layoutconfig;
    $profile['templatepath'] = 'customcert:' . $customcerttemplateid;
    $profile['customcerttemplateid'] = $customcerttemplateid;

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
