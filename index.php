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

require_login();
$context = context_system::instance();
require_capability('local/ncasign:managejobs', $context);

$filters = [
    'q' => trim(optional_param('q', '', PARAM_TEXT)),
    'status' => optional_param('status', '', PARAM_TEXT),
    'origin' => optional_param('origin', '', PARAM_TEXT),
    'templateprofileid' => optional_param('templateprofileid', 0, PARAM_INT),
];
$statusoptions = local_ncasign_job_filter_status_options();
$originoptions = local_ncasign_job_filter_origin_options();
$templateoptions = local_ncasign_job_filter_template_options();

if (!array_key_exists($filters['status'], $statusoptions)) {
    $filters['status'] = '';
}
if (!array_key_exists($filters['origin'], $originoptions)) {
    $filters['origin'] = '';
}
if (!array_key_exists($filters['templateprofileid'], $templateoptions)) {
    $filters['templateprofileid'] = 0;
}

$sortcolumns = local_ncasign_job_sort_columns();
$sort = optional_param('sort', 'id', PARAM_TEXT);
$dir = strtolower(optional_param('dir', 'desc', PARAM_ALPHA));
if (!array_key_exists($sort, $sortcolumns)) {
    $sort = 'id';
}
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'desc';
}

$url = new moodle_url('/local/ncasign/index.php', local_ncasign_job_url_params($filters, $sort, $dir));
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('jobs', 'local_ncasign'));
$PAGE->set_heading(get_string('jobs', 'local_ncasign'));
$PAGE->requires->css(new moodle_url('/local/ncasign/styles.css'));
$PAGE->requires->js_call_amd('local_ncasign/edge_scroll', 'init', [
    '[data-ncasign-edge-scroll="1"]',
    110,
    48,
]);

echo $OUTPUT->header();
echo $OUTPUT->single_button(
    new moodle_url('/local/ncasign/templates.php'),
    get_string('templateprofiles', 'local_ncasign')
);
echo $OUTPUT->single_button(
    new moodle_url('/local/ncasign/create_demo_job.php'),
    get_string('createdemojob', 'local_ncasign')
);

$params = [
    'replacedbyupload' => \local_ncasign\local\job_manager::JOB_REPLACED_BY_UPLOAD,
    'softdeleted' => \local_ncasign\local\job_manager::JOB_SOFT_DELETED,
    'signersignedstatus' => \local_ncasign\local\job_manager::SIGNER_SIGNED,
    'filecomponent' => 'local_ncasign',
    'dot' => '.',
];
$where = [
    'j.status NOT IN (:replacedbyupload, :softdeleted)',
];

if ($filters['q'] !== '') {
    $search = '%' . $DB->sql_like_escape($filters['q']) . '%';
    $searchclauses = [
        $DB->sql_like('u.firstname', ':qfirstname', false),
        $DB->sql_like('u.lastname', ':qlastname', false),
        $DB->sql_like('u.email', ':qemail', false),
        $DB->sql_like('u.username', ':qusername', false),
        $DB->sql_like('c.fullname', ':qcoursefullname', false),
        $DB->sql_like('c.shortname', ':qcourseshortname', false),
        $DB->sql_like('j.documenttitle', ':qdocumenttitle', false),
    ];
    $params += [
        'qfirstname' => $search,
        'qlastname' => $search,
        'qemail' => $search,
        'qusername' => $search,
        'qcoursefullname' => $search,
        'qcourseshortname' => $search,
        'qdocumenttitle' => $search,
    ];
    if (ctype_digit($filters['q'])) {
        $searchclauses[] = 'j.id = :qjobid';
        $searchclauses[] = 'j.userid = :quserid';
        $searchclauses[] = 'j.courseid = :qcourseid';
        $params += [
            'qjobid' => (int)$filters['q'],
            'quserid' => (int)$filters['q'],
            'qcourseid' => (int)$filters['q'],
        ];
    }
    $where[] = '(' . implode(' OR ', $searchclauses) . ')';
}

