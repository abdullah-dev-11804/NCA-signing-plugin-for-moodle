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
        'jobids' => null,
        'dry-run' => false,
    ],
    [
        'h' => 'help',
        'j' => 'jobids',
    ]
);

if (!empty($options['help']) || !empty($unrecognized) || empty($options['jobids'])) {
    $help = "Retry final PDF generation for fully signed NCA Sign jobs\n\n"
        . "Options:\n"
        . "-h, --help             Print this help\n"
        . "-j, --jobids=IDS       Comma-separated job IDs, for example 300,304\n"
        . "--dry-run              Check what would be retried without writing changes\n\n"
        . "Example:\n"
        . "php local/ncasign/cli/retry_finalize_jobs.php --jobids=300,304\n";
    cli_writeln($help);
    exit(empty($options['help']) ? 1 : 0);
}

$jobids = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', (string)$options['jobids']))));
if (!$jobids) {
    cli_error('No valid job IDs were provided.', 1);
}

$manager = new \local_ncasign\local\job_manager();
$dryrun = !empty($options['dry-run']);

foreach ($jobids as $jobid) {
    $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', IGNORE_MISSING);
    if (!$job) {
        cli_writeln("Job {$jobid}: not found, skipped.");
        continue;
    }

    $total = $DB->count_records('local_ncasign_signers', ['jobid' => $jobid]);
    $signed = $DB->count_records('local_ncasign_signers', [
        'jobid' => $jobid,
        'status' => \local_ncasign\local\job_manager::SIGNER_SIGNED,
    ]);
    $pending = $DB->count_records('local_ncasign_signers', [
        'jobid' => $jobid,
        'status' => \local_ncasign\local\job_manager::SIGNER_PENDING,
    ]);

    cli_writeln("Job {$jobid}: status={$job->status}, signed={$signed}/{$total}, pending={$pending}");

    if ($total < 1 || $pending > 0 || $signed !== $total) {
        cli_writeln("Job {$jobid}: not fully signed, skipped.");
        continue;
    }

    if ($dryrun) {
        cli_writeln("Job {$jobid}: dry-run only; finalization would be retried.");
        continue;
    }

    try {
        $filename = $manager->generate_signed_pdf_artifact($jobid);
    } catch (Throwable $e) {
        cli_writeln("Job {$jobid}: retry failed: " . $e->getMessage());
        continue;
    }

    $job = $DB->get_record('local_ncasign_jobs', ['id' => $jobid], '*', MUST_EXIST);
    if (empty($filename) || empty($job->finalhash) || !$manager->has_job_signed_pdf($jobid)) {
        cli_writeln("Job {$jobid}: finalizer ran but final PDF/hash is still missing.");
        continue;
    }

    $signers = $DB->get_records('local_ncasign_signers', ['jobid' => $jobid], 'signorder ASC, id ASC');
    $allserver = true;
    $latestmanualsignedat = 0;
    foreach ($signers as $signer) {
        if ((string)($signer->signedby ?? '') !== 'server_pkcs12') {
            $allserver = false;
        }
        $latestmanualsignedat = max($latestmanualsignedat, (int)($signer->signedat ?? 0));
    }

    if ($allserver) {
        $job->status = \local_ncasign\local\job_manager::JOB_COMPLETED_AUTO;
        $job->autosigned = time();
        $job->manualcompleted = null;
        $job->autosignnote = (string)get_config('local_ncasign', 'autosignnote');
    } else {
        $job->status = \local_ncasign\local\job_manager::JOB_COMPLETED_MANUAL;
        $job->manualcompleted = $latestmanualsignedat ?: time();
        $job->autosigned = null;
        $job->autosignnote = null;
    }
    $job->timemodified = time();
    $DB->update_record('local_ncasign_jobs', $job);

    cli_writeln("Job {$jobid}: repaired. filename={$filename}, finalhash={$job->finalhash}, status={$job->status}");
}

exit(0);
