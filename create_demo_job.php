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
            $smartfielderrors = get_demo_customcert_non_text_smart_fields($generationprofile);
            if ($smartfielderrors) {
                \core\notification::warning(get_string(
                    'democustomcertsmartfieldwrongtype',
                    'local_ncasign',
                    implode(', ', $smartfielderrors)
                ));
                error_log(
                    'NCASIGN_CANARY create_demo_job blocked_non_text_smart_fields' .
                    ' profileid=' . (int)($generationprofile['id'] ?? 0) .
                    ' customcerttemplateid=' . (int)($generationprofile['customcerttemplateid'] ?? 0) .
                    ' fields=' . implode(',', $smartfielderrors)
                );
            } else {
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
 * Find NCA Sign smart fields that were added as non-text customcert elements.
 *
 * @param array<string,mixed> $profile
 * @return string[]
 */
function get_demo_customcert_non_text_smart_fields(array $profile): array {
    global $DB;

    $templateid = (int)($profile['customcerttemplateid'] ?? 0);
    if ($templateid <= 0) {
        return [];
    }

    $manager = $DB->get_manager();
    if (!$manager->table_exists(new xmldb_table('customcert_pages'))
        || !$manager->table_exists(new xmldb_table('customcert_elements'))) {
        return [];
    }

    $smartfields = get_demo_customcert_smart_field_names($profile);
    if (!$smartfields) {
        return [];
    }

    $sql = "SELECT e.id, e.name, e.element, e.data
              FROM {customcert_elements} e
              JOIN {customcert_pages} p ON p.id = e.pageid
             WHERE p.templateid = :templateid";
    $elements = $DB->get_records_sql($sql, ['templateid' => $templateid]);

    $matches = [];
    foreach ($elements as $element) {
        $elementtype = trim((string)($element->element ?? ''));

        $candidates = get_demo_customcert_element_field_candidates($element);
        foreach ($candidates as $candidate) {
            if ($candidate === '' || !isset($smartfields[$candidate])) {
                continue;
            }
            if (in_array($elementtype, get_demo_customcert_allowed_smart_field_element_types($candidate), true)) {
                continue;
            }
            $matches[$candidate] = $smartfields[$candidate] . ' (' . $elementtype . ' element)';
        }
    }

    ksort($matches);
    return array_values($matches);
}

/**
 * Return customcert element types that are valid for a smart field.
 *
 * @param string $field
 * @return string[]
 */
function get_demo_customcert_allowed_smart_field_element_types(string $field): array {
    if (in_array($field, ['user_full_name', 'userfullname'], true)) {
        return ['text', 'studentname'];
    }

    return ['text'];
}

/**
 * Return possible smart field tokens from a customcert element.
 *
 * @param \stdClass $element
 * @return string[]
 */
function get_demo_customcert_element_field_candidates(\stdClass $element): array {
    $rawvalues = [
        (string)($element->name ?? ''),
        (string)($element->data ?? ''),
    ];

    $decoded = json_decode((string)($element->data ?? ''), true);
    if (is_array($decoded)) {
        foreach (flatten_demo_customcert_element_data($decoded) as $value) {
            $rawvalues[] = $value;
        }
    }

    $candidates = [];
    foreach ($rawvalues as $value) {
        $normalised = normalise_demo_customcert_element_name($value);
        if ($normalised !== '') {
            $candidates[$normalised] = $normalised;
        }
    }

    return array_values($candidates);
}

/**
 * Flatten decoded customcert element data into scalar strings.
 *
 * @param array<mixed> $data
 * @return string[]
 */
function flatten_demo_customcert_element_data(array $data): array {
    $values = [];
    foreach ($data as $value) {
        if (is_array($value)) {
            $values = array_merge($values, flatten_demo_customcert_element_data($value));
        } else if (is_scalar($value)) {
            $values[] = (string)$value;
        }
    }

    return $values;
}

/**
 * Return smart field names supported by the customcert text override path.
 *
 * @param array<string,mixed> $profile
 * @return array<string,string>
 */
function get_demo_customcert_smart_field_names(array $profile): array {
    $fields = [
        'protocol_number',
        'protocolnumber',
        'company_name',
        'clientcompanyname',
        'issue_date_kazakh',
        'issuedatekz',
        'issue_date_russian',
        'issuedateru',
        'expirydatekz',
        'expirydateru',
        'expirydateiso',
        'validityperioddays',
        'comission_chair',
        'commission_chair',
        'commision_member_1',
        'commission_member_1',
        'commision_member_2',
        'comission_member_2',
        'commission_member_2',
        'order_date_kazakh',
        'orderkz',
        'order_date_russian',
        'orderru',
        'protocol_type_kazakh',
        'protocoltypekz',
        'protocol_type_russian',
        'protocoltyperu',
        'user_full_name',
        'userfullname',
        'user_job_title',
        'userjobtitle',
        'course_completion_status',
        'completionstatus',
        'certificate_number',
        'certificatenumber',
        'document_number_cer',
        'book_id',
        'bookid',
        'course_completion_book_id',
        'document_number_cid',
        'chairinitials',
        'member1initials',
        'member2initials',
        'commision_chair_initials_ss',
        'comission_chair_initials_ss',
        'commission_chair_initials_ss',
        'commision_member_1_initials_ss',
        'commission_member_1_initials_ss',
        'comission_member_2_initials_ss',
        'commision_member_2_initials_ss',
        'commission_member_2_initials_ss',
    ];

    $layoutconfig = (array)($profile['layoutconfig'] ?? []);
    $customcertconfig = (array)($layoutconfig['customcert'] ?? []);
    $customfieldmap = (array)($customcertconfig['fieldmap'] ?? []);
    foreach ($customfieldmap as $elementname => $sourcefield) {
        $fields[] = (string)$elementname;
        $fields[] = (string)$sourcefield;
    }

    $normalised = [];
    foreach ($fields as $field) {
        $key = normalise_demo_customcert_element_name((string)$field);
        if ($key !== '') {
            $normalised[$key] = $key;
        }
    }

    return $normalised;
}

/**
 * Normalise customcert element names the same way the text override path does.
 *
 * @param string $name
 * @return string
 */
function normalise_demo_customcert_element_name(string $name): string {
    $name = \core_text::strtolower(trim($name));
    $name = preg_replace('/[\s\-]+/u', '_', $name) ?? $name;
    return trim($name, '_');
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
