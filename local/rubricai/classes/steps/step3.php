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
use local_rubricai\lock_manager;
use local_rubricai\rag_client;
use local_rubricai\step_renderer;

/**
 * Step 3 renderer — Manual rubric creation.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step3 {

    public static function render(array $ctx): void {
        global $PAGE;

        $id         = $ctx['id'];
        $path       = optional_param('path', 'B', PARAM_ALPHA);
        $show_ai    = optional_param('ai', 0, PARAM_INT);
        $use_moodle = session_manager::get('use_moodle', 1);

        $d1 = session_manager::get('d1', '');
        $d2 = session_manager::get('d2', '');
        $d3 = session_manager::get('d3', '');
        $d4 = session_manager::get('d4', '');

        // URL params for links within this step
        $step_params = [
            'id'         => $id,
            'step'       => 3,
            'path'       => $path,
            'use_moodle' => $use_moodle,
            'ai'         => $show_ai,
        ];

        $is_locked = lock_manager::is_locked(3);

        
        echo html_writer::tag('p', 'Clarificación del objetivo de evaluación', ['class' => 'rubricai-stitle']);
        echo html_writer::tag('p', 'Dimensiones clave para definir qué y cómo evaluar.', ['class' => 'rubricai-sdesc']);

        // Lock banner
        if ($is_locked) {
            $unlock_url = new moodle_url($PAGE->url, array_merge($step_params, ['unlock' => 1]));
            lock_manager::render_lock_banner(
                '🔒 Opción bloqueada',
                'La edición está protegida porque ya avanzaste.',
                new moodle_url($PAGE->url, ['step' => 0, 'unlock' => 2]),
                '🔓 Desbloquear (Se borrará el progreso posterior)'
            );
        }

        // D1: Tipo de contenido
        self::render_dimension(
            'd1-container',
            'Dimensión 1 — Tipo de contenido',
            '¿Qué tipo de contenido es el foco principal?',
            [
                'Conceptual' => 'Conceptos, principios y teorías.',
                'Procedimental' => 'Acciones, pasos ordenados, técnicas, estrategias.',
                'Actitudinal' => 'Valores, normas, creencias, actitudes.'
            ],
            'd1', $d1, $step_params, $is_locked
        );

        // D2: Objetivo (Dynamic Form)
        echo html_writer::start_tag('div', ['class' => 'rubricai-dim', 'id' => 'd2-container']);
        echo html_writer::start_tag('div', ['style' => 'display:flex; align-items:center; margin-bottom:12px;']);
        echo html_writer::tag('div', 'Dimensión 2 — Objetivo de evaluación', ['class' => 'dlbl', 'style' => 'margin-bottom:0;']);
        echo self::render_tooltip('TAXONOMÍA DE BLOOM (para redactar los objetivos)', 'Utiliza estos niveles para definir la profundidad del aprendizaje esperado.');
        echo html_writer::end_tag('div');

        echo html_writer::tag('div',
            'Formulá uno o más objetivos: ¿qué querés saber si pueden hacer?',
            ['class' => 'dq', 'style' => 'font-weight:bold; margin-bottom:10px;']
        );
        echo html_writer::tag('p', 'Que mis estudiantes puedan...', ['class' => 'rubricai-sdesc']);

        echo html_writer::start_tag('div', ['id' => 'objectives-list', 'class' => 'mb-3']);
        
        $d2_json = session_manager::get('d2_json', '');
        $objectives = [];
        if (!empty($d2_json)) {
            // Fix: Moodle optional_param might return slashed quotes even with PARAM_RAW
            $decoded = json_decode($d2_json, true);
            if ($decoded === null) {
                $decoded = json_decode(stripslashes($d2_json), true);
            }
            $objectives = $decoded ?: [];
        }
        
        // At least one empty row if none exists
        if (empty($objectives)) {
            $objectives[] = ['bloom' => '', 'text' => $d2]; // Fallback to old d2 if it was a simple string
        }

        $bloom_options = [
            'RECORDAR' => 'Memorizar información, reconocer datos, ideas o principios.',
            'ENTENDER' => 'Comprender el significado de la información, explicar conceptos e interpretar hechos.',
            'APLICAR' => 'Utilizar el conocimiento adquirido en situaciones nuevas o prácticas.',
            'ANALIZAR' => 'Descomponer la información en partes, identificar motivos o causas y organizar ideas.',
            'EVALUAR' => 'Justificar una postura, emitir juicios de valor sobre información y verificar el valor de la evidencia.',
            'CREAR' => 'Combinar elementos para formar un todo coherente o generar nuevos productos o ideas.'
        ];

        foreach ($objectives as $idx => $obj) {
            echo self::render_objective_row($idx, $obj, $bloom_options, $is_locked);
        }
        
        echo html_writer::end_tag('div');

        if (!$is_locked) {
            echo html_writer::tag('button', '＋ Añadir otro objetivo', [
                'type' => 'button',
                'id' => 'add-objective-btn',
                'class' => 'add-objective-btn'
            ]);
        }

        // Hidden input for the final d2 content (the combined string)
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'd2', 'value' => $d2]);
        // Hidden input for the JSON state
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'd2_json', 'value' => $d2_json]);

        echo html_writer::end_tag('div');

        // D3: Función
        self::render_dimension(
            'd3-container',
            'Dimensión 3 — Función de la evaluación',
            '¿Qué función tiene esta evaluación?',
            [
                'Diagnóstica' => 'Actividades que se llevan a cabo antes de iniciar el proceso de enseñanza-aprendizaje a fin de conocer las competencias, intereses y/o motivaciones.',
                'Formativa' => 'Proceso continuo y sistemático que ocurre durante el aprendizaje para monitorear el progreso del alumnado.',
                'Sumativa' => 'Proceso sistemático aplicado al final de un período de enseñanza para verificar los aprendizajes alcanzados.'
            ],
            'd3', $d3, $step_params, $is_locked
        );

        // D4: Modalidad
        self::render_dimension(
            'd4-container',
            'Dimensión 4 — Modalidad',
            '¿Cuál es el modo de resolución?',
            [
                'Individual' => 'Proceso sistemático para medir competencias, rendimiento, habilidades y potencial de una sola persona.',
                'Grupal/Colaborativa' => 'Trabajo conjunto en la resolución de tareas asignadas para optimizar el propio aprendizaje y el de los otros miembros.'
            ],
            'd4', $d4, $step_params, $is_locked,
            'border-bottom:none; padding-bottom:0;'
        );

        // AI Feedback / RAG
        echo html_writer::start_tag('div', ['id' => 'rag-feedback-container']);
        if ($show_ai && !empty($d2)) {
            self::render_rag_feedback($id, $d2);
        } else if ($show_ai) {
            echo html_writer::start_tag('div', [
                'class' => 'rubricai-card t-ia',
                'style' => 'border-left: 5px solid #185fa5; background: #f0f7ff; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '✨ Sugerencia de la IA', [
                'style' => 'display:block; margin-bottom:10px; color:#185fa5;',
            ]);
            echo html_writer::tag('div',
                'Escribe tu objetivo para que la IA lo analice usando los materiales del curso.',
                ['style' => 'font-size:12px; color:#666;']
            );
            echo html_writer::end_tag('div');
        }
        echo html_writer::end_tag('div'); // #rag-feedback-container

        // Navigation
        $prev_url = new moodle_url($PAGE->url, array_merge($step_params, ['step' => 1, 'action' => 'lib']));
        $next_url = new moodle_url($PAGE->url, array_merge($step_params, ['step' => 4]));
        $can_continue = ($d1 && $d2 && $d3 && $d4);

        echo html_writer::start_tag('div', ['class' => 'rubricai-nav']);
        echo html_writer::link($prev_url, '← Anterior', ['class' => 'rubricai-btn']);
        

        $btn_class = 'rubricai-btn rubricai-btn-primary ' . ($can_continue ? '' : 'disabled');
        $btn_text  = $can_continue ? 'Ver instrumentos sugeridos →' : 'Completa todas las dimensiones';
        $btn_style = $can_continue ? '' : 'opacity:0.5; cursor:not-allowed;';
        echo html_writer::link($next_url, $btn_text, [
            'id'    => 'next-step-btn',
            'class' => $btn_class,
            'style' => $btn_style,
        ]);

        echo html_writer::end_tag('div');
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Render a dimension with pill options and tooltips.
     */
    private static function render_dimension(
        string $id,
        string $label,
        ?string $question,
        array $options, // key => description
        string $param_name,
        string $current_value,
        array $step_params,
        bool $is_locked,
        string $extra_style = ''
    ): void {
        global $PAGE;

        $attrs = ['class' => 'rubricai-dim', 'id' => $id];
        if ($extra_style) {
            $attrs['style'] = $extra_style;
        }
        echo html_writer::start_tag('div', $attrs);
        echo html_writer::tag('div', $label, ['class' => 'dlbl']);

        if ($question) {
            echo html_writer::tag('div', $question, [
                'class' => 'dq',
                'style' => 'font-weight:bold; margin-bottom:10px;',
            ]);
        }

        echo html_writer::start_tag('div', ['class' => 'opts', 'style' => 'display:flex; gap:10px; flex-wrap:wrap;']);
        foreach ($options as $o => $desc) {
            $active = ($current_value == $o) ? 'main' : '';
            $url    = new moodle_url($PAGE->url, array_merge($step_params, [$param_name => $o]));
            
            echo html_writer::start_tag('div', ['style' => 'display:flex; align-items:center;']);
            lock_manager::render_pill($o, $url, "opt $active", $is_locked);
            echo self::render_tooltip($o, $desc);
            echo html_writer::end_tag('div');
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }

    /**
     * Utility to render a custom CSS tooltip.
     */
    private static function render_tooltip(string $title, string $text): string {
        $out = html_writer::start_tag('div', ['class' => 'rubricai-tooltip-container']);
        $out .= html_writer::tag('i', 'i', ['class' => 'rubricai-info-icon']);
        $out .= html_writer::tag('span', $text, ['class' => 'rubricai-tooltip-text']);
        $out .= html_writer::end_tag('div');
        return $out;
    }

    /**
     * Render a single row for the dynamic objective form.
     */
    private static function render_objective_row(int $index, array $data, array $bloom_options, bool $is_locked): string {
        $bloom = $data['bloom'] ?? '';
        $text = $data['text'] ?? '';

        $out = html_writer::start_tag('div', ['class' => 'objective-row', 'data-index' => $index]);
        
        // Bloom Select
        $select_attrs = [
            'class' => 'objective-bloom-select',
            'data-field' => 'bloom'
        ];
        if ($is_locked) $select_attrs['disabled'] = 'disabled';
        
        $out .= html_writer::start_tag('select', $select_attrs);
        $out .= html_writer::tag('option', 'Elegí una acción', ['value' => '']);
        foreach ($bloom_options as $key => $desc) {
            $opt_attrs = ['value' => $key];
            if ($bloom === $key) {
                $opt_attrs['selected'] = 'selected';
            }
            $out .= html_writer::tag('option', $key, $opt_attrs);
        }
        $out .= html_writer::end_tag('select');

        // Objective Text
        $input_attrs = [
            'type' => 'text',
            'class' => 'objective-text-input',
            'placeholder' => 'Define el objetivo aquí...',
            'value' => $text,
            'data-field' => 'text'
        ];
        if ($is_locked) $input_attrs['readonly'] = 'readonly';
        $out .= html_writer::empty_tag('input', $input_attrs);

        // Delete button
        if (!$is_locked) {
            $out .= html_writer::tag('button', '✕', [
                'type' => 'button',
                'class' => 'remove-objective-btn',
                'title' => 'Eliminar objetivo'
            ]);
        }
        
        $out .= html_writer::end_tag('div');
        return $out;
    }

    /**
     * Render RAG search results.
     */
    private static function render_rag_feedback(int $id, string $d2): void {
        $search_data = rag_client::search($id, $d2);

        echo html_writer::start_tag('div', [
            'class' => 'rubricai-card t-ia',
            'style' => 'border-left: 5px solid #185fa5; background: #f0f7ff; margin-bottom:20px;',
        ]);
        echo html_writer::tag('strong', '✨ Análisis de Contexto (RAG)', [
            'style' => 'display:block; margin-bottom:10px; color:#185fa5;',
        ]);

        if ($search_data && $search_data->status == 'success' && !empty($search_data->results)) {
            echo html_writer::tag('p',
                'He encontrado estos fragmentos relevantes en tus materiales para justificar tu objetivo:',
                ['style' => 'font-size:12px; margin-bottom:8px; font-weight:bold;']
            );
            echo '<ul style="font-size:11px; color:#555; list-style:none; padding:0;">';
            foreach (array_slice($search_data->results, 0, 2) as $res) {
                echo '<li style="background:rgba(255,255,255,0.5); padding:8px; border-radius:5px; margin-bottom:5px; border-left:3px solid #85b7eb;">';
                echo '<em>"' . s(mb_strimwidth($res->text, 0, 150, "...")) . '"</em><br>';
                echo '<small style="color:#185fa5;">— Fuente: ' . s($res->filename) . '</small>';
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo html_writer::tag('p',
                'No se encontraron fragmentos específicos en los PDFs, pero puedo ayudarte con sugerencias generales.',
                ['style' => 'font-size:12px; color:#666;']
            );
        }

        echo html_writer::end_tag('div');
    }
}