if ($filters['status'] !== '') {
    $where[] = 'j.status = :statusfilter';
    $params['statusfilter'] = $filters['status'];
}
if ($filters['origin'] !== '') {
    $where[] = 'j.origin = :originfilter';
    $params['originfilter'] = $filters['origin'];
}
if ($filters['templateprofileid'] > 0) {
    $where[] = 'j.templateprofileid = :templateprofilefilter';
    $params['templateprofilefilter'] = $filters['templateprofileid'];
}

$orderparts = [];
foreach ($sortcolumns[$sort]['sql'] as $sortsql) {
    $orderparts[] = $sortsql . ' ' . strtoupper($dir);
}
if ($sort !== 'id') {
    $orderparts[] = 'j.id DESC';
}
$orderclause = implode(', ', $orderparts);

$jobs = $DB->get_records_sql(
    "SELECT j.*,
            u.firstname AS userfirstname,
            u.lastname AS userlastname,
            u.deleted AS userdeleted,
            c.fullname AS coursefullname,
            c.visible AS coursevisible,
            j.timecreated AS issuedate,
            COALESCE(sc.signedcount, 0) AS signedcount,
            COALESCE(sc.totalcount, 0) AS totalcount,
            COALESCE(fc.artifactcount, 0) AS artifactcount
       FROM {local_ncasign_jobs} j
  LEFT JOIN {user} u ON u.id = j.userid
  LEFT JOIN {course} c ON c.id = j.courseid
  LEFT JOIN (
            SELECT jobid,
                   SUM(CASE WHEN status = :signersignedstatus THEN 1 ELSE 0 END) AS signedcount,
                   COUNT(1) AS totalcount
              FROM {local_ncasign_signers}
          GROUP BY jobid
        ) sc ON sc.jobid = j.id
  LEFT JOIN (
            SELECT itemid,
                   COUNT(1) AS artifactcount
              FROM {files}
             WHERE component = :filecomponent
               AND filearea IN ('originalpdf', 'signedpdf', 'signatures', 'publicprofilepdf')
               AND filename <> :dot
          GROUP BY itemid
        ) fc ON fc.itemid = j.id
      WHERE " . implode(' AND ', $where) . "
   ORDER BY {$orderclause}",
    $params,
    0,
    200
);

echo local_ncasign_render_job_filters($filters, $statusoptions, $originoptions, $templateoptions, $sort, $dir);

$table = new html_table();
$table->head = [
    local_ncasign_job_sort_heading('ID', 'id', $filters, $sort, $dir),
    local_ncasign_job_sort_heading(get_string('user'), 'user', $filters, $sort, $dir),
    local_ncasign_job_sort_heading(get_string('course'), 'course', $filters, $sort, $dir),
    local_ncasign_job_sort_heading(get_string('joborigin', 'local_ncasign'), 'origin', $filters, $sort, $dir),
    local_ncasign_job_sort_heading(get_string('status', 'local_ncasign'), 'status', $filters, $sort, $dir),
    local_ncasign_job_sort_heading(get_string('issuedate', 'local_ncasign'), 'issuedate', $filters, $sort, $dir),
    local_ncasign_job_sort_heading(get_string('deadline', 'local_ncasign'), 'deadline', $filters, $sort, $dir),
    local_ncasign_job_sort_heading(get_string('manualsigned', 'local_ncasign'), 'manualsigned', $filters, $sort, $dir),
    local_ncasign_job_sort_heading(get_string('autosigned', 'local_ncasign'), 'autosigned', $filters, $sort, $dir),
    local_ncasign_job_sort_heading(get_string('jobdetails', 'local_ncasign'), 'jobdetails', $filters, $sort, $dir),
    local_ncasign_job_sort_heading(get_string('artifacts', 'local_ncasign'), 'artifacts', $filters, $sort, $dir),
];

