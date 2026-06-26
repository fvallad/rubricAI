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

namespace local_rubricai;

defined('MOODLE_INTERNAL') || die();

/**
 * Action handler for sync, ingest, and delete operations.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_handler {

    /**
     * Dispatch the given action. Returns false if the action is not recognized
     * (so the caller can continue rendering). On recognized actions, this method
     * never returns — it calls redirect() which dies.
     *
     * @param string     $action    One of: 'sync', 'ingest', 'delete_rag', 'preview', 'run_compare'
     * @param int        $course_id
     * @param \moodle_url $base_url  The current $PAGE->url
     * @param bool       $is_ajax
     * @return bool  false if action not handled
     */
    public static function handle(string $action, int $course_id, \moodle_url $base_url, bool $is_ajax): bool {
        switch ($action) {
            case 'sync':
                self::handle_sync($course_id, $base_url, $is_ajax);
                return true; // never reached

            case 'ingest':
                require_sesskey();
                self::handle_ingest($course_id, $base_url, $is_ajax);
                return true;

            case 'delete_rag':
                require_sesskey();
                self::handle_delete_rag($course_id, $base_url, $is_ajax);
                return true;

            case 'preview':
                self::handle_preview($course_id);
                return true;

            case 'run_compare':
                self::handle_run_compare($course_id, $base_url, $is_ajax);
                return true;

            default:
                return false;
        }
    }

    // ------------------------------------------------------------------
    // Individual action handlers
    // ------------------------------------------------------------------

    /**
     * Sync course files to the Python service and redirect to Step 1.
     */
    private static function handle_sync(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        // Extract files to sync dir + get summary
        data_provider::get_course_files($course_id, true);
        $summary = data_provider::get_course_summary($course_id);

        // POST /sync
        rag_client::sync($summary);

        // Always return to the Library tab (Step 1)
        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1, 'action' => 'lib']);
        if ($is_ajax) {
            // For AJAX: return JSON with redirect URL, avoid Moodle's redirect() which mutates session
            header('Content-Type: application/json');
            echo json_encode(['redirect' => $redir->out(false)]);
            if (ob_get_level() > 0) ob_end_clean();
            exit();
        }
        redirect($redir);
    }

    /**
     * Trigger embedding ingestion and redirect to Step 1 with result status.
     */
    private static function handle_ingest(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        global $CFG;
        \core_php_time_limit::raise(600);

        // 1. Force a clean physical extraction of ALL allowed course files to disk.
        data_provider::get_course_files($course_id, true);

        // 2. Filter: only keep user-selected files on disk before calling Python.
        $selected_files_raw = optional_param('selected_files', '', PARAM_RAW);
        error_log("[RubricAI] handle_ingest course={$course_id} selected_files_raw=" . substr($selected_files_raw, 0, 300));

        $base_sync_dir = rtrim($CFG->dataroot . '/rubricai_sync/course_' . $course_id, '/');

        if (!empty($selected_files_raw)) {
            $selected_files = json_decode($selected_files_raw, true);

            if (is_array($selected_files) && count($selected_files) > 0) {
                // Normalize: trim whitespace and unify directory separators
                $selected_files = array_map(function($p) {
                    return str_replace('\\', '/', trim($p));
                }, $selected_files);

                error_log("[RubricAI] Selected files (" . count($selected_files) . "): " . implode(', ', $selected_files));

                if (file_exists($base_sync_dir)) {
                    $directory = new \RecursiveDirectoryIterator($base_sync_dir, \RecursiveDirectoryIterator::SKIP_DOTS);
                    $iterator  = new \RecursiveIteratorIterator($directory);

                    foreach ($iterator as $file) {
                        if ($file->isDir()) continue;

                        // Relative path from course sync dir, normalized to forward slashes
                        $relative_path = str_replace('\\', '/', substr($file->getPathname(), strlen($base_sync_dir) + 1));

                        if (!in_array($relative_path, $selected_files)) {
                            error_log("[RubricAI] Unselected (skipping physical unlink): {$relative_path}");
                        } else {
                            error_log("[RubricAI] Keeping selected: {$relative_path}");
                        }
                    }
                }
            } else {
                error_log("[RubricAI] WARNING: selected_files JSON decoded to empty/non-array. Raw: " . $selected_files_raw);
            }
        } else {
            error_log("[RubricAI] WARNING: selected_files is empty — ingesting ALL files.");
        }

        $res_data = rag_client::ingest($course_id, $selected_files ?? [], $base_sync_dir);

        // Determine ingestion state: 1=success, 2=empty, 3=processing, -1=error
        if ($res_data && $res_data->status == 'success') {
            $state = ($res_data->chunks > 0) ? 1 : 2;
        } else if ($res_data && $res_data->status == 'started') {
            $state = 3;
        } else {
            $state = -1;
        }
        if ($res_data && isset($res_data->chunks) && $res_data->chunks === 0) {
            $state = 2;
        }

        // Always return to the Library tab (Step 1)
        // Set deleted=1 param so UI can potentially show a small flash message (optional)
        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1, 'ingested' => $state, 'deleted' => 1, 'action' => 'lib']);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Delete the existing RAG embedding for a course.
     */
    private static function handle_delete_rag(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        rag_client::delete($course_id);
        data_provider::delete_sync_dir($course_id);
        
        $redir = new \moodle_url($base_url, ['step' => 0, 'action' => 'lib', 'force_step' => 0]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Fetch LLM prompt preview and return as JSON.
     */
    private static function handle_preview(int $course_id): void {
        header('Content-Type: application/json');
        
        $step     = optional_param('p_step', 4, PARAM_INT);
        $feedback = optional_param('feedback', session_manager::get('feedback', ''), PARAM_TEXT);
        
        $summary = data_provider::get_course_summary($course_id);
        
        $data = [
            'course_id'          => $course_id,
            'step'               => $step,
            'objective'          => session_manager::get('d2', ''),
            'objective_json'     => session_manager::get('d2_json', ''),
            'dimensions'         => "Contenido: " . session_manager::get('d1', '') . 
                                   ", Función: " . session_manager::get('d3', '') . 
                                   ", Modalidad: " . session_manager::get('d4', ''),
            'd1_content'         => session_manager::get('d1', ''),
            'd3_function'        => session_manager::get('d3', ''),
            'd4_modality'        => session_manager::get('d4', ''),
            'feedback'           => $feedback,
            'chosen_instrument'  => session_manager::get('instrument') ?: session_manager::get('sel_sug', ''),
            'instrument_content' => session_manager::get('inst_content', ''),
        ];

        $res = rag_client::preview_prompt($data);
        echo json_encode($res ?: ['status' => 'error', 'message' => 'Servicio de IA no disponible']);
        die();
    }

    /**
     * Gathers course activities and resources, sends them to python microservice for evaluation.
     */
    private static function handle_run_compare(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        global $PAGE;
        \core_php_time_limit::raise(600);

        $rubric_id = optional_param('rubric_id', '', PARAM_RAW);
        if (empty($rubric_id)) {
            $redir = new \moodle_url($base_url, ['step' => 8, 'action' => 'compare', 'error' => 'no_rubric']);
            redirect($redir);
        }

        $rubric = rag_client::get_rubric($rubric_id);
        if (!$rubric || empty($rubric->criteria)) {
            $redir = new \moodle_url($base_url, ['step' => 8, 'action' => 'compare', 'error' => 'rubric_empty']);
            redirect($redir);
        }

        // 1. Gather all course resources and activities
        $payload = data_provider::get_course_full_evaluation_payload($course_id);

        // Release session lock before the long-running Python call so other
        // requests from the same user don't block waiting 120s for the lock.
        \core\session\manager::write_close();

        // 2. Call python RAG service evaluate endpoint
        $result = rag_client::evaluate($course_id, $rubric_id, $payload);

        if ($result && (!isset($result->status) || $result->status !== 'error') && isset($result->overall_score)) {
            $score = (float)$result->overall_score;
            $holistic = $result->holistic_evaluation ?? 'No disponible.';
            $format = $result->format_evaluation ?? 'No disponible.';
            $recs = isset($result->recommendations) ? (array)$result->recommendations : [];

            // Add debug logs to PHP error log
            error_log("RubricAI Audit Completed - Course: $course_id, Rubric: $rubric_id, Score: $score");

            // Save to Moodle database (config_plugins)
            session_manager::save_audit_results($course_id, $score, $holistic, $format, $recs, $rubric_id, time());

            // Clear previous errors if any
            session_manager::unset_key('compare_error_' . $course_id);

            // Also keep in session with course-specific isolation
            session_manager::set('compare_score_' . $course_id, $score);
            session_manager::set('compare_holistic_' . $course_id, $holistic);
            session_manager::set('compare_format_' . $course_id, $format);
            session_manager::set('compare_recommendations_' . $course_id, json_encode($recs));
            session_manager::set('compare_rubric_id_' . $course_id, $rubric_id);

            $redir = new \moodle_url($base_url, ['step' => 8, 'action' => 'compare', 'compared' => 1]);
        } else {
            error_log("RubricAI Audit Failed - Course: $course_id, Rubric: $rubric_id");
            $error_msg = 'La evaluación multiagente falló. Verifica que el microservicio de Python esté operativo y configurado en tu archivo .env.';
            if ($result && isset($result->message)) {
                $error_msg = 'Error del microservicio: ' . htmlspecialchars($result->message);
            }
            session_manager::set('compare_error_' . $course_id, $error_msg);
            $redir = new \moodle_url($base_url, ['step' => 8, 'action' => 'compare', 'error' => 'evaluation_failed']);
        }

        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }
}
