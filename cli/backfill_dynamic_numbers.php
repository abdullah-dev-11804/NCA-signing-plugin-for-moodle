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

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'dry-run' => true,
        'write' => false,
        'jobids' => null,
        'batch-size' => 100,
        'offset' => 0,
        'pdftotext' => 'pdftotext',
        'include-inactive' => false,
        'only-missing' => true,
        'all' => false,
        'debug' => false,
        'debug-text' => false,
        'clear-missing' => false,
    ],
    [
        'h' => 'help',
        'j' => 'jobids',
    ]
);

if (!empty($options['help']) || !empty($unrecognized)) {
    $help = "Backfill NCAsign protocol/certificate/book numbers from stored PDFs\n\n"
        . "The script extracts PRO/CER/CID values from the stored job PDF and writes each\n"
        . "confirmed value to local_ncasign_jobs.finalizationmanifest.dynamic_fields.\n"
        . "A value is written only when it is found unambiguously and matches the same\n"
        . "job userid/courseid. Missing values stay empty.\n\n"
        . "Options:\n"
        . "-h, --help                 Print this help\n"
        . "-j, --jobids=IDS           Comma-separated job IDs, for example 300,304\n"
        . "--batch-size=N            Number of jobs to inspect when --jobids is not used; default 100\n"
        . "--offset=N                Offset for batch processing; default 0\n"
        . "--pdftotext=PATH          pdftotext binary path; default pdftotext\n"
        . "--include-inactive        Include replaced_by_upload and soft_deleted jobs\n"
        . "--all                     Re-check jobs even when dynamic_fields already exist\n"
        . "--debug                   Print extracted number candidates for skipped jobs\n"
        . "--debug-text              Print a short excerpt of extracted PDF text for skipped jobs\n"
        . "--clear-missing           Clear PRO/CER/CID fields that are not found in the PDF\n"
        . "--write                   Save confirmed values to DB; without this it is a dry run\n\n"
        . "Examples:\n"
        . "php local/ncasign/cli/backfill_dynamic_numbers.php --batch-size=100\n"
        . "php local/ncasign/cli/backfill_dynamic_numbers.php --jobids=300,304 --write\n"
        . "php local/ncasign/cli/backfill_dynamic_numbers.php --offset=100 --batch-size=100 --write\n";
    cli_writeln($help);
    exit(empty($options['help']) ? 1 : 0);
}

$dryrun = empty($options['write']);
$batchsize = max(1, min(1000, (int)$options['batch-size']));
$offset = max(0, (int)$options['offset']);
$pdftotext = trim((string)$options['pdftotext']);
$onlymissing = empty($options['all']) && !empty($options['only-missing']);
$includeinactive = !empty($options['include-inactive']);
$debug = !empty($options['debug']) || !empty($options['debug-text']);
$debugtext = !empty($options['debug-text']);
$clearmissing = !empty($options['clear-missing']);

if ($pdftotext === '') {
    cli_error('pdftotext path cannot be empty.', 1);
}

$manager = new \local_ncasign\local\job_manager();
$jobids = [];
if (!empty($options['jobids'])) {
    $jobids = array_values(array_filter(array_unique(array_map('intval', preg_split('/\s*,\s*/', (string)$options['jobids'])))));
}

$jobs = local_ncasign_backfill_load_jobs($jobids, $batchsize, $offset, $onlymissing, $includeinactive);
if (!$jobs) {
    cli_writeln('No matching jobs found.');
    exit(0);
}

$summary = [
    'checked' => 0,
    'updated' => 0,
    'wouldupdate' => 0,
    'skipped' => 0,
    'failed' => 0,
];

cli_writeln(($dryrun ? 'DRY RUN: ' : 'WRITE MODE: ') . 'checking ' . count($jobs) . ' job(s).');