foreach ($jobs as $job) {
    $signedcount = (int)($job->signedcount ?? 0);
    $totalcount = (int)($job->totalcount ?? 0);

    $userlabel = local_ncasign_render_user_link($job);
    $usercell = new html_table_cell($userlabel);
    $usercell->attributes['class'] = 'local-ncasign-user-name-cell';

    $courselabel = local_ncasign_render_course_link($job);
    $coursecell = new html_table_cell($courselabel);
    $coursecell->attributes['class'] = 'local-ncasign-course-name-cell';

    $artifactcell = new html_table_cell(local_ncasign_render_artifacts((int)$job->id));
    $artifactcell->attributes['class'] = 'local-ncasign-artifacts-cell';
    $issuedatecell = new html_table_cell(userdate((int)$job->issuedate));
    $issuedatecell->attributes['class'] = 'local-ncasign-date-cell';
    $deadlinecell = new html_table_cell(userdate((int)$job->manualdeadline));
    $deadlinecell->attributes['class'] = 'local-ncasign-date-cell';
    $autosignedcell = new html_table_cell($job->autosigned ? userdate((int)$job->autosigned) : '-');
    $autosignedcell->attributes['class'] = 'local-ncasign-date-cell';

    $table->data[] = [
        (int)$job->id,
        $usercell,
        $coursecell,
        local_ncasign_render_origin_badge((string)($job->origin ?? 'course_completion')),
        local_ncasign_render_job_status_badge($job, $signedcount, $totalcount),
        $issuedatecell,
        $deadlinecell,
        "{$signedcount}/{$totalcount}",
        $autosignedcell,
        html_writer::link(new moodle_url('/local/ncasign/job.php', ['id' => (int)$job->id]), get_string('viewdetails', 'local_ncasign')),
        $artifactcell,
    ];
}

echo html_writer::div(
    html_writer::table($table),
    'local-ncasign-jobs-scroll',
    ['data-ncasign-edge-scroll' => '1']
);
echo $OUTPUT->footer();

/**
 * Return allowed filter statuses.
 *
 * @return array<string,string>
 */
function local_ncasign_job_filter_status_options(): array {
    return [
        '' => get_string('allstatuses', 'local_ncasign'),
        \local_ncasign\local\job_manager::JOB_PENDING => get_string('badgepending', 'local_ncasign'),
        \local_ncasign\local\job_manager::JOB_COMPLETED_MANUAL => get_string('badgecompletedmanual', 'local_ncasign'),
        \local_ncasign\local\job_manager::JOB_COMPLETED_AUTO => get_string('badgecompletedauto', 'local_ncasign'),
        \local_ncasign\local\job_manager::JOB_FINALIZE_FAILED => get_string('badgefinalizefailed', 'local_ncasign'),
    ];
}

/**
 * Return allowed filter origins.
 *
 * @return array<string,string>
 */
function local_ncasign_job_filter_origin_options(): array {
    return [
        '' => get_string('allorigins', 'local_ncasign'),
        \local_ncasign\local\job_manager::JOB_ORIGIN_COURSE_COMPLETION =>
            get_string('origin_course_completion', 'local_ncasign'),
        \local_ncasign\local\job_manager::JOB_ORIGIN_DEMO => get_string('origin_demo_job', 'local_ncasign'),
        \local_ncasign\local\job_manager::JOB_ORIGIN_CUSTOMCERT_ISSUE =>
            get_string('origin_customcert_issue', 'local_ncasign'),
    ];
}

/**
 * Return template profile filter options.
 *
 * @return array<int,string>
 */
function local_ncasign_job_filter_template_options(): array {
    global $DB;

    $options = [0 => get_string('alltemplateprofiles', 'local_ncasign')];
    if (!$DB->get_manager()->table_exists('local_ncasign_templates')) {
        return $options;
    }

    foreach ($DB->get_records('local_ncasign_templates', null, 'name ASC', 'id,name') as $profile) {
        $options[(int)$profile->id] = format_string((string)$profile->name);
    }

    return $options;
}

/**
 * Return sortable column map.
 *
 * @return array<string,array{sql:array<int,string>}>
 */
