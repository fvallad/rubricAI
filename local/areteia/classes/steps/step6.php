<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\step_renderer;
use local_areteia\rag_client;
use local_areteia\encaje_table;

/**
 * Step 6 — Multi-purpose step.
 *
 * Under action=eval: JSON viewer for final selection (legacy).
 * Under action=crit: Correction instrument selection + AI generation.
 */
class step6 {

    public static function render(array $ctx): void {
        $action = $ctx['action'] ?? 'eval';

        if ($action === 'crit') {
            self::render_crit($ctx);
        } else {
            self::render_eval($ctx);
        }
    }

    // ==================================================================
    // ACTION = crit — Correction Instrument Selection + Generation
    // ==================================================================

    private static function render_crit(array $ctx): void {
        global $PAGE, $OUTPUT;

        $id = $ctx['id'];
        $instrument = session_manager::get('instrument', '');
        $correction = session_manager::get('correction_instrument', '');
        $correction_content = session_manager::get('correction_content', '');
        $do_gen = optional_param('do_gen', 0, PARAM_INT);
        $change_corr = optional_param('change_corr', 0, PARAM_INT);
        $link_params = ['id' => $id, 'action' => 'crit'];

        // Guard: require an evaluation instrument to exist
        if (empty($instrument)) {
            echo html_writer::tag('span', 'Paso 1 — Instrumento de Corrección', ['class' => 'areteia-tag t-ia']);
            echo html_writer::tag('p', 'Selecciona el instrumento de corrección', ['class' => 'areteia-stitle']);
            echo html_writer::start_tag('div', ['class' => 'alert alert-warning', 'style' => 'margin-top:20px;']);
            echo html_writer::tag('strong', '⚠️ No se encontró un instrumento de evaluación.');
            echo html_writer::tag('p', 'Primero debes completar el flujo "📝 Crear evaluación" para definir tu instrumento y sus ítems.', ['style' => 'margin-top:8px;']);
            $eval_url = new moodle_url($PAGE->url, ['action' => 'eval', 'step' => 3]);
            echo html_writer::link($eval_url, 'Ir a Crear evaluación →', ['class' => 'areteia-btn areteia-btn-primary', 'style' => 'margin-top:10px;']);
            echo html_writer::end_tag('div');
            step_renderer::render_nav(6);
            return;
        }

        // Allow user to re-select correction instrument
        if ($change_corr) {
            session_manager::unset_key('correction_instrument');
            session_manager::unset_key('correction_content');
            $correction = '';
            $correction_content = '';
        }

        echo html_writer::tag('span', 'Paso 1 — Instrumento de Corrección', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Selecciona y genera el instrumento de corrección', ['class' => 'areteia-stitle']);

        // Summary card of the evaluation instrument
        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'background:#f0f4ff; border:1px solid #d0d8f0; padding:15px; margin-bottom:20px;'
        ]);
        echo html_writer::tag('strong', '📝 Instrumento de evaluación seleccionado:', ['style' => 'display:block; font-size:12px; color:#555; margin-bottom:5px;']);
        echo html_writer::tag('div', $instrument, ['style' => 'font-size:16px; font-weight:700; color:#185fa5;']);

        // Show objectives if available
        $d2 = session_manager::get('d2', '');
        if (!empty($d2)) {
            echo html_writer::tag('div', '🎯 ' . mb_strimwidth($d2, 0, 150, '...'), ['style' => 'font-size:12px; color:#777; margin-top:8px;']);
        }
        echo html_writer::end_tag('div');

        step_renderer::render_rag_info();