foreach ($jobs as $job) {
    $summary['checked']++;
    $jobid = (int)$job->id;

    try {
        $payload = $manager->get_job_signed_pdf_binary($jobid) ?: $manager->get_job_certificate_binary($jobid);
        if (!$payload || empty($payload['content'])) {
            $summary['skipped']++;
            cli_writeln("Job {$jobid}: skipped, no stored PDF found.");
            continue;
        }

        $text = local_ncasign_backfill_pdf_to_text(
            (string)$payload['content'],
            (string)($payload['filename'] ?? "job_{$jobid}.pdf"),
            $pdftotext
        );
        $numbers = local_ncasign_backfill_extract_numbers($text, (int)$job->userid, (int)$job->courseid);
        if (!$numbers['ok']) {
            $summary['skipped']++;
            cli_writeln("Job {$jobid}: skipped, " . $numbers['reason']);
            if ($debug) {
                local_ncasign_backfill_print_debug($jobid, $text, (int)$job->userid, (int)$job->courseid, $debugtext);
            }
            continue;
        }

        $manifest = local_ncasign_backfill_decode_manifest($job->finalizationmanifest ?? null);
        $before = !empty($manifest['dynamic_fields']) && is_array($manifest['dynamic_fields'])
            ? $manifest['dynamic_fields']
            : [];
        $dynamicfields = [
            'protocol_number' => $clearmissing ? '' : (string)($before['protocol_number'] ?? ''),
            'certificate_number' => $clearmissing ? '' : (string)($before['certificate_number'] ?? ''),
            'book_id' => $clearmissing ? '' : (string)($before['book_id'] ?? ''),
        ];
        foreach ($numbers['values'] as $field => $value) {
            $dynamicfields[$field] = (string)$value;
        }
        $manifest['dynamic_fields'] = $dynamicfields;
        $manifest['dynamic_fields_backfill'] = [
            'method' => 'pdftotext_regex_strict',
            'sourcearea' => !empty($payload['sourcearea']) ? (string)$payload['sourcearea'] : 'stored_pdf',
            'sourcefilename' => (string)($payload['filename'] ?? ''),
            'sourcesha256' => (string)($payload['sha256'] ?? ''),
            'time' => time(),
        ];

        if ($before == $manifest['dynamic_fields']) {
            cli_writeln(
                "Job {$jobid}: already populated " .
                local_ncasign_backfill_format_dynamic_fields($manifest['dynamic_fields'])
            );
            continue;
        }

        if ($dryrun) {
            $summary['wouldupdate']++;
            cli_writeln(
                "Job {$jobid}: would update " .
                local_ncasign_backfill_format_dynamic_fields($manifest['dynamic_fields']) .
                " (found " . implode(', ', array_keys($numbers['values'])) .
                ($clearmissing ? ', clear missing enabled' : '') . ")"
            );
            continue;
        }

        $DB->update_record('local_ncasign_jobs', (object)[
            'id' => $jobid,
            'finalizationmanifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timemodified' => time(),
        ]);
        $summary['updated']++;
        cli_writeln(
            "Job {$jobid}: updated " .
            local_ncasign_backfill_format_dynamic_fields($manifest['dynamic_fields']) .
            " (found " . implode(', ', array_keys($numbers['values'])) .
            ($clearmissing ? ', clear missing enabled' : '') . ")"
        );
    } catch (Throwable $e) {
        $summary['failed']++;
        cli_writeln("Job {$jobid}: failed, " . $e->getMessage());
    }
}

cli_writeln('Summary: ' . json_encode($summary, JSON_UNESCAPED_SLASHES));
exit($summary['failed'] > 0 ? 1 : 0);

/**
 * Load jobs for backfill.
 *
 * @param int[] $jobids
 * @param int $batchsize
 * @param int $offset
 * @param bool $onlymissing
 * @param bool $includeinactive
 * @return stdClass[]
 */
