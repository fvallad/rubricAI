<?php
namespace local_rubricai;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles the three server-side actions that redirect: sync, ingest, export.
 *
 * Each action processes data, then issues a Moodle redirect().
 * After calling handle(), the script never continues (redirect dies).
 */
class action_handler {

    /**
     * Dispatch the given action. Returns false if the action is not recognized
     * (so the caller can continue rendering). On recognized actions, this method
     * never returns — it calls redirect() which dies.
     *
     * @param string     $action    One of: 'sync', 'ingest', 'export'
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

            case 'export':
                require_sesskey();
                self::handle_export($course_id, $base_url, $is_ajax);
                return true;

            case 'delete_rag':
                require_sesskey();
                self::handle_delete_rag($course_id, $base_url, $is_ajax);
                return true;

            case 'preview':
                self::handle_preview($course_id);
                return true;

            case 'inject_quiz':
                require_sesskey();
                self::handle_inject_quiz($course_id, $base_url, $is_ajax);
                return true;

            case 'inject_assign':
                require_sesskey();
                self::handle_inject_assign($course_id, $base_url, $is_ajax);
                return true;

            case 'inject_forum':
                require_sesskey();
                self::handle_inject_forum($course_id, $base_url, $is_ajax);
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

        if (!empty($selected_files_raw)) {
            $selected_files = json_decode($selected_files_raw, true);

            if (is_array($selected_files) && count($selected_files) > 0) {
                // Normalize: trim whitespace and unify directory separators
                $selected_files = array_map(function($p) {
                    return str_replace('\\', '/', trim($p));
                }, $selected_files);

                error_log("[RubricAI] Selected files (" . count($selected_files) . "): " . implode(', ', $selected_files));

                $base_sync_dir = rtrim($CFG->dataroot . '/rubricai_sync/course_' . $course_id, '/');

                if (file_exists($base_sync_dir)) {
                    $directory = new \RecursiveDirectoryIterator($base_sync_dir, \RecursiveDirectoryIterator::SKIP_DOTS);
                    $iterator  = new \RecursiveIteratorIterator($directory);

                    foreach ($iterator as $file) {
                        if ($file->isDir()) continue;

                        // Relative path from course sync dir, normalized to forward slashes
                        $relative_path = str_replace('\\', '/', substr($file->getPathname(), strlen($base_sync_dir) + 1));

                        if (!in_array($relative_path, $selected_files)) {
                            error_log("[RubricAI] Deleting unselected: {$relative_path}");
                            @unlink($file->getPathname());
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
     * Export the generated instrument + rubric as a Moodle Assign activity.
     */
    private static function handle_export(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        $inst_name = session_manager::get('instrument', '') . ' - RubricAI';
        $final_desc = session_manager::get('inst_content', '');

        $rubric = session_manager::get('rubric_content', '');
        if (!empty($rubric)) {
            $final_desc .= "\n\n### Rúbrica\n" . $rubric;
        }

        if (!$inst_name) {
            $inst_name = 'Evaluación RubricAI';
        }
        if (!$final_desc) {
            $final_desc = 'Instrumento generado por RubricAI.';
        }

        $moduleinfo = \local_rubricai\data_provider::create_assign_activity($course_id, $inst_name, $final_desc);

        // Force a valid tab action to avoid infinite redirect loop
        $action = optional_param('action', 'eval', PARAM_ALPHA);
        if ($action === 'export') {
            $action = 'eval';
        }

        $redir = new \moodle_url($base_url, [
            'step'     => 7,
            'exported' => 1,
            'cmid'     => $moduleinfo->coursemodule,
            'action'   => $action,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    private static function handle_inject_quiz(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        $section_num = optional_param('section_num', 0, PARAM_INT);

        // 1. Obtener el puntaje total máximo solicitado (Ej: 7.0 o 100.0)
        $max_grade = optional_param('max_grade', 100.0, PARAM_FLOAT);

        // LEER DESDE SESIÓN: Ya no dependemos del payload pesado por POST
        $raw_selection = session_manager::get('final_selection_json', '');

        $questions = [];
        if (!empty($raw_selection)) {
            $parsed = json_decode($raw_selection, true);
            if (is_array($parsed) && !empty($parsed['items'])) {
                $questions = $parsed['items'];
                
                // Read point distribution securely from POST directly
                $item_points = optional_param_array('item_points', [], PARAM_RAW);
                foreach ($questions as $idx => &$q) {
                    if (isset($item_points[$idx])) {
                        $weight_percentage = (float)$item_points[$idx];
                        $q['weight'] = $weight_percentage; // Persist the percentage weight
                        $q['points'] = round(($weight_percentage / 100.0) * $max_grade, 2); // Calculate absolute points
                    }
                }
                unset($q); // break reference
                
                // Actualizar la sesión con los pesos finales configurados por el usuario antes de inyectar
                $parsed['items'] = $questions;
                session_manager::set('final_selection_json', json_encode($parsed));
            }
        }

        // 2. Si no hay preguntas, error
        if (empty($questions)) {
            $redir = new \moodle_url($base_url, [
                'step'         => 5,
                'quiz_error'   => 1,
                'message'      => 'No se detectó una selección válida de ítems.'
            ]);
            redirect($redir);
        }

        try {
            // Se pasa el max_grade además de name (si se desea uno por defecto se pasa null o string custom)
            $result = \local_rubricai\data_provider::create_quiz_activity($course_id, $section_num, $questions, 'Cuestionario RubricAI', $max_grade);
            if (!$result || !isset($result['coursemodule'])) {
                throw new \moodle_exception('error_creating_quiz', 'local_rubricai', '', null, 'Result is empty or invalid');
            }
            $quiz_cmid = $result['coursemodule'];
        } catch (\Throwable $e) {
            error_log('[RubricAI] inject_quiz error in course ' . $course_id . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $redir = new \moodle_url($base_url, [
                'step'         => 7,
                'action'       => 'eval',
                'quiz_error'   => 1,
            ]);
            redirect($redir);
        }

        $redir = new \moodle_url($base_url, [
            'step'         => 7,
            'action'       => 'eval',
            'quiz_injected'=> 1,
            'quiz_cmid'    => $quiz_cmid,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Returns the fake questions for quiz injection.
     * Moved from step7 to action_handler for better availability.
     */
    public static function get_fake_questions(): array {
        return [
            [
                'type'    => 'multichoice',
                'text'    => 'Cual de los siguientes es un ejemplo de evaluacion formativa?',
                'options' => [
                    'Examen final del semestre',
                    'Retroalimentacion continua durante el proceso de aprendizaje',
                    'Prueba de admision universitaria',
                    'Calificacion numerica trimestral',
                ],
                'correct' => 1,
            ],
            [
                'type'    => 'truefalse',
                'text'    => 'La taxonomia de Bloom clasifica los objetivos de aprendizaje en niveles cognitivos jerarquicos.',
                'correct' => true,
            ],
            [
                'type' => 'essay',
                'text' => 'Describe como diseñarias una evaluacion autentica para tu asignatura. Fundamenta tu respuesta considerando el contexto pedagogico del curso.',
            ],
        ];
    }

    /**
     * Export the generated instrument as an Assign activity (with section selection).
     */
    private static function handle_inject_assign(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        $section_num = optional_param('section_num', 0, PARAM_INT);
        $inst_name = session_manager::get('instrument', '') . ' - RubricAI';
        $inst_content = session_manager::get('inst_content', '');
        $rubric_content = session_manager::get('rubric_content', '');

        // Build a rich description from the instrument items
        $description = self::build_activity_description($inst_content, $rubric_content);

        if (!$inst_name || $inst_name === ' - RubricAI') {
            $inst_name = 'Evaluación RubricAI';
        }

        try {
            $moduleinfo = data_provider::create_assign_activity($course_id, $inst_name, $description, $section_num);
            $cmid = $moduleinfo->coursemodule;
        } catch (\Throwable $e) {
            error_log('[RubricAI] inject_assign error: ' . $e->getMessage());
            $redir = new \moodle_url($base_url, [
                'step'       => 7,
                'action'     => 'eval',
                'export_error' => 1,
            ]);
            redirect($redir);
        }

        $redir = new \moodle_url($base_url, [
            'step'          => 7,
            'action'        => 'eval',
            'assign_injected' => 1,
            'assign_cmid'   => $cmid,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Export the generated instrument as a Forum activity (with section selection).
     */
    private static function handle_inject_forum(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        $section_num = optional_param('section_num', 0, PARAM_INT);
        $inst_name = session_manager::get('instrument', '') . ' - RubricAI';
        $inst_content = session_manager::get('inst_content', '');
        $rubric_content = session_manager::get('rubric_content', '');

        $description = self::build_activity_description($inst_content, $rubric_content);

        if (!$inst_name || $inst_name === ' - RubricAI') {
            $inst_name = 'Debate RubricAI';
        }

        try {
            $moduleinfo = data_provider::create_forum_activity($course_id, $inst_name, $description, $section_num);
            $cmid = $moduleinfo->coursemodule;
        } catch (\Throwable $e) {
            error_log('[RubricAI] inject_forum error: ' . $e->getMessage());
            $redir = new \moodle_url($base_url, [
                'step'       => 7,
                'action'     => 'eval',
                'export_error' => 1,
            ]);
            redirect($redir);
        }

        $redir = new \moodle_url($base_url, [
            'step'          => 7,
            'action'        => 'eval',
            'forum_injected' => 1,
            'forum_cmid'    => $cmid,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Build a rich Markdown description from the structured instrument content.
     */
    private static function build_activity_description(string $inst_content, string $rubric_content): string {
        $parts = [];
        $data = @json_decode($inst_content, true);

        if (is_array($data)) {
            if (!empty($data['title'])) {
                $parts[] = '## ' . $data['title'];
            }

            foreach (($data['items'] ?? []) as $idx => $item) {
                $num = $idx + 1;
                $type_label = $item['type'] ?? 'Ítem';
                $difficulty = $item['difficulty'] ?? '';
                $header = "### Ítem {$num} — {$type_label}";
                if ($difficulty) {
                    $header .= " ({$difficulty})";
                }
                $parts[] = $header;
                $parts[] = $item['consiga'] ?? $item['text'] ?? '';

                // Add options if present (for reference)
                if (!empty($item['alternativas'])) {
                    $parts[] = '';
                    foreach ($item['alternativas'] as $oi => $opt) {
                        $letter = chr(65 + $oi); // A, B, C, ...
                        $parts[] = "{$letter}. {$opt}";
                    }
                }

                // Add objectives
                if (!empty($item['objectives'])) {
                    $parts[] = '';
                    $parts[] = '**Objetivos:** ' . implode(', ', $item['objectives']);
                }
                $parts[] = ''; // spacer
            }

            if (!empty($data['justification'])) {
                $parts[] = '---';
                $parts[] = '**Justificación Pedagógica:** ' . $data['justification'];
            }
        } else {
            // Fallback: use raw content as-is
            $parts[] = $inst_content ?: 'Instrumento generado por RubricAI.';
        }

        if (!empty($rubric_content)) {
            $parts[] = '';
            $parts[] = '---';
            $parts[] = '## Rúbrica';
            $parts[] = $rubric_content;
        }

        return implode("\n", $parts);
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

        // 1. Gather all course resources and activities
        $payload = data_provider::get_course_full_evaluation_payload($course_id);

        // 2. Call python RAG service evaluate endpoint
        $result = rag_client::evaluate($course_id, $rubric_id, $payload);

        if ($result) {
            $score = isset($result->overall_score) ? (float)$result->overall_score : 50.0;
            $holistic = $result->holistic_evaluation ?? 'No disponible.';
            $format = $result->format_evaluation ?? 'No disponible.';
            $recs = isset($result->recommendations) ? (array)$result->recommendations : [];

            // Add debug logs to PHP error log
            error_log("RubricAI Audit Completed - Course: $course_id, Rubric: $rubric_id, Score: $score");

            // Save to Moodle database (config_plugins)
            session_manager::save_audit_results($course_id, $score, $holistic, $format, $recs, $rubric_id);

            // Also keep in session with course-specific isolation
            session_manager::set('compare_score_' . $course_id, $score);
            session_manager::set('compare_holistic_' . $course_id, $holistic);
            session_manager::set('compare_format_' . $course_id, $format);
            session_manager::set('compare_recommendations_' . $course_id, json_encode($recs));
            session_manager::set('compare_rubric_id_' . $course_id, $rubric_id);

            $redir = new \moodle_url($base_url, ['step' => 8, 'action' => 'compare', 'compared' => 1]);
        } else {
            error_log("RubricAI Audit Failed - Course: $course_id, Rubric: $rubric_id");
            $redir = new \moodle_url($base_url, ['step' => 8, 'action' => 'compare', 'error' => 'evaluation_failed']);
        }

        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }
}