function local_ncasign_job_sort_columns(): array {
    return [
        'id' => ['sql' => ['j.id']],
        'user' => ['sql' => ['u.lastname', 'u.firstname', 'j.userid']],
        'course' => ['sql' => ['c.fullname', 'j.courseid']],
        'origin' => ['sql' => ['j.origin']],
        'status' => ['sql' => ['j.status']],
        'issuedate' => ['sql' => ['j.timecreated']],
        'deadline' => ['sql' => ['j.manualdeadline']],
        'manualsigned' => ['sql' => ['COALESCE(sc.signedcount, 0)', 'COALESCE(sc.totalcount, 0)']],
        'autosigned' => ['sql' => ['j.autosigned']],
        'jobdetails' => ['sql' => ['j.id']],
        'artifacts' => ['sql' => ['COALESCE(fc.artifactcount, 0)', 'j.id']],
    ];
}

/**
 * Clean URL params for the jobs page.
 *
 * @param array<string,mixed> $filters
 * @param string|null $sort
 * @param string|null $dir
 * @return array<string,mixed>
 */
function local_ncasign_job_url_params(array $filters, ?string $sort = null, ?string $dir = null): array {
    $params = [];
    foreach ($filters as $key => $value) {
        if ($value === '' || $value === 0 || $value === null) {
            continue;
        }
        $params[$key] = $value;
    }
    if ($sort !== null && $sort !== 'id') {
        $params['sort'] = $sort;
    }
    if ($dir !== null && $dir !== 'desc') {
        $params['dir'] = $dir;
    }

    return $params;
}

/**
 * Render the job filters.
 *
 * @param array<string,mixed> $filters
 * @param array<string,string> $statusoptions
 * @param array<string,string> $originoptions
 * @param array<int,string> $templateoptions
 * @param string $sort
 * @param string $dir
 * @return string
 */
function local_ncasign_render_job_filters(
    array $filters,
    array $statusoptions,
    array $originoptions,
    array $templateoptions,
    string $sort,
    string $dir
): string {
    $fields = [];
    $fields[] = html_writer::tag('div',
        html_writer::label(get_string('searchjobs', 'local_ncasign'), 'id_ncasign_filter_q', false, [
            'class' => 'local-ncasign-filter-label',
        ]) .
        html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'q',
            'id' => 'id_ncasign_filter_q',
            'placeholder' => get_string('searchjobs_placeholder', 'local_ncasign'),
            'value' => s((string)$filters['q']),
            'class' => 'form-control',
        ]) .
        html_writer::div(get_string('searchjobs_help', 'local_ncasign'), 'local-ncasign-filter-help'),
        [
            'class' => 'local-ncasign-filter-field local-ncasign-filter-search',
        ]
    );
    $fields[] = html_writer::tag('div',
        html_writer::label(get_string('status', 'local_ncasign'), 'id_ncasign_filter_status', false, [
            'class' => 'local-ncasign-filter-label',
        ]) .
        html_writer::select($statusoptions, 'status', (string)$filters['status'], false, [
            'id' => 'id_ncasign_filter_status',
            'class' => 'form-select custom-select',
        ]),
        [
            'class' => 'local-ncasign-filter-field',
        ]
    );
    $fields[] = html_writer::tag('div',
        html_writer::label(get_string('joborigin', 'local_ncasign'), 'id_ncasign_filter_origin', false, [
            'class' => 'local-ncasign-filter-label',
        ]) .
        html_writer::select($originoptions, 'origin', (string)$filters['origin'], false, [
            'id' => 'id_ncasign_filter_origin',
            'class' => 'form-select custom-select',
        ]),
        [
            'class' => 'local-ncasign-filter-field',
        ]
    );
    $fields[] = html_writer::tag('div',
        html_writer::label(get_string('templateprofile', 'local_ncasign'), 'id_ncasign_filter_template', false, [
            'class' => 'local-ncasign-filter-label',
        ]) .
        html_writer::select($templateoptions, 'templateprofileid', (int)$filters['templateprofileid'], false, [
            'id' => 'id_ncasign_filter_template',
            'class' => 'form-select custom-select',
        ]),
        [
            'class' => 'local-ncasign-filter-field local-ncasign-filter-template',
        ]
    );
    $fields[] = html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'sort',
        'value' => s($sort),
    ]);
    $fields[] = html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'dir',
        'value' => s($dir),
    ]);
    $fields[] = html_writer::tag('div',
        html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-primary',
            'value' => get_string('applyfilters', 'local_ncasign'),
        ]) .
        html_writer::link(new moodle_url('/local/ncasign/index.php'), get_string('clearfilters', 'local_ncasign'), [
            'class' => 'btn btn-secondary',
        ]),
        [
            'class' => 'local-ncasign-filter-actions',
        ]
    );

    return html_writer::tag('form',
        html_writer::tag('div', implode('', $fields), [
            'class' => 'local-ncasign-job-filters-inner',
        ]),
        [
            'method' => 'get',
            'action' => (new moodle_url('/local/ncasign/index.php'))->out(false),
            'class' => 'local-ncasign-job-filters',
        ]
    );
}

