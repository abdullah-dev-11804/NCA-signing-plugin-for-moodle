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

    return true;
}