function local_ncasign_backfill_load_jobs(
    array $jobids,
    int $batchsize,
    int $offset,
    bool $onlymissing,
    bool $includeinactive
): array {
    global $DB;

    $conditions = [
        'j.origin = :origin',
    ];
    $params = [
        'origin' => \local_ncasign\local\job_manager::JOB_ORIGIN_COURSE_COMPLETION,
    ];

    if (!$includeinactive) {
        $conditions[] = 'j.status NOT IN (:replaced, :softdeleted)';
        $params['replaced'] = \local_ncasign\local\job_manager::JOB_REPLACED_BY_UPLOAD;
        $params['softdeleted'] = \local_ncasign\local\job_manager::JOB_SOFT_DELETED;
    }

    if ($onlymissing) {
        $conditions[] = "(j.finalizationmanifest IS NULL
            OR j.finalizationmanifest NOT LIKE :dynamicmarker
            OR j.finalizationmanifest NOT LIKE :protocolmarker
            OR j.finalizationmanifest NOT LIKE :certmarker
            OR j.finalizationmanifest NOT LIKE :bookmarker
            OR j.finalizationmanifest LIKE :protocolempty
            OR j.finalizationmanifest LIKE :certempty
            OR j.finalizationmanifest LIKE :bookempty)";
        $params['dynamicmarker'] = '%"dynamic_fields"%';
        $params['protocolmarker'] = '%"protocol_number"%';
        $params['certmarker'] = '%"certificate_number"%';
        $params['bookmarker'] = '%"book_id"%';
        $params['protocolempty'] = '%"protocol_number":""%';
        $params['certempty'] = '%"certificate_number":""%';
        $params['bookempty'] = '%"book_id":""%';
    }

    if ($jobids) {
        [$insql, $inparams] = $DB->get_in_or_equal($jobids, SQL_PARAMS_NAMED, 'jobid');
        $conditions[] = "j.id {$insql}";
        $params += $inparams;
        $limitfrom = 0;
        $limitnum = 0;
    } else {
        $limitfrom = $offset;
        $limitnum = $batchsize;
    }

    $sql = "SELECT j.*
              FROM {local_ncasign_jobs} j
             WHERE " . implode(' AND ', $conditions) . "
          ORDER BY j.id ASC";

    return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
}

/**
 * Extract PDF text with pdftotext.
 *
 * @param string $pdfbytes
 * @param string $filename
 * @param string $pdftotext
 * @return string
 */
function local_ncasign_backfill_pdf_to_text(string $pdfbytes, string $filename, string $pdftotext): string {
    global $CFG;

    $dir = make_temp_directory('local_ncasign/backfill_dynamic_numbers');
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $filename);
    $pdffile = $dir . '/' . uniqid('job_', true) . '_' . ($safe ?: 'document.pdf');
    file_put_contents($pdffile, $pdfbytes);

    $cmd = escapeshellcmd($pdftotext) . ' -layout -enc UTF-8 ' . escapeshellarg($pdffile) . ' -';
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptor, $pipes, $CFG->tempdir);
    if (!is_resource($process)) {
        @unlink($pdffile);
        throw new RuntimeException('Unable to start pdftotext.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitcode = proc_close($process);
    @unlink($pdffile);

    if ($exitcode !== 0) {
        throw new RuntimeException('pdftotext failed: ' . trim((string)$stderr));
    }

    $text = trim((string)$stdout);
    if ($text === '') {
        throw new RuntimeException('pdftotext returned empty text.');
    }

    return $text;
}

/**
 * Extract strict PRO/CER/CID values for a job.
 *
 * @param string $text
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function local_ncasign_backfill_extract_numbers(string $text, int $userid, int $courseid): array {
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace('/\s*-\s*/u', '-', $text);
    $course = sprintf('%04d', $courseid);
    $patterns = [
        'protocol_number' => 'PRO',
        'certificate_number' => 'CER',
        'book_id' => 'CID',
    ];
    $values = [];
    $missing = [];

    foreach ($patterns as $key => $prefix) {
        $regex = '/\b' . $prefix . '-' . preg_quote((string)$userid, '/') . '-' .
            preg_quote($course, '/') . '-\d{8}-\d{4}\b/u';
        if (!preg_match_all($regex, $text, $matches)) {
            $missing[] = $key;
            continue;
        }

        $unique = array_values(array_unique($matches[0]));
        if (count($unique) !== 1) {
            return [
                'ok' => false,
                'reason' => "ambiguous {$key}: " . implode(', ', $unique),
            ];
        }
        $values[$key] = $unique[0];
    }

    if (!$values) {
        return [
            'ok' => false,
            'reason' => "no PRO/CER/CID values found for userid={$userid}, course={$course}",
        ];
    }

    return [
        'ok' => true,
        'values' => $values,
        'missing' => $missing,
    ];
}

