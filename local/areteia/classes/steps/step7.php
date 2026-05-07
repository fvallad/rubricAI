<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\data_provider;
use local_areteia\step_renderer;
use local_areteia\encaje_table;

/**
 * Step 7 — Resultado final.
 * Preview of the complete instrument and export to Moodle.
 */
class step7 {

    public static function render(array $ctx): void {
        $action = $ctx['action'] ?? 'eval';

        if ($action === 'crit') {
            self::render_crit($ctx);
        } else {
            self::render_eval($ctx);
        }
    }

    // ==================================================================
    // ACTION = crit — Final Correction Instrument View
    // ==================================================================

    private static function render_crit(array $ctx): void {
        global $PAGE;

        $id = $ctx['id'];
        $instrument = session_manager::get('instrument', '');
        $correction = session_manager::get('correction_instrument', '');
        $correction_content = session_manager::get('correction_content', '');
        $link_params = ['id' => $id, 'action' => 'crit'];

        $corr_label = encaje_table::LABELS[$correction] ?? $correction;
        $corr_icon = encaje_table::ICONS[$correction] ?? '📄';

        echo html_writer::tag('span', 'Paso 2 — Resultado Final', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Instrumento de corrección finalizado', ['class' => 'areteia-stitle']);

        // Guard
        if (empty($correction_content)) {
            echo html_writer::tag('div', 'No hay instrumento de corrección generado. Vuelve al paso anterior.', ['class' => 'alert alert-warning']);
            $prev_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 6]));
            step_renderer::render_nav(7, $prev_url);
            return;
        }

        // Summary header
        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'background:#f0f4ff; border:1px solid #d0d8f0; padding:15px; margin-bottom:20px;'
        ]);
        echo html_writer::tag('div', "📝 Evaluación: <strong>$instrument</strong>", ['style' => 'font-size:13px; color:#555; margin-bottom:5px;']);
        echo html_writer::tag('div', "$corr_icon Corrección: <strong>$corr_label</strong>", ['style' => 'font-size:13px; color:#2e7d32;']);
        echo html_writer::end_tag('div');

        // Render the correction instrument
        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'padding:20px; margin-bottom:20px;'
        ]);

        $data = json_decode($correction_content, true);
        if (is_array($data)) {
            // Title
            if (!empty($data['title'])) {
                echo html_writer::tag('h3', s($data['title']), ['style' => 'color:#185fa5; margin-bottom:15px;']);
            }

            // Use step6 renderers (they are the same class namespace)
            step6::render_correction_public($correction, $data);

            // Justification
            if (!empty($data['justification'])) {
                echo html_writer::start_tag('div', ['style' => 'font-size:12px; color:#666; font-style:italic; padding:15px; background:#f9f9f9; border-radius:10px; margin-top:20px; border:1px solid #eee;']);
                echo html_writer::tag('strong', '💡 Justificación Pedagógica: ', ['style' => 'color:#185fa5;']);
                echo s($data['justification'] ?? '');
                echo html_writer::end_tag('div');
            }
        } else {
            echo html_writer::tag('div', 'Error decodificando el instrumento.', ['class' => 'alert alert-danger']);
        }

        echo html_writer::end_tag('div');

        // Completion banner
        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'border-left:5px solid #28a745; background:#f4fff4; padding:15px;'
        ]);
        echo html_writer::tag('strong', '✅ Instrumento de corrección completado', ['style' => 'color:#28a745; display:block; margin-bottom:5px;']);
        echo html_writer::tag('p', 'Tu instrumento de corrección está listo. Puedes volver al paso anterior para refinarlo o usar la evaluación en tu curso.', [
            'style' => 'font-size:12px; margin:0; color:#555;'
        ]);
        echo html_writer::end_tag('div');

        // Navigation
        $prev_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 6]));
        step_renderer::render_nav(7, $prev_url, null, '', [], '✔ Completado');
    }

    // ==================================================================
    // ACTION = eval — Quiz Injection (existing, unchanged)
    // ==================================================================

    private static function render_eval(array $ctx): void {
        global $PAGE;

        // Capture incoming form submission from Step 5
        $selected_indices = optional_param_array('selected_items', [], PARAM_INT);
        $src_data         = optional_param('src_data_payload', '', PARAM_RAW);
        
        // If we have selected indices, we process the selection
        if (!empty($selected_indices)) {
            $data = null;
            if (!empty($src_data)) {
                $data = json_decode($src_data, true);
            }
            
            // Fallback: If POST payload is missing or invalid, try reading from session
            if (!is_array($data) || empty($data['items'])) {
                $inst_content = session_manager::get('inst_content', '');
                if (!empty($inst_content)) {
                    $decode_attempt = json_decode($inst_content, true);
                    if (is_array($decode_attempt)) {
                        $data = $decode_attempt;
                        // Ensure items are sequential for index matching
                        $data['items'] = array_values($data['items'] ?? []);
                    }
                }
            }

            // If we still don't have data, we can't process filtered items
            if (!is_array($data) || empty($data['items'])) {
                error_log("[AreteIA] Failed to resolve source data for step 7");
            } else {
                $filtered_items = [];
                $num_sel = count($selected_indices);
                $base_weight = floor(100 / max(1, $num_sel));
                $default_weights = array_fill(0, max(0, $num_sel - 1), $base_weight);
                if ($num_sel > 0) {
                    $default_weights[] = 100 - ($base_weight * ($num_sel - 1));
                }

                $current_idx = 0;
                foreach ($selected_indices as $idx) {
                    if (isset($data['items'][$idx])) {
                        $item = $data['items'][$idx];
                        $t = strtolower($item['type'] ?? '');
                        
                        $q = [
                            'text' => $item['consiga'] ?? '',
                            'points' => $item['points'] ?? ($num_sel > 0 ? $default_weights[$current_idx] : 1.0),
                            'difficulty' => $item['difficulty'] ?? 'Media'
                        ];
                        $current_idx++;
                        
                        if (strpos($t, 'múltiple') !== false || strpos($t, 'selección') !== false || strpos($t, 'cerrada') !== false) {
                            $q['type'] = 'multichoice';
                            $q['options'] = $item['alternativas'] ?? [];
                            $q['correct'] = isset($item['correct_index']) ? (int)$item['correct_index'] : 0;
                        } elseif (strpos($t, 'verdadero') !== false) {
                            $q['type'] = 'truefalse';
                            $q['correct'] = isset($item['correct_boolean']) ? (bool)$item['correct_boolean'] : true;
                        } elseif (strpos($t, 'emparejamiento') !== false || strpos($t, 'orden') !== false) {
                            $q['type'] = 'match';
                            $q['pairs'] = array_map(function($p) {
                                return ['premise' => $p['premise'] ?? '', 'answer' => $p['answer'] ?? ''];
                            }, $item['pairs'] ?? []);
                        } elseif (strpos($t, 'breve') !== false || strpos($t, 'clásica') !== false) {
                            $q['type'] = 'shortanswer';
                            $q['correct'] = $item['short_answer'] ?? '';
                        } elseif (strpos($t, 'numérica') !== false) {
                            $q['type'] = 'numerical';
                            $q['correct'] = isset($item['numerical_value']) ? (float)$item['numerical_value'] : 0.0;
                        } else {
                            $q['type'] = 'essay';
                        }
                        
                        $filtered_items[] = $q;
                    }
                }
                
                if (!empty($filtered_items)) {
                    $final_json_payload = json_encode([
                        'title' => $data['title'] ?? 'Evaluación Final',
                        'justification' => $data['justification'] ?? '',
                        'items' => $filtered_items
                    ]);
                    
                    session_manager::set('final_selection_json', $final_json_payload);
                }
            }
        }

        $context        = $ctx['context'];
        $instrument     = session_manager::get('instrument', '');
        $inst_content   = session_manager::get('inst_content', '');
        $rubric_content = session_manager::get('rubric_content', '');
        $exported       = session_manager::get('exported', 0);
        $cmid           = session_manager::get('cmid', 0);

        echo html_writer::tag('span', 'Paso 7 — Resultado final', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Instrumento de evaluación finalizado', ['class' => 'areteia-stitle']);

        // Export success banner
        if ($exported == 1) {
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left: 5px solid #28a745; background: #f4fff4; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '🚀 ¡Actividad publicada en Moodle!', [
                'style' => 'color:#28a745; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p', 'La tarea ha sido creada exitosamente.', [
                'style' => 'font-size:12px; margin-bottom:10px;',
            ]);
            echo html_writer::link(
                new moodle_url('/mod/assign/view.php', ['id' => $cmid]),
                'Ir a la actividad en Moodle ↗',
                ['class' => 'areteia-btn areteia-btn-primary external', 'target' => '_blank']
            );
            echo html_writer::end_tag('div');
        }

        // Preview card
        echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'margin-bottom:20px;']);
        
        $final_json = session_manager::get('final_selection_json', '');
        $is_quiz = !empty($final_json);
        $quiz_injected = optional_param('quiz_injected', 0, PARAM_INT);
        
        if ($is_quiz) {
            $data = json_decode($final_json, true);
            echo html_writer::tag('p', "<strong>Configuración del Cuestionario: " . s($data['title'] ?? '') . "</strong>", [
                'style' => 'color:#185fa5; font-size:1.1em; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;',
            ]);

            // Start form for Quiz Injection
            $inject_url = new moodle_url($PAGE->url, [
                'action'  => 'inject_quiz',
                'id'      => $ctx['id'],
                'sesskey' => sesskey()
            ]);
            echo html_writer::start_tag('form', ['id' => 'quiz-config-form', 'method' => 'POST', 'action' => $inject_url->out(false)]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            // selection_json removed - now using session_manager directly in backend to avoid POST bloat

            echo html_writer::start_tag('div', [
                'style' => 'background:#fcfcfc; padding:15px; border-radius:8px; border:1px solid #eee; max-height:400px; overflow-y:auto;'
            ]);

            $item_count = count($data['items'] ?? []);
            $default_pct = $item_count > 0 ? round(100.0 / $item_count, 1) : 100.0;

            foreach (($data['items'] ?? []) as $index => $item) {
                echo html_writer::start_tag('div', ['style' => 'margin-bottom:10px; border-bottom:1px solid #f0f0f0; padding-bottom:10px; display:flex; justify-content:space-between; gap:20px;']);
                echo html_writer::start_tag('div', ['style' => 'flex:1; font-size:12px;']);
                echo html_writer::tag('div', '<strong>' . ($index + 1) . '. ' . s($item['type']) . '</strong>', ['style' => 'color:#6c63ff; font-size:11px;']);
                echo html_writer::tag('div', format_text($item['text'] ?? $item['consiga'] ?? '', FORMAT_MARKDOWN));
                echo html_writer::end_tag('div');

                // Peso/Ponderación input
                echo html_writer::start_tag('div', ['style' => 'text-align:center; min-width:110px;']);
                echo html_writer::tag('label', 'Ponderación (%):', ['style' => 'font-size:11px; font-weight:bold; color:#555; display:block; margin-bottom:2px;']);
                echo html_writer::start_tag('div', ['style' => 'display:flex; align-items:center; justify-content:center; gap:5px;']);
                
                // Prioritize 'weight' key (percentage), fallback to points (which was percentage initially)
                $val = $item['weight'] ?? ($item['points'] ?? $default_pct);

                echo html_writer::empty_tag('input', [
                    'type'  => 'number',
                    'name'  => "item_points[$index]",
                    'step'  => '0.1',
                    'min'   => '0.1',
                    'max'   => '100',
                    'value' => $val,
                    'class' => 'quiz-item-points form-control',
                    'data-idx' => $index,
                    'style' => 'width:60px; text-align:center; padding:4px;'
                ]);
                echo html_writer::tag('span', '%', ['style' => 'font-size:12px; color:#555; font-weight:bold;']);
                echo html_writer::end_tag('div');
                
                // Show calculated absolute points (updated via JS)
                $max_grade = (float)session_manager::get('max_grade', 100.0);
                $abs_points = round(($val / 100.0) * $max_grade, 2);
                echo html_writer::tag('div', "($abs_points pts)", [
                    'class' => 'quiz-item-abs-points',
                    'data-idx' => $index,
                    'style' => 'font-size:10px; color:#999; margin-top:2px;'
                ]);

                echo html_writer::end_tag('div');

                echo html_writer::end_tag('div');
            }
            
            if (empty($data['items'])) {
                echo html_writer::tag('div', 'No hay ítems seleccionados para configurar. Vuelve al paso anterior.', ['class' => 'alert alert-info']);
            }
            echo html_writer::end_tag('div');

            // Global Settings: Max Grade & Section selection
            echo html_writer::start_tag('div', ['style' => 'display:flex; align-items:center; justify-content:space-between; margin-top:20px; gap:20px; flex-wrap:wrap;']);
            
            // Max Grade
            echo html_writer::start_tag('div', ['style' => 'flex:1; min-width:200px;']);
            echo html_writer::tag('strong', 'Puntaje final total (Max Grade):', ['style' => 'display:block; font-size:13px; margin-bottom:5px;']);
            echo html_writer::empty_tag('input', [
                'id' => 'max_grade_input',
                'type' => 'number',
                'name' => 'max_grade',
                'step' => '0.1',
                'value' => session_manager::get('max_grade', '100'), // Default 100 max points
                'class' => 'form-control',
                'style' => 'max-width:150px;'
            ]);
            echo html_writer::end_tag('div');

            // Section Info
            $sections = data_provider::get_course_sections($ctx['id']);
            echo html_writer::start_tag('div', ['style' => 'flex:1; min-width:200px;']);
            echo html_writer::tag('strong', 'Ubicación en Moodle:', ['style' => 'display:block; font-size:13px; margin-bottom:5px;']);
            echo html_writer::start_tag('select', ['name' => 'section_num', 'class' => 'form-control', 'style' => 'max-width:280px; font-size:13px;']);
            foreach ($sections as $sec) {
                echo html_writer::tag('option', s($sec['name']), ['value' => $sec['num']]);
            }
            echo html_writer::end_tag('select');
            echo html_writer::end_tag('div');

            if ($quiz_injected != 1) {
                echo html_writer::tag('button', '🚀 Publicar Cuestionario en Moodle', [
                    'id'    => 'btn-publish-quiz',
                    'type'  => 'submit',
                    'class' => 'areteia-btn areteia-btn-primary',
                    'style' => 'background:#28a745; border-color:#28a745;'
                ]);
            }
            echo html_writer::end_tag('div'); // flex container

            echo html_writer::end_tag('form'); // end quiz form

        } else {
            echo html_writer::tag('p', "<strong>Vista previa final: $instrument</strong>", [
                'style' => 'color:#185fa5; font-size:1.1em; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;',
            ]);

            // Instrument preview
            echo html_writer::tag('div', '<strong>Consignas e Ítems:</strong>', [
                'style' => 'font-size:13px; font-weight:bold; margin-bottom:5px;',
            ]);
            
            $preview_inst = mb_strimwidth($inst_content, 0, 500, '...');
            echo html_writer::tag('div',
                format_text($preview_inst, FORMAT_MARKDOWN, ['context' => $context]),
                [
                    'class' => 'areteia-markdown-content',
                    'style' => 'font-size:12px; background:#fcfcfc; padding:10px; border-radius:8px; margin-bottom:15px; border:1px solid #eee;',
                ]
            );

            // Rubric preview
            if (!empty($rubric_content)) {
                echo html_writer::tag('div', '<strong>Rúbrica:</strong>', [
                    'style' => 'font-size:13px; font-weight:bold; margin-bottom:5px;',
                ]);
                $preview_rub = mb_strimwidth($rubric_content, 0, 500, '...');
                echo html_writer::tag('div',
                    format_text($preview_rub, FORMAT_MARKDOWN, ['context' => $context]),
                    [
                        'class' => 'areteia-markdown-content',
                        'style' => 'font-size:12px; background:#fcfcfc; padding:10px; border-radius:8px; border:1px solid #eee;',
                    ]
                );
            }
        }
        echo html_writer::end_tag('div');

        // Total Usage Summary
        $u4 = session_manager::get('s4_usage', []);
        $u5 = session_manager::get('s5_usage', []);
        $u7 = session_manager::get('s7_usage', []); // For future rubric gen

        $total_in  = ($u4['input_tokens'] ?? 0) + ($u5['input_tokens'] ?? 0) + ($u7['input_tokens'] ?? 0);
        $total_out = ($u4['output_tokens'] ?? 0) + ($u5['output_tokens'] ?? 0) + ($u7['output_tokens'] ?? 0);
        
        if ($total_in > 0) {
            $total_usage = [
                'input_tokens' => $total_in,
                'output_tokens' => $total_out,
                'total_tokens' => $total_in + $total_out
            ];
            $usage_json = json_encode($total_usage);
            echo "<script>console.log('AI Token Usage (Total):', {$usage_json});</script>";
        }

        // Navigation
        $prev_url   = new moodle_url($PAGE->url, ['step' => 6]); // Now goes back to 5 due to sequence update
        $export_url = new moodle_url($PAGE->url, ['action' => 'export', 'sesskey' => sesskey()]);

        if ($exported == 1 || $quiz_injected == 1) { // Hide if either is published to avoid confusion
            step_renderer::render_nav(7, $prev_url, null, '', [], '✔ Publicado con éxito');
        } else {
            // Se pasa null en el tercer parámetro para eliminar el botón duplicado de "Publicar"
            step_renderer::render_nav(7, $prev_url, null);
        }

        // ----------------------------------------------------------------
        // Quiz injection block
        // ----------------------------------------------------------------
        $quiz_injected = optional_param('quiz_injected', 0, PARAM_INT);
        $quiz_error    = optional_param('quiz_error', 0, PARAM_INT);
        $quiz_cmid     = optional_param('quiz_cmid', 0, PARAM_INT);

        // Success banner
        if ($quiz_injected == 1) {
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left:5px solid #28a745; background:#f4fff4; margin-top:20px;',
            ]);
            echo html_writer::tag('strong', '🎯 ¡Cuestionario publicado en Moodle!', [
                'style' => 'color:#28a745; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p', '3 preguntas creadas correctamente.', [
                'style' => 'font-size:12px; margin-bottom:10px;',
            ]);
            if ($quiz_cmid) {
                echo html_writer::link(
                    new moodle_url('/mod/quiz/view.php', ['id' => $quiz_cmid]),
                    'Ir al cuestionario ↗',
                    ['class' => 'areteia-btn areteia-btn-primary external', 'target' => '_blank']
                );
            }
            echo html_writer::end_tag('div');
        }

        // Error banner
        if ($quiz_error == 1) {
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left:5px solid #dc3545; background:#fff4f4; margin-top:20px;',
            ]);
            echo html_writer::tag('strong', '❌ Error al crear el cuestionario', [
                'style' => 'color:#dc3545; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p',
                'Revisá los logs de Moodle para más detalles.',
                ['style' => 'font-size:12px; margin:0;']
            );
            echo html_writer::end_tag('div');
        }
    }
}
