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

/**
 * Rubric management admin page.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_rubricai\rag_client;

admin_externalpage_setup('local_rubricai_rubrics');

$action  = optional_param('action', 'list', PARAM_ALPHANUMEXT);
$id      = optional_param('id', '', PARAM_ALPHANUMEXT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$page_url = new moodle_url('/local/rubricai/admin/rubrics.php');
$notice   = '';

// --- POST actions (require sesskey) ---
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $rubric = [
        'id'          => optional_param('rubric_id', '', PARAM_ALPHANUMEXT) ?: null,
        'title'       => required_param('title', PARAM_TEXT),
        'description' => optional_param('description', '', PARAM_TEXT),
        'criteria'    => [],
    ];
    $names   = optional_param_array('criterion_name', [], PARAM_TEXT);
    $descs   = optional_param_array('criterion_desc', [], PARAM_TEXT);
    $weights = optional_param_array('criterion_weight', [], PARAM_INT);
    $dims    = optional_param_array('criterion_dimension', [], PARAM_TEXT);
    foreach ($names as $i => $name) {
        if (trim($name) === '') continue;
        $rubric['criteria'][] = [
            'name'        => $name,
            'description' => $descs[$i] ?? '',
            'weight'      => (int)($weights[$i] ?? 0),
            'dimension'   => $dims[$i] ?? 'General',
        ];
    }
    $result = rag_client::save_rubric($rubric);
    if ($result && isset($result->status) && $result->status === 'success') {
        redirect($page_url, get_string('rubric_saved', 'local_rubricai'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $notice = get_string('rubric_service_error', 'local_rubricai');
    $action = $rubric['id'] ? 'edit' : 'create';
}

if ($action === 'delete' && $confirm && $id) {
    require_sesskey();
    $deleted = rag_client::delete_rubric($id);
    if ($deleted) {
        redirect($page_url, get_string('rubric_deleted', 'local_rubricai'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $notice = get_string('rubric_service_error', 'local_rubricai');
    $action = 'list';
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $file_info = $_FILES['json_file'] ?? null;
    if ($file_info && $file_info['error'] === UPLOAD_ERR_OK) {
        $json_content = file_get_contents($file_info['tmp_name']);
        $rubric = json_decode($json_content, true);
        if (is_array($rubric)) {
            $result = rag_client::save_rubric($rubric);
            if ($result && isset($result->status) && $result->status === 'success') {
                redirect($page_url, get_string('rubric_imported', 'local_rubricai'), null, \core\output\notification::NOTIFY_SUCCESS);
            }
        }
    }
    $notice = get_string('rubric_service_error', 'local_rubricai');
    $action = 'list';
}

if ($action === 'import_generic') {
    require_sesskey();
    $fixture_path = __DIR__ . '/../db/fixtures/rubric_generic.json';
    if (file_exists($fixture_path)) {
        $rubric = json_decode(file_get_contents($fixture_path), true);
        $result = rag_client::save_rubric($rubric);
        if ($result && isset($result->status) && $result->status === 'success') {
            redirect($page_url, get_string('rubric_imported', 'local_rubricai'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
    $notice = get_string('rubric_service_error', 'local_rubricai');
    $action = 'list';
}

// --- Render ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('rubric_list_title', 'local_rubricai'));

if ($notice) {
    echo $OUTPUT->notification($notice, 'error');
}

// ---- LIST VIEW ----
if ($action === 'list') {
    $rubrics = rag_client::list_rubrics();

    echo html_writer::start_tag('div', ['style' => 'margin-bottom:20px;']);
    echo html_writer::link(
        new moodle_url($page_url, ['action' => 'create']),
        get_string('rubric_create', 'local_rubricai'),
        ['class' => 'btn btn-primary']
    );
    echo ' ';
    echo html_writer::link(
        new moodle_url($page_url, ['action' => 'import_generic', 'sesskey' => sesskey()]),
        get_string('rubric_import_generic', 'local_rubricai'),
        ['class' => 'btn btn-secondary']
    );
    echo html_writer::end_tag('div');

    // Import from JSON form
    echo html_writer::start_tag('details', ['style' => 'margin-bottom:20px;']);
    echo html_writer::tag('summary', get_string('rubric_import', 'local_rubricai'), ['style' => 'cursor:pointer;font-weight:bold;']);
    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'style' => 'margin-top:10px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'import']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('label', get_string('import_json_label', 'local_rubricai') . ': ');
    echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'json_file', 'accept' => '.json']);
    echo ' ';
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('rubric_import', 'local_rubricai'), 'class' => 'btn btn-sm btn-outline-secondary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('details');

    if (empty($rubrics)) {
        echo $OUTPUT->notification(get_string('no_rubrics', 'local_rubricai'), 'info');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('rubric_title_field', 'local_rubricai'),
            get_string('rubric_desc_field', 'local_rubricai'),
            get_string('rubric_criteria', 'local_rubricai'),
            '',
        ];
        $table->data = [];
        foreach ($rubrics as $r) {
            $r = (object)$r;
            $rubric_id  = $r->id ?? '';
            $n_criteria = count($r->criteria ?? []);
            $edit_url   = new moodle_url($page_url, ['action' => 'edit', 'id' => $rubric_id]);
            $del_url    = new moodle_url($page_url, ['action' => 'delete', 'id' => $rubric_id]);
            $actions    = html_writer::link($edit_url, get_string('rubric_edit', 'local_rubricai'), ['class' => 'btn btn-sm btn-outline-primary me-1']);
            $actions   .= html_writer::link($del_url, get_string('rubric_delete', 'local_rubricai'), ['class' => 'btn btn-sm btn-outline-danger']);
            $table->data[] = [
                html_writer::tag('strong', s($r->title ?? '')),
                s($r->description ?? ''),
                $n_criteria,
                $actions,
            ];
        }
        echo html_writer::table($table);
    }
}

// ---- DELETE CONFIRM VIEW ----
if ($action === 'delete' && !$confirm && $id) {
    $rubric = rag_client::get_rubric($id);
    if (!$rubric || !empty($rubric->status) && $rubric->status === 'error') {
        echo $OUTPUT->notification(get_string('rubric_not_found', 'local_rubricai'), 'error');
    } else {
        echo $OUTPUT->notification(
            get_string('delete_confirm', 'local_rubricai') . ' «' . s($rubric->title ?? $id) . '»',
            'warning'
        );
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'delete']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => '1']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('rubric_delete', 'local_rubricai'), 'class' => 'btn btn-danger me-2']);
        echo html_writer::link($page_url, get_string('cancel', 'local_rubricai'), ['class' => 'btn btn-secondary']);
        echo html_writer::end_tag('form');
    }
}

// ---- CREATE / EDIT FORM ----
if (in_array($action, ['create', 'edit'])) {
    $rubric = null;
    if ($action === 'edit' && $id) {
        $rubric = rag_client::get_rubric($id);
        if (!$rubric || (!empty($rubric->status) && $rubric->status === 'error')) {
            echo $OUTPUT->notification(get_string('rubric_not_found', 'local_rubricai'), 'error');
            echo $OUTPUT->footer();
            exit;
        }
    }

    $title    = $rubric ? s($rubric->title ?? '') : '';
    $desc     = $rubric ? s($rubric->description ?? '') : '';
    $criteria = $rubric ? ($rubric->criteria ?? []) : [];
    $heading  = $action === 'edit'
        ? get_string('rubric_edit', 'local_rubricai')
        : get_string('rubric_create', 'local_rubricai');

    echo html_writer::tag('h4', $heading);
    echo html_writer::start_tag('form', ['method' => 'post', 'id' => 'rubric-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'save']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'rubric_id', 'value' => $id]);

    echo html_writer::start_tag('div', ['class' => 'mb-3']);
    echo html_writer::tag('label', get_string('rubric_title_field', 'local_rubricai'), ['class' => 'form-label fw-bold']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'value' => $title, 'class' => 'form-control', 'required' => 'required']);
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('div', ['class' => 'mb-3']);
    echo html_writer::tag('label', get_string('rubric_desc_field', 'local_rubricai'), ['class' => 'form-label fw-bold']);
    echo html_writer::tag('textarea', $desc, ['name' => 'description', 'class' => 'form-control', 'rows' => '3']);
    echo html_writer::end_tag('div');

    echo html_writer::tag('h5', get_string('rubric_criteria', 'local_rubricai'), ['style' => 'margin-top:20px;']);
    echo html_writer::start_tag('div', ['id' => 'criteria-container']);

    if (empty($criteria)) {
        // Start with one empty criterion row
        $criteria = [['name' => '', 'description' => '', 'weight' => 0, 'dimension' => 'General']];
    }
    foreach ($criteria as $i => $crit) {
        $crit = (object)$crit;
        echo self_rubricai_criterion_row($i, $crit);
    }

    echo html_writer::end_tag('div'); // criteria-container

    echo html_writer::tag('button', '+ ' . get_string('add_criterion', 'local_rubricai'), [
        'type'    => 'button',
        'id'      => 'add-criterion',
        'class'   => 'btn btn-sm btn-outline-secondary mt-2 mb-4',
    ]);

    echo html_writer::tag('button', get_string('save_rubric', 'local_rubricai'), ['type' => 'submit', 'class' => 'btn btn-primary me-2']);
    echo html_writer::link($page_url, get_string('cancel', 'local_rubricai'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');

    // Inline JS for dynamic criterion rows
    $criterion_template = json_encode(self_rubricai_criterion_row('__IDX__', (object)['name' => '', 'description' => '', 'weight' => 0, 'dimension' => 'General']));
    echo html_writer::tag('script', "
        (function() {
            var idx = " . count($criteria) . ";
            var template = " . $criterion_template . ";
            document.getElementById('add-criterion').addEventListener('click', function() {
                var html = template.replace(/__IDX__/g, idx++);
                var div = document.createElement('div');
                div.innerHTML = html;
                document.getElementById('criteria-container').appendChild(div.firstElementChild);
            });
            document.getElementById('criteria-container').addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-criterion')) {
                    e.target.closest('.criterion-row').remove();
                }
            });
        })();
    ");
}

echo $OUTPUT->footer();

/**
 * Renders a single criterion row for the rubric form.
 */