        // ---------------------------------------------------------------
        // Phase 1: Selection (if no correction instrument chosen yet)
        // ---------------------------------------------------------------
        if (empty($correction)) {
            $options = encaje_table::get_correction_options($instrument);
            $all_types = array_keys(encaje_table::LABELS);

            echo html_writer::tag('p',
                'Según el tipo de evaluación elegido, estos son los instrumentos de corrección pedagógicamente adecuados:',
                ['class' => 'areteia-sdesc']
            );

            echo html_writer::start_tag('div', ['style' => 'display:flex; flex-direction:column; gap:12px; margin-bottom:20px;']);

            $valid_keys = array_column($options, 'key');

            foreach ($all_types as $type_key) {
                $is_valid = in_array($type_key, $valid_keys);
                $label = encaje_table::LABELS[$type_key];
                $icon = encaje_table::ICONS[$type_key];
                $desc = encaje_table::DESCRIPTIONS[$type_key];

                $card_style = $is_valid
                    ? 'background:#fff; border:2px solid #e0e0e0; padding:18px; border-radius:12px; cursor:pointer; transition:all 0.2s;'
                    : 'background:#f5f5f5; border:2px solid #eee; padding:18px; border-radius:12px; opacity:0.45; cursor:not-allowed;';

                if ($is_valid) {
                    $select_url = new moodle_url($PAGE->url, array_merge($link_params, [
                        'step' => 6,
                        'correction_instrument' => $type_key,
                        'do_gen' => 1
                    ]));
                    echo html_writer::start_tag('a', [
                        'href' => $select_url->out(false),
                        'class' => 'areteia-btn areteia-btn-primary sug-card',
                        'data-ia' => '1',
                        'style' => $card_style . ' text-decoration:none; color:inherit; display:block;',
                    ]);
                } else {
                    echo html_writer::start_tag('div', [
                        'style' => $card_style,
                        'title' => 'No disponible para este tipo de evaluación',
                    ]);
                }

                echo html_writer::start_tag('div', ['style' => 'display:flex; align-items:flex-start; gap:12px;']);
                echo html_writer::tag('span', $icon, ['style' => 'font-size:28px; line-height:1;']);
                echo html_writer::start_tag('div', ['style' => 'flex:1;']);
                echo html_writer::tag('div', $label, ['style' => 'font-weight:700; font-size:15px; color:#185fa5; margin-bottom:4px;']);
                echo html_writer::tag('div', $desc, ['style' => 'font-size:12px; color:#666; line-height:1.5;']);
                if (!$is_valid) {
                    echo html_writer::tag('div', '🚫 No aplicable para ' . $instrument, [
                        'style' => 'font-size:11px; color:#999; margin-top:6px; font-style:italic;'
                    ]);
                }
                echo html_writer::end_tag('div');
                echo html_writer::end_tag('div');

                echo html_writer::end_tag($is_valid ? 'a' : 'div');
            }

            echo html_writer::end_tag('div');

            step_renderer::render_nav(6);
            return;
        }

        // ---------------------------------------------------------------
        // Phase 2: Generation + Refinement
        // ---------------------------------------------------------------

