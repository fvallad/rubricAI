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
 * RubricAI — Entry point for the pedagogical workflow.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

global $CFG, $DB, $PAGE, $OUTPUT, $USER;

$id     = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', 'lib', PARAM_ALPHANUMEXT);
$step   = optional_param('step', -1, PARAM_INT); // -1 to detect if not provided

// Allow server-side redirect actions to bypass tab validation
$server_actions = ['sync', 'ingest', 'delete_rag', 'preview', 'run_compare'];
if (!isset(\local_rubricai\step_renderer::ACTIONS[$action]) && !in_array($action, $server_actions)) {
    $action = 'lib';
}

$allowed_steps = isset(\local_rubricai\step_renderer::ACTIONS[$action]['steps']) 
    ? \local_rubricai\step_renderer::ACTIONS[$action]['steps'] 
    : [];
if ($step === -1 || (!empty($allowed_steps) && !in_array($step, $allowed_steps))) {
    $step = !empty($allowed_steps) ? $allowed_steps[0] : 0;
}

require_login();

if (!$id) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Please provide a Course ID (?id=XX)', 'error');
    echo $OUTPUT->footer();
    die();
}

require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/course/lib.php');

$context = context_course::instance($id);
$PAGE->set_url(new moodle_url('/local/rubricai/index.php', [
    'id'     => $id, 
    'step'   => $step, 
    'action' => $action
]));
$PAGE->set_context($context);
$PAGE->set_title('RubricAI — Flujo docente');
$PAGE->set_heading('RubricAI — Flujo módulo docente');
$PAGE->set_pagelayout('report');

// Auto-skip Step 0 if RAG already exists (for "Crear Biblioteca" action)
$force_step = optional_param('force_step', 1, PARAM_INT); // Default to 1 to auto-skip if it exists
if ($action === 'lib' && $step === 0 && $id > 0 && $force_step) {
    try {
        $status = \local_rubricai\rag_client::status($id);
        if ($status['data'] && !empty($status['data']->embedding_exists)) {
            $step = 1;
        }
    } catch (\Exception $e) {
        // Silently fail and stay on step 0 if service is down
    }
}

// ------------------------------------------------------------------
// AJAX detection
// ------------------------------------------------------------------
$is_ajax = optional_param('ajax', 0, PARAM_INT);

// ------------------------------------------------------------------
// Session state management
// ------------------------------------------------------------------
try {
    \local_rubricai\session_manager::init();
    \local_rubricai\session_manager::sync_from_request();
} catch (\Throwable $e) {
    // Session init failure is unlikely but we should be safe
    error_log('[RubricAI] Session init failed: ' . $e->getMessage());
}

// ------------------------------------------------------------------
// Action handling (redirect before any rendering)
// ------------------------------------------------------------------
if (in_array($action, $server_actions)) {
    \local_rubricai\action_handler::handle($action, $id, $PAGE->url, (bool)$is_ajax);
    // ^ never returns (redirect + die)
}

// ------------------------------------------------------------------
// Render Header (must be done after action handler to avoid redirect errors)
// ------------------------------------------------------------------
if ($is_ajax) {
    ob_start();
} else {
    echo $OUTPUT->header();
    echo '<style>' . file_get_contents(__DIR__ . '/styles.css') . '</style>';
    echo '<script>' . file_get_contents(__DIR__ . '/rubricai.js') . '</script>';
}

// ------------------------------------------------------------------
// Main rendering inside try/catch for stability
// ------------------------------------------------------------------
try {
    // Fetch course data
    $summary = \local_rubricai\data_provider::get_course_summary($id);
    $files   = \local_rubricai\data_provider::get_course_files($id);
    $step_data = [
        'summary' => $summary,
        'files'   => $files,
        'context' => $context,
        'is_ajax' => $is_ajax
    ];

    // Outer wrapper (only for non-AJAX — AJAX replaces inner content)
    if (!$is_ajax) {
        echo html_writer::start_tag('div', ['class' => 'rubricai-wrap', 'id' => 'rubricai-main']);
    }

    // Inner content
    echo html_writer::start_tag('div', ['class' => 'rubricai-inner']);
    \local_rubricai\step_renderer::render($action, $step, $id, $summary, $files, $context, $is_ajax);
    echo html_writer::end_tag('div'); // rubricai-inner

    if (!$is_ajax) {
        echo html_writer::end_tag('div'); // rubricai-wrap
        echo $OUTPUT->footer();
    } else {
        echo ob_get_clean();
        die();
    }

} catch (\Throwable $e) {
    if ($is_ajax) {
        if (ob_get_level() > 0) ob_end_clean();
        echo 'Error: ' . $e->getMessage();
        die();
    }
    echo $OUTPUT->notification('Error: ' . $e->getMessage(), 'error');
    echo $OUTPUT->footer();
}
