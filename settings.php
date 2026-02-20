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

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_ncasign', get_string('pluginname', 'local_ncasign'));
    $settings->add(new admin_setting_heading(
        'local_ncasign/header',
        get_string('settingsheader', 'local_ncasign'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ncasign/enabled',
        get_string('enabled', 'local_ncasign'),
        '',
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ncasign/manualwindowhours',
        get_string('manualwindowhours', 'local_ncasign'),
        '',
        24,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ncasign/autosignenabled',
        get_string('autosignenabled', 'local_ncasign'),
        '',
        1
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ncasign/autosignnote',
        get_string('autosignnote', 'local_ncasign'),
        '',
        'Auto-signed by server fallback (demo mode).'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ncasign/notifyroleids',
        get_string('notifyroleids', 'local_ncasign'),
        '',
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ncasign/certurltemplate',
        get_string('certurltemplate', 'local_ncasign'),
        'Use {courseid} and {userid} placeholders.',
        '/mock/certificate.php?course={courseid}&user={userid}',
        PARAM_RAW_TRIMMED
    ));

    $ADMIN->add('localplugins', $settings);
}
