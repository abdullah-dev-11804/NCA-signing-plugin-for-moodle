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

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade script for local_ncasign.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_ncasign_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026031600) {
        $table = new xmldb_table('local_ncasign_jobs');

        $field = new xmldb_field('documentuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('documenttype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'certificate', 'documentuuid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('documenttitle', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'documenttype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('finalhash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'documenttitle');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('documentuuid_uix', XMLDB_INDEX_UNIQUE, ['documentuuid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026031600, 'local', 'ncasign');
    }

    if ($oldversion < 2026031900) {
        $table = new xmldb_table('local_ncasign_signers');

        $field = new xmldb_field('signername', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'signeremail');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('signerposition', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'signername');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('signorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1', 'signerposition');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('notifiedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $signers = $DB->get_records('local_ncasign_signers', null, 'jobid ASC, id ASC');
        $orderbyjob = [];
        foreach ($signers as $signer) {
            $jobid = (int)$signer->jobid;
            if (!isset($orderbyjob[$jobid])) {
                $orderbyjob[$jobid] = 1;
            }

            if (empty($signer->signername)) {
                $signer->signername = !empty($signer->signeremail) ? (string)$signer->signeremail : null;
            }
            if (empty($signer->signerposition)) {
                $signer->signerposition = 'Commission member ' . $orderbyjob[$jobid];
            }
            $signer->signorder = $orderbyjob[$jobid];
            if (!empty($signer->status) && $signer->status !== 'pending' && empty($signer->notifiedat)) {
                $signer->notifiedat = (int)($signer->signedat ?? $signer->timecreated ?? time());
            }

            $DB->update_record('local_ncasign_signers', $signer);
            $orderbyjob[$jobid]++;
        }

        upgrade_plugin_savepoint(true, 2026031900, 'local', 'ncasign');
    }

    if ($oldversion < 2026032400) {
        $table = new xmldb_table('local_ncasign_jobs');
        $field = new xmldb_field('templateprofileid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_ncasign_templates');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('renderer', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('documenttype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'certificate');
            $table->add_field('documenttitle', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('templatepath', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('layoutconfig', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['active']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ncasign_template_courses');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('templateid_idx', XMLDB_INDEX_NOTUNIQUE, ['templateid']);
            $table->add_index('courseid_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('template_course_uix', XMLDB_INDEX_UNIQUE, ['templateid', 'courseid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ncasign_template_signers');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('signeremail', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('signername', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('signerposition', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('signorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('templateid_idx', XMLDB_INDEX_NOTUNIQUE, ['templateid']);
            $table->add_index('template_signorder_uix', XMLDB_INDEX_UNIQUE, ['templateid', 'signorder']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026032400, 'local', 'ncasign');
    }

    if ($oldversion < 2026032500) {
        $table = new xmldb_table('local_ncasign_jobs');

        $field = new xmldb_field('drafthash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'documenttitle');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_ncasign_template_signers');

        $field = new xmldb_field('expectediin', XMLDB_TYPE_CHAR, '12', null, null, null, null, 'signerposition');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_ncasign_signers');

        $fields = [
            new xmldb_field('expectediin', XMLDB_TYPE_CHAR, '12', null, null, null, null, 'signerposition'),
            new xmldb_field('rawcms', XMLDB_TYPE_TEXT, null, null, null, null, null, 'signedby'),
            new xmldb_field('signercertificate', XMLDB_TYPE_TEXT, null, null, null, null, null, 'rawcms'),
            new xmldb_field('signeriin', XMLDB_TYPE_CHAR, '12', null, null, null, null, 'signercertificate'),
            new xmldb_field('ocspresponse', XMLDB_TYPE_TEXT, null, null, null, null, null, 'signeriin'),
            new xmldb_field('signingmethod', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'ocspresponse'),
            new xmldb_field('verificationstatus', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'signingmethod'),
            new xmldb_field('verificationinfo', XMLDB_TYPE_TEXT, null, null, null, null, null, 'verificationstatus'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $sql = "SELECT s.id, s.jobid, s.signeremail, s.signorder, j.drafthash, ts.expectediin
                  FROM {local_ncasign_signers} s
                  LEFT JOIN {local_ncasign_jobs} j ON j.id = s.jobid
                  LEFT JOIN {local_ncasign_template_signers} ts
                    ON ts.templateid = j.templateprofileid
                   AND ts.signorder = s.signorder";
        $records = $DB->get_records_sql($sql);
        foreach ($records as $record) {
            $update = (object)[
                'id' => (int)$record->id,
                'expectediin' => !empty($record->expectediin) ? preg_replace('/\D+/', '', (string)$record->expectediin) : null,
            ];
            $DB->update_record('local_ncasign_signers', $update);
        }

        $jobs = $DB->get_records('local_ncasign_jobs');
        foreach ($jobs as $job) {
            if (!empty($job->drafthash)) {
                continue;
            }
            $job->drafthash = null;
            $DB->update_record('local_ncasign_jobs', $job);
        }

        upgrade_plugin_savepoint(true, 2026032500, 'local', 'ncasign');
    }

    if ($oldversion < 2026032700) {
        $table = new xmldb_table('local_ncasign_jobs');

        $field = new xmldb_field('finalizerbackend', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'finalhash');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('finalizationmanifest', XMLDB_TYPE_TEXT, null, null, null, null, null, 'finalizerbackend');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026032700, 'local', 'ncasign');
    }

    if ($oldversion < 2026033000) {
        upgrade_plugin_savepoint(true, 2026033000, 'local', 'ncasign');
    }

    if ($oldversion < 2026040300) {
        $table = new xmldb_table('local_ncasign_jobs');
        $field = new xmldb_field('finalizationevidence', XMLDB_TYPE_TEXT, null, null, null, null, null, 'finalizationmanifest');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026040300, 'local', 'ncasign');
    }

    if ($oldversion < 2026052100) {
        $table = new xmldb_table('local_ncasign_jobs');

        $field = new xmldb_field('origin', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'course_completion', 'templateprofileid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('origin_idx', XMLDB_INDEX_NOTUNIQUE, ['origin']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $DB->set_field('local_ncasign_jobs', 'origin', 'course_completion', ['origin' => '']);

        $sources = $DB->get_records_sql(
            "SELECT DISTINCT itemid
               FROM {files}
              WHERE component = :component
                AND filearea = :filearea
                AND filename <> :dot
                AND " . $DB->sql_like('source', ':source', false),
            [
                'component' => 'local_ncasign',
                'filearea' => 'originalpdf',
                'dot' => '.',
                'source' => 'local_generated_demo_draft:%',
            ]
        );
        foreach ($sources as $source) {
            $DB->set_field('local_ncasign_jobs', 'origin', 'demo_job', ['id' => (int)$source->itemid]);
        }

        $sources = $DB->get_records_sql(
            "SELECT DISTINCT itemid
               FROM {files}
              WHERE component = :component
                AND filearea = :filearea
                AND filename <> :dot
                AND (" . $DB->sql_like('source', ':sourcea', false) . "
                 OR " . $DB->sql_like('source', ':sourceb', false) . ")",
            [
                'component' => 'local_ncasign',
                'filearea' => 'originalpdf',
                'dot' => '.',
                'sourcea' => 'mod_customcert_generated%',
                'sourceb' => 'mod_customcert_filearea%',
            ]
        );
        foreach ($sources as $source) {
            $DB->set_field('local_ncasign_jobs', 'origin', 'customcert_issue', ['id' => (int)$source->itemid]);
        }

        upgrade_plugin_savepoint(true, 2026052100, 'local', 'ncasign');
    }

    if ($oldversion < 2026060400) {
        $table = new xmldb_table('local_ncasign_number_counters');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('countertype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('counterdate', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, null);
            $table->add_field('currentvalue', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('counter_date_uix', XMLDB_INDEX_UNIQUE, ['countertype', 'counterdate']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026060400, 'local', 'ncasign');
    }

    if ($oldversion < 2026070800) {
        $table = new xmldb_table('local_ncasign_completion_suppress');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sourcecomponent', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'local_sentaldocupload');
            $table->add_field('sourceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reason', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'manual_course_completion_upload');
            $table->add_field('consumed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeconsumed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('user_course_consumed_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'consumed']);
            $table->add_index('expiresat_ix', XMLDB_INDEX_NOTUNIQUE, ['expiresat']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026070800, 'local', 'ncasign');
    }

    return true;
}