/**
 * Render a sortable table heading.
 *
 * @param string $label
 * @param string $sortkey
 * @param array<string,mixed> $filters
 * @param string $currentsort
 * @param string $currentdir
 * @return string
 */
function local_ncasign_job_sort_heading(
    string $label,
    string $sortkey,
    array $filters,
    string $currentsort,
    string $currentdir
): string {
    $active = $sortkey === $currentsort;
    $nextdir = $active && $currentdir === 'asc' ? 'desc' : 'asc';
    $params = local_ncasign_job_url_params($filters, $sortkey, $nextdir);
    $classes = 'local-ncasign-sort-link';
    if ($active) {
        $classes .= ' local-ncasign-sort-active';
    }
    $arrow = '';
    if ($active) {
        $arrow = html_writer::span($currentdir === 'asc' ? '&uarr;' : '&darr;', 'local-ncasign-sort-arrow');
    }

    return html_writer::link(new moodle_url('/local/ncasign/index.php', $params), s($label) . $arrow, [
        'class' => $classes,
    ]);
}

/**
 * Render the signing job user's profile link.
 *
 * @param stdClass $job
 * @return string
 */
function local_ncasign_render_user_link(\stdClass $job): string {
    $lastname = trim((string)($job->userlastname ?? ''));
    $firstname = trim((string)($job->userfirstname ?? ''));
    $label = trim($lastname . ' ' . $firstname);

    if ($label === '') {
        return s('#' . (int)$job->userid);
    }

    if (!empty($job->userdeleted)) {
        return s($label);
    }

    return html_writer::link(new moodle_url('/user/profile.php', ['id' => (int)$job->userid]), s($label));
}

/**
 * Render the signing job course link.
 *
 * @param stdClass $job
 * @return string
 */
function local_ncasign_render_course_link(\stdClass $job): string {
    $label = trim((string)($job->coursefullname ?? ''));

    if ($label === '') {
        return s('#' . (int)$job->courseid);
    }

    return html_writer::link(new moodle_url('/course/view.php', ['id' => (int)$job->courseid]), format_string($label));
}

/**
 * Render artifact links for a signing job.
 *
 * @param int $jobid
 * @return string
 */
