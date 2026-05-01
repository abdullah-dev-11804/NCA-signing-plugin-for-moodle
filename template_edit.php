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
require_once($CFG->libdir . '/formslib.php');

require_login();
$context = context_system::instance();
require_capability('local/ncasign:managejobs', $context);

$manager = new \local_ncasign\local\template_manager();
$id = optional_param('id', 0, PARAM_INT);
$profile = $id > 0 ? $manager->get_profile($id) : null;

$url = new moodle_url('/local/ncasign/template_edit.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('templateprofileedit', 'local_ncasign'));
$PAGE->set_heading(get_string('templateprofileedit', 'local_ncasign'));

$form = new \local_ncasign\form\template_edit_form($url, [
    'id' => $id,
    'profile' => $profile,
    'availablecustomcerttemplates' => $manager->get_available_customcert_templates(),
]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/ncasign/templates.php'));
}

if ($data = $form->get_data()) {
    $layoutconfig = \local_ncasign\form\template_edit_form::build_layout_config_from_data($data, $profile);
    $customcerttemplateid = (int)$data->customcerttemplateid;

    $savedid = $manager->save_profile([
        'id' => $id,
        'name' => $data->name,
        'renderer' => \local_ncasign\local\document_generator::DOC_CUSTOMCERT_TEMPLATE,
        'documenttype' => 'certificate',
        'documenttitle' => $data->documenttitle,
        'templatepath' => 'customcert:' . $customcerttemplateid,
        'layoutconfig' => json_encode(
            $layoutconfig,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
        ),
        'active' => !empty($data->active),
        'courseids' => $data->courseids,
        'signers' => \local_ncasign\form\template_edit_form::parse_signers((string)$data->signersraw),
    ]);

    redirect(
        new moodle_url('/local/ncasign/template_edit.php', ['id' => $savedid]),
        get_string('templateprofilenotice_saved', 'local_ncasign')
    );
}

$form->set_data(\local_ncasign\form\template_edit_form::build_form_data($profile));

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/ncasign/templates.php'), get_string('templateprofilesback', 'local_ncasign'));
$form->display();
echo $OUTPUT->footer();