        // Generate if requested
        if ($do_gen || empty($correction_content)) {
            $feedback = optional_param('feedback', '', PARAM_TEXT);
            $inst_content = session_manager::get('inst_content', '');
            $final_json = session_manager::get('final_selection_json', '');

            $res_data = rag_client::generate([
                'course_id'            => $id,
                'course_title'         => $PAGE->course->fullname,
                'step'                 => 9,
                'objective'            => $d2,
                'objective_json'       => session_manager::get('d2_json', ''),
                'd1_content'           => session_manager::get('d1', ''),
                'd3_function'          => session_manager::get('d3', ''),
                'd4_modality'          => session_manager::get('d4', ''),
                'chosen_instrument'    => $instrument,
                'correction_type'      => $correction,
                'correction_label'     => encaje_table::LABELS[$correction] ?? $correction,
                'instrument_content'   => $inst_content,
                'quiz_items_json'      => $final_json,
                'feedback'             => $feedback,
            ]);

            if ($res_data && $res_data->status == 'success') {
                $correction_content = json_encode($res_data->output);
                session_manager::set('correction_content', $correction_content);

                if (!empty($res_data->usage)) {
                    session_manager::set('s9_usage', (array)$res_data->usage);
                }

                // PRG: redirect to avoid re-generation on reload
                $clean_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 6]));
                redirect($clean_url);
            } else {
                $err = $res_data->message ?? 'Error al generar el instrumento de corrección.';
                echo $OUTPUT->notification('Error de IA: ' . $err, 'error');
            }
        }

        // Display the generated correction instrument
        $corr_label = encaje_table::LABELS[$correction] ?? $correction;
        $corr_icon = encaje_table::ICONS[$correction] ?? '📄';

        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'background:#f8fff8; border:1px solid #c8e6c9; padding:15px; margin-bottom:20px;'
        ]);
        echo html_writer::tag('div', "$corr_icon $corr_label", [
            'style' => 'font-size:16px; font-weight:700; color:#2e7d32; margin-bottom:10px;'
        ]);

        if (!empty($correction_content)) {
            $data = json_decode($correction_content, true);
            if (is_array($data)) {
                self::render_correction_instrument($correction, $data);
            } else {
                echo html_writer::tag('div', 'Error decodificando la respuesta de la IA.', ['class' => 'alert alert-danger']);
            }
        } else {
            echo html_writer::tag('div', 'No se pudo generar el instrumento. Intenta nuevamente.', ['class' => 'alert alert-warning']);
        }

        echo html_writer::end_tag('div');

        // Feedback area for refinement
        echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'background:#fffcf5; border:1px solid #faeeda; padding:15px; margin-bottom:20px;']);
        echo html_writer::tag('strong', '✨ ¿Deseas ajustar este instrumento? Pide un cambio a AreteIA:', ['style' => 'display:block; margin-bottom:10px; font-size:12px; color:#854f0b;']);
        echo html_writer::tag('textarea', '', [
            'name' => 'feedback',
            'class' => 'form-control w-100 mb-2',
            'placeholder' => 'Ej: Agrega más criterios, simplifica los descriptores, enfócate en la práctica...',
            'rows' => 2
        ]);
        echo html_writer::start_tag('div', ['style' => 'display:flex; gap:10px; align-items:center;']);
        echo step_renderer::render_preview_button(9);
        $adjust_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 6, 'do_gen' => 1]));
        echo html_writer::link($adjust_url, 'Refinar Instrumento ✨', [
            'class' => 'areteia-btn areteia-btn-primary',
            'style' => 'font-size:12px; background:#854f0b; border-color:#854f0b;',
            'data-adjust' => '1'
        ]);
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        // Change correction instrument button
        $change_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 6, 'change_corr' => 1]));
        echo html_writer::link($change_url, '🔄 Cambiar instrumento de corrección', [
            'class' => 'areteia-btn',
            'style' => 'font-size:12px; margin-bottom:20px; display:inline-block;'
        ]);

        // Navigation
        $next_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 7]));
        step_renderer::render_nav(6, null, $next_url, 'Ver resultado final →');
    }

    // ==================================================================
    // Correction instrument renderers (structured visualization)
    // ==================================================================

    /**
     * Public wrapper for rendering correction instruments (used by step7).
     */
    public static function render_correction_public(string $type, array $data): void {
        self::render_correction_instrument($type, $data);
    }

    private static function render_correction_instrument(string $type, array $data): void {
        switch ($type) {
            case 'clave_correccion':
                self::render_answer_key($data);
                break;
            case 'lista_cotejo':
                self::render_checklist($data);
                break;
            case 'escala_valoracion':
                self::render_rating_scale($data);
                break;
            case 'rubrica':
                self::render_rubric($data);
                break;
            default:
                // Generic fallback
                echo html_writer::tag('pre', s(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)), [
                    'style' => 'background:#f9f9f9; padding:15px; border-radius:8px; font-size:12px; overflow-x:auto;'
                ]);
        }
    }

    /** 🔑 Clave de corrección: question → correct answer */
    private static function render_answer_key(array $data): void {
        $items = $data['items'] ?? $data['answers'] ?? $data;
        if (!is_array($items) || empty($items)) {
            echo html_writer::tag('p', 'Sin datos para mostrar.', ['style' => 'color:#999;']);
            return;
        }

        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:100%; font-size:13px;']);
        echo '<thead><tr><th style="width:60%;">Pregunta / Ítem</th><th>Respuesta correcta</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $item = (array)$item;
            $q = s($item['question'] ?? $item['pregunta'] ?? $item['text'] ?? '');
            $a = s($item['answer'] ?? $item['respuesta'] ?? $item['correct'] ?? '');
            echo "<tr><td>{$q}</td><td style=\"color:#2e7d32; font-weight:600;\">{$a}</td></tr>";
        }
        echo '</tbody></table>';
    }

    /** ✅ Lista de cotejo: criterion → Sí/No */
    private static function render_checklist(array $data): void {
        $items = $data['criteria'] ?? $data['criterios'] ?? $data['items'] ?? $data;
        if (!is_array($items) || empty($items)) {
            echo html_writer::tag('p', 'Sin datos para mostrar.', ['style' => 'color:#999;']);
            return;
        }

        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:100%; font-size:13px;']);
        echo '<thead><tr><th style="width:70%;">Criterio</th><th style="text-align:center;">Logrado</th><th style="text-align:center;">No logrado</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $item = (array)$item;
            $c = s($item['criterion'] ?? $item['criterio'] ?? $item['text'] ?? '');
            echo "<tr><td>{$c}</td><td style=\"text-align:center;\">☐</td><td style=\"text-align:center;\">☐</td></tr>";
        }
        echo '</tbody></table>';
    }

    /** 📊 Escala de valoración: criterion × levels */
    private static function render_rating_scale(array $data): void {
        $items = $data['criteria'] ?? $data['criterios'] ?? $data['items'] ?? $data;
        $levels = $data['levels'] ?? $data['niveles'] ?? ['Insuficiente', 'Suficiente', 'Bueno', 'Destacado'];

        if (!is_array($items) || empty($items)) {
            echo html_writer::tag('p', 'Sin datos para mostrar.', ['style' => 'color:#999;']);
            return;
        }

        $level_count = count($levels);
        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:100%; font-size:13px;']);
        echo '<thead><tr><th style="width:40%;">Criterio</th>';
        foreach ($levels as $lv) {
            $lv = is_array($lv) ? ($lv['label'] ?? $lv['name'] ?? '') : $lv;
            echo '<th style="text-align:center; font-size:11px;">' . s($lv) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $item) {
            $item = (array)$item;
            $c = s($item['criterion'] ?? $item['criterio'] ?? $item['text'] ?? '');
            echo "<tr><td>{$c}</td>";
            for ($i = 0; $i < $level_count; $i++) {
                echo '<td style="text-align:center;">○</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /** 📋 Rúbrica: criterion × levels with descriptors */
    private static function render_rubric(array $data): void {
        $items = $data['criteria'] ?? $data['criterios'] ?? $data['items'] ?? $data;
        $levels = $data['levels'] ?? $data['niveles'] ?? [];

        if (!is_array($items) || empty($items)) {
            echo html_writer::tag('p', 'Sin datos para mostrar.', ['style' => 'color:#999;']);
            return;
        }

        // Determine level headers from data
        $level_headers = [];
        if (!empty($levels)) {
            foreach ($levels as $lv) {
                $level_headers[] = is_array($lv) ? ($lv['label'] ?? $lv['name'] ?? '') : $lv;
            }
        } else {
            // Infer from first item's descriptors
            $first = (array)reset($items);
            $descs = $first['descriptors'] ?? $first['descriptores'] ?? $first['levels'] ?? [];
            foreach ($descs as $d) {
                $d = (array)$d;
                $level_headers[] = $d['level'] ?? $d['nivel'] ?? '';
            }
        }

        $level_count = max(count($level_headers), 1);

        // Color gradient for level columns (green→red)
        $colors = ['#ffebee', '#fff3e0', '#e8f5e9', '#c8e6c9'];
        if ($level_count > 4) {
            $colors = array_pad($colors, $level_count, '#e8f5e9');
        }

        echo html_writer::start_tag('div', ['style' => 'overflow-x:auto;']);
        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:100%; font-size:12px; border-collapse:collapse;']);

        // Header
        echo '<thead><tr><th style="width:20%; vertical-align:bottom; padding:10px;">Criterio</th>';
        foreach ($level_headers as $idx => $lh) {
            $bg = $colors[$idx] ?? '#f5f5f5';
            echo '<th style="text-align:center; padding:10px; background:' . $bg . '; font-size:11px;">' . s($lh) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            $item = (array)$item;
            $c = s($item['criterion'] ?? $item['criterio'] ?? $item['text'] ?? '');
            $weight = isset($item['weight']) ? ' (' . $item['weight'] . '%)' : '';

            echo '<tr><td style="font-weight:600; padding:10px; vertical-align:top; border-right:2px solid #ddd;">' . $c . $weight . '</td>';

            $descs = $item['descriptors'] ?? $item['descriptores'] ?? $item['levels'] ?? [];
            foreach ($descs as $idx => $d) {
                $d = (array)$d;
                $text = s($d['description'] ?? $d['descriptor'] ?? $d['text'] ?? '');
                $bg = $colors[$idx] ?? '#f5f5f5';
                echo '<td style="padding:10px; font-size:11px; line-height:1.5; background:' . $bg . '; vertical-align:top;">' . $text . '</td>';
            }

            // Fill empty cells if descriptor count < level count
            $missing = $level_count - count($descs);
            for ($i = 0; $i < $missing; $i++) {
                echo '<td style="padding:10px;">—</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo html_writer::end_tag('div');
    }

    // ==================================================================
    // ACTION = eval — JSON Viewer (legacy, unchanged)
    // ==================================================================

    private static function render_eval(array $ctx): void {
        global $PAGE;

        $id = $ctx['id'];
        
        // Retrieve selection from POST or session cache
        $selection_raw = optional_param('selection_json', '', PARAM_RAW);
        
        if (!empty($selection_raw)) {
            session_manager::set('final_selection_json', $selection_raw);
        } else {
            $selection_raw = session_manager::get('final_selection_json', '');
        }

        echo html_writer::tag('span', 'Paso 6 — Selección Final (JSON)', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Estructura técnica del instrumento generado', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p', 
            'Esta es la representación estructurada de los ítems que has seleccionado. Este JSON será utilizado para construir la rúbrica y exportar a Moodle.',
            ['class' => 'areteia-sdesc']
        );

        if (empty($selection_raw)) {
            echo html_writer::tag('div', 'No hay ítems seleccionados. Por favor, vuelve al paso anterior.', ['class' => 'alert alert-warning']);
        } else {
            $data = json_decode($selection_raw, true);
            
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'background:#2d2d2d; border:none; padding:0; border-radius:12px; overflow:hidden; margin-bottom:20px;']);
            
            // JSON Header
            echo html_writer::start_tag('div', ['style' => 'background:#1e1e1e; padding:10px 20px; display:flex; justify-content:space-between; align-items:center;']);
            echo html_writer::tag('span', 'instrument_selection.json', ['style' => 'color:#aaa; font-family:monospace; font-size:12px;']);
            echo html_writer::tag('button', '📋 Copiar JSON', [
                'class' => 'btn-copy-json',
                'onclick' => 'copyJsonToClipboard()',
                'style' => 'background:transparent; border:1px solid #444; color:#ccc; font-size:11px; padding:4px 10px; border-radius:4px; cursor:pointer;'
            ]);
            echo html_writer::end_tag('div');
            
            // JSON Content
            echo html_writer::start_tag('pre', [
                'id' => 'final-json-preview',
                'style' => 'margin:0; padding:20px; color:#9cdcfe; background:#2d2d2d; font-family: "Fira Code", "Courier New", monospace; font-size:13px; max-height:500px; overflow-y:auto; line-height:1.5;'
            ]);
            echo s(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo html_writer::end_tag('pre');
            
            echo html_writer::end_tag('div');
            
            // Client-side script for copying
            echo '
            <script>
            function copyJsonToClipboard() {
                const text = document.getElementById("final-json-preview").innerText;
                navigator.clipboard.writeText(text).then(() => {
                    const btn = document.querySelector(".btn-copy-json");
                    const oldText = btn.innerText;
                    btn.innerText = "✅ ¡Copiado!";
                    setTimeout(() => btn.innerText = oldText, 2000);
                });
            }
            </script>
            ';
        }

        // Navigation
        $link_params = ['id' => $id];
        $prev_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 5]));
        $next_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 7]));

        step_renderer::render_nav(6, $prev_url, $next_url, 'Continuar a Rúbrica →');
    }
}
