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
        'Auto-signed by server fallback.'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ncasign/certurltemplate',
        get_string('certurltemplate', 'local_ncasign'),
        'Use {courseid} and {userid} placeholders.',
        '/mock/certificate.php?course={courseid}&user={userid}',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_heading(
        'local_ncasign/ncanodeheader',
        get_string('ncanodeheader', 'local_ncasign'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ncasign/ncanodebaseurl',
        get_string('ncanodebaseurl', 'local_ncasign'),
        get_string('ncanodebaseurl_desc', 'local_ncasign'),
        'http://127.0.0.1:14579',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_ncasign/ncanodetimeout',
        get_string('ncanodetimeout', 'local_ncasign'),
        get_string('ncanodetimeout_desc', 'local_ncasign'),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ncasign/ncanodecheckocsp',
        get_string('ncanodecheckocsp', 'local_ncasign'),
        get_string('ncanodecheckocsp_desc', 'local_ncasign'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ncasign/ncanodecheckcrl',
        get_string('ncanodecheckcrl', 'local_ncasign'),
        get_string('ncanodecheckcrl_desc', 'local_ncasign'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ncasign/ncalayerekus',
        get_string('ncalayerekus', 'local_ncasign'),
        get_string('ncalayerekus_desc', 'local_ncasign'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ncasign/ncalayerusetsa',
        get_string('ncalayerusetsa', 'local_ncasign'),
        get_string('ncalayerusetsa_desc', 'local_ncasign'),
        0
    ));

    $ADMIN->add('localplugins', $settings);
}
