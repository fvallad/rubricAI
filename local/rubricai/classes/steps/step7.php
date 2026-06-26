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

namespace local_rubricai\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_rubricai\session_manager;
use local_rubricai\data_provider;
use local_rubricai\step_renderer;
use local_rubricai\encaje_table;

/**
 * Step 7 renderer — Comparative analysis engine.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

        echo html_writer::tag('p', 'Instrumento de corrección finalizado', ['class' => 'rubricai-stitle']);

        // Guard
        if (empty($correction_content)) {
            echo html_writer::tag('div', 'No hay instrumento de corrección generado. Vuelve al paso anterior.', ['class' => 'alert alert-warning']);
            $prev_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 6]));
            step_renderer::render_nav(7, $prev_url);
            return;
        }

        // Summary header
        echo html_writer::start_tag('div', [
            'class' => 'rubricai-card',
            'style' => 'background:#f0f4ff; border:1px solid #d0d8f0; padding:15px; margin-bottom:20px;'
        ]);
        echo html_writer::tag('div', "📝 Evaluación: <strong>$instrument</strong>", ['style' => 'font-size:13px; color:#555; margin-bottom:5px;']);
        echo html_writer::tag('div', "$corr_icon Corrección: <strong>$corr_label</strong>", ['style' => 'font-size:13px; color:#2e7d32;']);
        echo html_writer::end_tag('div');

        // Render the correction instrument
        echo html_writer::start_tag('div', [
            'class' => 'rubricai-card',
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
            'class' => 'rubricai-card',
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
    // ACTION = eval — Instrument preview
    // ==================================================================

    private static function render_eval(array $ctx): void {
        global $PAGE;

        $context        = $ctx['context'];
        $instrument     = session_manager::get('instrument', '');
        $inst_content   = session_manager::get('inst_content', '');
        $rubric_content = session_manager::get('rubric_content', '');

        $activity_type  = encaje_table::get_activity_type($instrument);
        $activity_label = encaje_table::ACTIVITY_TYPE_LABELS[$activity_type] ?? 'Tarea';
        $activity_icon  = encaje_table::ACTIVITY_TYPE_ICONS[$activity_type] ?? '📋';

        echo html_writer::tag('p', 'Instrumento de evaluación finalizado', ['class' => 'rubricai-stitle']);

        echo html_writer::start_tag('div', ['class' => 'rubricai-card', 'style' => 'margin-bottom:20px;']);

        echo html_writer::tag('p', "<strong>{$activity_icon} Vista previa: $instrument</strong> <span style='font-size:11px; background:#e8f0fe; color:#185fa5; padding:2px 8px; border-radius:10px;'>→ {$activity_label}</span>", [
            'style' => 'color:#185fa5; font-size:1.1em; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;',
        ]);

        echo html_writer::tag('div', '<strong>Consignas e Ítems:</strong>', [
            'style' => 'font-size:13px; font-weight:bold; margin-bottom:5px;',
        ]);
        $preview_inst = mb_strimwidth($inst_content, 0, 500, '...');
        echo html_writer::tag('div',
            format_text($preview_inst, FORMAT_MARKDOWN, ['context' => $context]),
            [
                'class' => 'rubricai-markdown-content',
                'style' => 'font-size:12px; background:#fcfcfc; padding:10px; border-radius:8px; margin-bottom:15px; border:1px solid #eee;',
            ]
        );

        if (!empty($rubric_content)) {
            echo html_writer::tag('div', '<strong>Rúbrica:</strong>', [
                'style' => 'font-size:13px; font-weight:bold; margin-bottom:5px;',
            ]);
            $preview_rub = mb_strimwidth($rubric_content, 0, 500, '...');
            echo html_writer::tag('div',
                format_text($preview_rub, FORMAT_MARKDOWN, ['context' => $context]),
                [
                    'class' => 'rubricai-markdown-content',
                    'style' => 'font-size:12px; background:#fcfcfc; padding:10px; border-radius:8px; border:1px solid #eee;',
                ]
            );
        }

        echo html_writer::end_tag('div');

        $u4 = session_manager::get('s4_usage', []);
        $u5 = session_manager::get('s5_usage', []);
        $u7 = session_manager::get('s7_usage', []);

        $total_in  = ($u4['input_tokens'] ?? 0) + ($u5['input_tokens'] ?? 0) + ($u7['input_tokens'] ?? 0);
        $total_out = ($u4['output_tokens'] ?? 0) + ($u5['output_tokens'] ?? 0) + ($u7['output_tokens'] ?? 0);

        if ($total_in > 0) {
            $total_usage = [
                'input_tokens'  => $total_in,
                'output_tokens' => $total_out,
                'total_tokens'  => $total_in + $total_out,
            ];
            $usage_json = json_encode($total_usage);
            echo "<script>console.log('AI Token Usage (Total):', {$usage_json});</script>";
        }

        $prev_url = new moodle_url($PAGE->url, ['step' => 6]);
        step_renderer::render_nav(7, $prev_url, null);
    }
}