function local_ncasign_render_artifacts(int $jobid): string {
    global $DB;

    $manager = new \local_ncasign\local\job_manager();
    $links = [];
    if ($manager->has_job_original_pdf($jobid)) {
        $originallink = new moodle_url('/local/ncasign/download_artifact.php', [
            'jobid' => $jobid,
            'type' => 'original',
        ]);
        $links[] = html_writer::link($originallink, 'Original PDF');
    }

    if ($manager->has_job_signed_pdf($jobid)) {
        $signedpdf = $manager->get_job_signed_pdf_binary($jobid);
        $issignedfinal = !empty($signedpdf['filename']) && stripos((string)$signedpdf['filename'], 'signed_final_') !== false;
        $signedpdflink = new moodle_url('/local/ncasign/download_artifact.php', [
            'jobid' => $jobid,
            'type' => 'signedpdf',
        ]);
        $label = $issignedfinal
            ? get_string('signedpdffinallabel', 'local_ncasign')
            : get_string('signedpdfprogresslabel', 'local_ncasign');
        $links[] = html_writer::link($signedpdflink, $label);
    }

    $verifylink = $manager->get_verification_url_for_job($jobid);
    if ($verifylink !== '') {
        $links[] = html_writer::link($verifylink, 'Verify');
    }

    $signed = $DB->get_records('local_ncasign_signers', [
        'jobid' => $jobid,
        'status' => \local_ncasign\local\job_manager::SIGNER_SIGNED,
    ]);
    foreach ($signed as $signer) {
        $siglink = new moodle_url('/local/ncasign/download_artifact.php', [
            'jobid' => $jobid,
            'type' => 'signature',
            'signerid' => (int)$signer->id,
        ]);
        $links[] = html_writer::link($siglink, 'CMS signer #' . (int)$signer->id);
    }

    $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], 'autosignnote', IGNORE_MISSING);
    if ($job && !empty($job->autosignnote)) {
        $links[] = html_writer::tag('span', 'Finalization note: ' . s((string)$job->autosignnote), [
            'style' => 'color:#b00020;',
        ]);
    }

    if (!$links) {
        return '-';
    }

    return implode(' | ', $links);
}

/**
 * Render a job origin badge.
 *
 * @param string $origin
 * @return string
 */
function local_ncasign_render_origin_badge(string $origin): string {
    $origin = strtolower(trim($origin));
    if ($origin === \local_ncasign\local\job_manager::JOB_ORIGIN_DEMO) {
        return local_ncasign_badge(get_string('origin_demo_job', 'local_ncasign'), '#fff3cd', '#664d03');
    }
    if ($origin === \local_ncasign\local\job_manager::JOB_ORIGIN_CUSTOMCERT_ISSUE) {
        return local_ncasign_badge(get_string('origin_customcert_issue', 'local_ncasign'), '#cff4fc', '#055160');
    }

    return local_ncasign_badge(get_string('origin_course_completion', 'local_ncasign'), '#d1e7dd', '#0f5132');
}

/**
 * Render a compact badge.
 *
 * @param string $label
 * @param string $background
 * @param string $color
 * @return string
 */
function local_ncasign_badge(string $label, string $background, string $color = '#fff'): string {
    return html_writer::tag('span', s($label), [
        'style' => 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;background:' .
            $background . ';color:' . $color . ';white-space:nowrap;',
    ]);
}

/**
 * Render a job status badge.
 *
 * @param stdClass $job
 * @param int $signedcount
 * @param int $totalcount
 * @return string
 */
function local_ncasign_render_job_status_badge(\stdClass $job, int $signedcount, int $totalcount): string {
    if ($job->status === \local_ncasign\local\job_manager::JOB_COMPLETED_MANUAL) {
        return local_ncasign_badge(get_string('badgecompletedmanual', 'local_ncasign'), '#1f7a1f');
    }
    if ($job->status === \local_ncasign\local\job_manager::JOB_COMPLETED_AUTO) {
        return local_ncasign_badge(get_string('badgecompletedauto', 'local_ncasign'), '#6f42c1');
    }
    if ($job->status === \local_ncasign\local\job_manager::JOB_FINALIZE_FAILED) {
        return local_ncasign_badge(get_string('badgefinalizefailed', 'local_ncasign'), '#dc3545');
    }
    if ($job->status === \local_ncasign\local\job_manager::JOB_REPLACED_BY_UPLOAD) {
        return local_ncasign_badge(get_string('badgereplacedbyupload', 'local_ncasign'), '#6c757d');
    }
    if ($job->status === \local_ncasign\local\job_manager::JOB_SOFT_DELETED) {
        return local_ncasign_badge(get_string('badgesoftdeleted', 'local_ncasign'), '#343a40');
    }
    if ($signedcount > 0 && $signedcount < $totalcount) {
        return local_ncasign_badge(get_string('badgepartial', 'local_ncasign', "{$signedcount}/{$totalcount}"), '#0d6efd');
    }
    return local_ncasign_badge(get_string('badgepending', 'local_ncasign'), '#6c757d');
}
