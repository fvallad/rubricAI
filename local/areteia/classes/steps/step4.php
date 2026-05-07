<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\lock_manager;
use local_areteia\rag_client;
use local_areteia\step_renderer;

/**
 * Step 4 — Sugerencias de la IA.
 * AI proposes pedagogically justified instruments based on the objective.
 */
class step4 {

    public static function render(array $ctx): void {
        global $PAGE, $OUTPUT;

        $id         = $ctx['id'];
        $summary    = $ctx['summary'];
        $sel_sug    = optional_param('sel_sug', '', PARAM_TEXT);
        $do_gen     = optional_param('do_gen', 0, PARAM_INT);
        $confirmed  = optional_param('confirmed', 0, PARAM_INT);
        $instrument = session_manager::get('instrument', '');
        $d2         = session_manager::get('d2', '');
        $d1         = session_manager::get('d1', '');
        $d3         = session_manager::get('d3', '');
        $d4         = session_manager::get('d4', '');
        $s_sugs_raw = session_manager::get('s_sugs', '');
        $feedback   = session_manager::get('feedback', '');

        // Minimal params for links in this step
        $link_params = ['id' => $id];

        echo html_writer::tag('p', 'Instrumentos sugeridos por AreteIA', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p',
            'AreteIA te sugerirá un conjunto de instrumentos de evaluación justificados pedagógicamente y basados en tu(s) objetivo(s).',
            ['class' => 'areteia-sdesc']
        );
        
        step_renderer::render_rag_info();

        // Lock banner: protect downstream generated content
        $is_locked = lock_manager::is_locked(4);
        if ($is_locked) {
            lock_manager::render_lock_banner(
                '🔒 Opción bloqueada',
                'La edición está protegida porque ya avanzaste.',
                new moodle_url($PAGE->url, ['step' => 0, 'unlock' => 2]),
                '🔓 Desbloquear (Se borrará el progreso)'
            );
        }

        // Parse cached suggestions from session
        $sugs = [];
        if ($s_sugs_raw) {
            $sugs = @json_decode($s_sugs_raw, true) ?: [];
        }
        $usage = session_manager::get('s4_usage', null);

        // Generate suggestions via AI if requested
        if ($do_gen) {
            $feedback = optional_param('feedback', $feedback, PARAM_TEXT);
            $sugs = self::generate_suggestions($id, $d2, $d1, $d3, $d4, $summary, $feedback);
        }

        // Show generate button if no suggestions yet
        if (empty($sugs)) {
            echo html_writer::start_tag('div', [
                'style' => 'text-align:center; padding:40px; border:2px dashed #eee; border-radius:15px;',
            ]);
            echo html_writer::tag('p',
                'Hacé clic para que AreteIA analice tu(s) objetivo(s) y te sugiera los instrumentos de evaluación',
                ['style' => 'color:#777; margin-bottom:20px;']
            );
            $gen_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 4, 'do_gen' => 1]));
            echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:center; align-items:center; gap:10px;']);
            echo step_renderer::render_preview_button(4);
            echo html_writer::link($gen_url, '✨ Generar sugerencias con AreteIA', [
                'class'   => 'areteia-btn areteia-btn-primary',
                'style'   => 'padding:12px 25px;',
                'data-ia' => '1',
            ]);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        } else {
            $sug_names = array_map(fn($s) => $s['name'] ?? '', $sugs);
            $is_ai_sel = in_array($sel_sug, $sug_names);
            $effective_sel = $sel_sug ?: $instrument;

            // 1) Resultados (Sugerencias)
            echo html_writer::start_tag('div', [
                'class' => 'sug-buttons',
                'style' => 'display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:30px;',
            ]);
            foreach ($sugs as $s) {
                self::render_suggestion_button($s, $is_ai_sel ? $sel_sug : '', $instrument, $link_params, $is_locked);
            }
            echo html_writer::end_tag('div');

            // 2) Ajustar sugerencias (Feedback area) - Only available if something is selected
            if (!empty($effective_sel)) {
                echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'background:#fffcf5; border:1px solid #faeeda; padding:15px; margin-bottom:20px;']);
                echo html_writer::tag('strong', '✨ ¿Deseas ajustar estas opciones? Pide un cambio a AreteIA:', ['style' => 'display:block; margin-bottom:10px; font-size:12px; color:#854f0b;']);
                echo html_writer::tag('textarea', $feedback, [
                    'name' => 'feedback',
                    'class' => 'form-control w-100 mb-2',
                    'placeholder' => 'Ej: Haz que las opciones sean más prácticas o enfocadas a proyectos...',
                    'rows' => 2
                ]);
                echo html_writer::start_tag('div', ['style' => 'display:flex; gap:10px; align-items:center;']);
                echo step_renderer::render_preview_button(4);
                $adjust_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 4, 'do_gen' => 1, 'sel_sug' => $effective_sel]));
                echo html_writer::link($adjust_url, 'Refinar Sugerencias ✨', [
                    'class' => 'areteia-btn areteia-btn-primary',
                    'style' => 'font-size:12px; background:#854f0b; border-color:#854f0b;',
                    'data-adjust' => '1'
                ]);
                echo html_writer::end_tag('div');
                echo html_writer::end_tag('div');
            }

            // 3) Catálogo / Fallback (Seleccionar de la lista completa)
            $all_instr_data = rag_client::get_instruments();
            $instruments_list = ($all_instr_data && $all_instr_data->status == 'success') ? $all_instr_data->instruments : [];

            if (!empty($instruments_list)) {
                echo html_writer::start_tag('div', [
                    'class' => 'areteia-card',
                    'style' => 'background:#f8f9fa; border:1px dashed #d3d1c7; padding:20px; margin-bottom:20px;'
                ]);
                echo html_writer::tag('strong', '📂 Catálogo: Seleccionar de la lista completa', [
                    'style' => 'display:block; margin-bottom:10px; font-size:13px; color:#666;'
                ]);
                
                echo html_writer::start_tag('div', ['style' => 'display:flex; gap:10px; align-items:center;']);
                echo html_writer::start_tag('select', [
                    'id' => 'instrument-fallback-select',
                    'class' => 'form-control',
                    'style' => 'flex-grow:1;',
                    'data-baseurl' => (new moodle_url($PAGE->url, $link_params))->out(false) . "&step=4"
                ]);
                echo html_writer::tag('option', 'Selecciona otro instrumento del catálogo...', ['value' => '']);
                foreach ($instruments_list as $inst) {
                    // Manual selection state: only select if it's NOT an AI suggestion
                    $attrs = ['value' => $inst->name];
                    if (!$is_ai_sel && $sel_sug === $inst->name) {
                        $attrs['selected'] = 'selected';
                    }
                    echo html_writer::tag('option', $inst->name, $attrs);
                }
                echo html_writer::end_tag('select');
                echo html_writer::end_tag('div');

                // Render definition for manual selection
                if (!$is_ai_sel && !empty($sel_sug)) {
                    $selected_def = '';
                    foreach ($instruments_list as $inst) {
                        if ($inst->name === $sel_sug) {
                            $selected_def = $inst->definition;
                            break;
                        }
                    }
                    if ($selected_def) {
                        echo html_writer::start_tag('div', [
                            'class' => 'manual-selection-info',
                            'style' => 'margin-top:15px; background:#fff; border:1px solid #e0e0e0; padding:15px; border-radius:8px; border-left:4px solid #6c63ff;'
                        ]);
                        echo html_writer::tag('span', 'Definición oficial:', ['style' => 'display:block; font-size:10px; text-transform:uppercase; color:#999; font-weight:bold; margin-bottom:5px;']);
                        echo html_writer::tag('div', $selected_def, ['style' => 'font-size:13px; color:#333; line-height:1.5;']);
                        echo html_writer::end_tag('div');
                    }
                }

                echo html_writer::end_tag('div');
            }
        }

        // Log token usage to console
        if (!empty($usage)) {
            $usage_json = json_encode($usage);
            echo "<script>console.log('AI Token Usage (Step 4):', {$usage_json});</script>";
        }

        // Navigation
        $prev_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 3]));

        // Render generation config BEFORE the navigation block
        if ($effective_sel && ($confirmed || $effective_sel === $instrument)) {
            echo html_writer::start_tag('div', [
                'id' => 'gen-config-area',
                'style' => 'background:#f0f7ff; border:1px solid #c2e0ff; padding:20px; border-radius:12px; margin-top:20px; margin-bottom:20px; width:100%; text-align:center;'
            ]);
            
            echo html_writer::tag('p', "Has seleccionado: <strong>$effective_sel</strong>", ['style' => 'margin-bottom:15px; color:#185fa5;']);
            
            echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:center; align-items:center; gap:15px; flex-wrap:wrap;']);
            echo html_writer::tag('label', '¿Cuántos ítems quieres generar?', ['for' => 'num_items_input', 'style' => 'margin-bottom:0; font-weight:bold;']);
            echo html_writer::tag('input', '', [
                'type'  => 'number',
                'id'    => 'num_items_input',
                'class' => 'form-control',
                'style' => 'width:80px; text-align:center;',
                'value' => '5',
                'min'   => '1',
                'max'   => '20'
            ]);
            
            echo step_renderer::render_preview_button(5);
            
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        }

        // Navigation Block
        echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
        echo html_writer::link($prev_url, '← Anterior', ['class' => 'areteia-btn']);
        
        if ($effective_sel) {
            if ($confirmed || $effective_sel === $instrument) {
                // In Gen-Config state, right button is Generate Items
                $gen_items_url = new moodle_url($PAGE->url, array_merge($link_params, [
                    'step'       => 5,
                    'do_gen'     => 1,
                    'instrument' => $effective_sel
                ]));
                
                echo html_writer::link($gen_items_url, '✨ Generar Ítems', [
                    'id'    => 'btn-generate-items',
                    'class' => 'areteia-btn areteia-btn-primary',
                    'style' => 'background:#6c63ff; border-color:#6c63ff;'
                ]);
            } else {
                // Navigation state: offer "Confirmar"
                $btn_attrs = ['class' => 'areteia-btn areteia-btn-primary'];
                $btn_label = 'Confirmar Instrumento →';

                // Warn before destroying downstream content
                if (!empty(session_manager::get('inst_content'))
                    && $instrument !== ''
                    && $effective_sel !== $instrument
                ) {
                    $btn_attrs['data-confirm'] = '¡Atención! Confirmar este instrumento eliminará permanentemente la evaluación y rúbrica que tenías generada. ¿Estás seguro?';
                }

                $confirm_url = new moodle_url($PAGE->url, array_merge($link_params, [
                    'step'       => 4,
                    'confirmed'  => 1,
                    'instrument' => $effective_sel,
                ]));
                echo html_writer::link($confirm_url, $btn_label, $btn_attrs);
            }
        } else {
            echo html_writer::tag('span', 'Seleccioná un instrumento para continuar', [
                'class' => 'areteia-btn disabled',
                'style' => 'opacity:0.5; cursor:not-allowed;',
            ]);
        }
        echo html_writer::end_tag('div');

        echo html_writer::end_tag('div');
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Call the AI to generate instrument suggestions, cache in session.
     */
    private static function generate_suggestions(
        int $id,
        string $d2,
        string $d1,
        string $d3,
        string $d4,
        array $summary,
        string $feedback = ""
    ): array {
        global $OUTPUT;

        $res_data = rag_client::generate([
            'course_id'      => $id,
            'step'           => 4,
            'objective'      => $d2,
            'objective_json' => session_manager::get('d2_json', ''),
            'summary'        => $summary['summary'],
            'dimensions'     => "Contenido: $d1, Función: $d3, Modalidad: $d4",
            'd1_content'     => $d1,
            'd3_function'    => $d3,
            'd4_modality'    => $d4,
            'feedback'       => $feedback,
            'current_selection' => optional_param('sel_sug', '', PARAM_TEXT)
        ]);

        if ($res_data && $res_data->status == 'success') {
            $sugs = $res_data->output->suggestions ?? [];
            // Ensure each suggestion is an array
            $sugs = array_map(function($item) {
                return (array)$item;
            }, (array)$sugs);

            if (!empty($sugs)) {
                session_manager::set('s_sugs', json_encode($sugs));
                if (!empty($res_data->usage)) {
                    session_manager::set('s4_usage', (array)$res_data->usage);
                }
            }
            return $sugs;
        }

        $err = $res_data->message ?? 'Error en el servicio de AreteIA.';
        if (!empty($res_data->reason)) $err .= " Motivo: " . $res_data->reason;
        echo $OUTPUT->notification('Error de IA: ' . $err, 'error');
        return [];
    }

    /**
     * Render a suggestion selection button.
     */
    private static function render_suggestion_button(
        array $s,
        string $sel_sug,
        string $instrument,
        array $link_params,
        bool $is_locked = false
    ): void {
        global $PAGE;

        $name       = $s['name'] ?? 'Sin nombre';
        $is_current = ($instrument === $name);

        // Determine selection state
        $is_sel = '';
        if ($sel_sug === $name) {
            $is_sel = 'areteia-btn-primary';
        } elseif (empty($sel_sug) && $is_current) {
            $is_sel = 'areteia-btn-primary';
        }

        $sug_url    = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 4, 'sel_sug' => $name]));
        $btn_attrs = [
            'class' => "areteia-btn $is_sel",
            'style' => 'height:100%; display:flex; flex-direction:column; text-align:left; padding:15px; position:relative;',
        ];

        if ($is_locked) {
            $btn_attrs['style'] .= ' opacity:0.6; cursor:not-allowed;';
            echo html_writer::start_tag('div', $btn_attrs);
        } else {
            // Check if selection causes data loss
            $btn_attrs['href'] = $sug_url->out(false);
            if (!empty(session_manager::get('inst_content')) && $instrument !== '' && $name !== $instrument) {
                $btn_attrs['data-confirm'] = 'Si cambias de instrumento se borrará el contenido actual. ¿Continuar?';
            }
            echo html_writer::start_tag('a', $btn_attrs);
        }

        if ($is_current) {
            echo html_writer::tag('span', 'En uso', [
                'style' => 'position:absolute; top:-10px; right:10px; font-size:9px; background:#e6f4ea; color:#1e8e3e; padding:1px 6px; border-radius:10px; border:1px solid #1e8e3e;',
            ]);
        }

        echo html_writer::tag('strong', $name, ['style' => 'margin-bottom:8px; display:block;']);
        
        // Official definition from master catalog (Deterministic)
        if (!empty($s['definition'])) {
            echo html_writer::tag('div', $s['definition'], [
                'style' => 'font-size:12px; color:#185fa5; background:#f0f7ff; padding:8px; border-radius:6px; margin-bottom:10px; line-height:1.4;'
            ]);
        }

        echo html_writer::tag('div', '<strong>Fundamentación:</strong> ' . ($s['why'] ?? ''), [
            'style' => 'font-size:11px; font-weight:normal; opacity:0.8; line-height:1.4;'
        ]);

        echo html_writer::end_tag($is_locked ? 'div' : 'a');
    }
}