function self_rubricai_criterion_row(int|string $idx, object $crit): string {
    $out  = html_writer::start_tag('div', ['class' => 'criterion-row border rounded p-3 mb-2', 'style' => 'background:#f8f9fa;']);
    $out .= html_writer::start_tag('div', ['class' => 'row g-2']);

    $out .= html_writer::start_tag('div', ['class' => 'col-md-4']);
    $out .= html_writer::tag('label', get_string('criterion_name', 'local_rubricai'), ['class' => 'form-label']);
    $out .= html_writer::empty_tag('input', ['type' => 'text', 'name' => "criterion_name[$idx]", 'value' => s($crit->name ?? ''), 'class' => 'form-control form-control-sm']);
    $out .= html_writer::end_tag('div');

    $out .= html_writer::start_tag('div', ['class' => 'col-md-4']);
    $out .= html_writer::tag('label', get_string('criterion_description', 'local_rubricai'), ['class' => 'form-label']);
    $out .= html_writer::empty_tag('input', ['type' => 'text', 'name' => "criterion_desc[$idx]", 'value' => s($crit->description ?? ''), 'class' => 'form-control form-control-sm']);
    $out .= html_writer::end_tag('div');

    $out .= html_writer::start_tag('div', ['class' => 'col-md-2']);
    $out .= html_writer::tag('label', get_string('criterion_weight', 'local_rubricai'), ['class' => 'form-label']);
    $out .= html_writer::empty_tag('input', ['type' => 'number', 'name' => "criterion_weight[$idx]", 'value' => (int)($crit->weight ?? 0), 'class' => 'form-control form-control-sm', 'min' => '0', 'max' => '100']);
    $out .= html_writer::end_tag('div');

    $out .= html_writer::start_tag('div', ['class' => 'col-md-2']);
    $out .= html_writer::tag('label', get_string('criterion_dimension', 'local_rubricai'), ['class' => 'form-label']);
    $out .= html_writer::empty_tag('input', ['type' => 'text', 'name' => "criterion_dimension[$idx]", 'value' => s($crit->dimension ?? 'General'), 'class' => 'form-control form-control-sm']);
    $out .= html_writer::end_tag('div');

    $out .= html_writer::end_tag('div'); // row

    $out .= html_writer::tag('button', get_string('remove_criterion', 'local_rubricai'), [
        'type'  => 'button',
        'class' => 'remove-criterion btn btn-sm btn-link text-danger mt-1',
    ]);

    $out .= html_writer::end_tag('div'); // criterion-row
    return $out;
}