/**
 * Format dynamic fields for CLI output.
 *
 * @param array $fields
 * @return string
 */
function local_ncasign_backfill_format_dynamic_fields(array $fields): string {
    $labels = [];
    foreach (['protocol_number', 'certificate_number', 'book_id'] as $key) {
        $value = trim((string)($fields[$key] ?? ''));
        $labels[] = $key . '=' . ($value !== '' ? $value : '-');
    }

    return implode(', ', $labels);
}

/**
 * Print extraction details for a skipped job.
 *
 * @param int $jobid
 * @param string $text
 * @param int $userid
 * @param int $courseid
 * @param bool $debugtext
 * @return void
 */
function local_ncasign_backfill_print_debug(
    int $jobid,
    string $text,
    int $userid,
    int $courseid,
    bool $debugtext
): void {
    $normalised = str_replace("\xc2\xa0", ' ', $text);
    $normalised = preg_replace('/\s*-\s*/u', '-', $normalised);
    $course = sprintf('%04d', $courseid);

    cli_writeln("Job {$jobid}: debug expected userid={$userid}, course={$course}");

    foreach (['PRO', 'CER', 'CID'] as $prefix) {
        $strictregex = '/\b' . $prefix . '-' . preg_quote((string)$userid, '/') . '-' .
            preg_quote($course, '/') . '-\d{8}-\d{4}\b/u';
        preg_match_all($strictregex, $normalised, $strictmatches);

        $looseregex = '/\b' . $prefix . '-\d+-\d{4}-\d{8}-\d{4}\b/u';
        preg_match_all($looseregex, $normalised, $loosematches);

        $strict = array_values(array_unique($strictmatches[0] ?? []));
        $loose = array_values(array_unique($loosematches[0] ?? []));

        cli_writeln("Job {$jobid}: debug {$prefix} strict matches: " . ($strict ? implode(', ', $strict) : '-'));
        cli_writeln("Job {$jobid}: debug {$prefix} loose matches: " . ($loose ? implode(', ', $loose) : '-'));
    }

    if ($debugtext) {
        $excerpt = preg_replace('/[ \t]+/', ' ', $normalised);
        $excerpt = preg_replace('/\R+/', "\n", $excerpt);
        $excerpt = trim((string)$excerpt);
        if (\core_text::strlen($excerpt) > 2500) {
            $excerpt = \core_text::substr($excerpt, 0, 2500) . "\n...";
        }
        cli_writeln("Job {$jobid}: extracted text excerpt:\n" . $excerpt);
    }
}

/**
 * Decode stored finalization manifest.
 *
 * @param mixed $manifest
 * @return array
 */
function local_ncasign_backfill_decode_manifest($manifest): array {
    if (empty($manifest) || !is_string($manifest)) {
        return [
            'version' => 1,
        ];
    }

    $decoded = json_decode($manifest, true);
    return is_array($decoded) ? $decoded : [
        'version' => 1,
        'previous_finalizationmanifest_unparseable' => true,
    ];
}
