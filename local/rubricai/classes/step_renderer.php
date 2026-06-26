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
 * Step renderer and workflow dispatcher.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_renderer {

    /** 
     * Definition of actions (tabs) and their step sequences.
     */
    public const ACTIONS = [
        'lib'  => [
            'label' => 'Crear Biblioteca',
            'steps' => [0, 1],
            'icon'  => '📚'
        ],
        'compare' => [
            'label' => 'Auditoría RubricAI',
            'steps' => [8],
            'icon'  => '🔍'
        ]
    ];

    /**
     * Render the complete step view (tabs + progress bar + card + step content).
     *
     * @param string    $action   Current action (lib, eval, crit)
     * @param int       $step     Current step
     * @param int       $id       Course ID
     * @param array     $summary  Course summary from data_provider
     * @param array     $files    Course files from data_provider
     * @param \context  $context  Moodle context
     * @param bool      $is_ajax  Whether this is an AJAX request
     */
    public static function render(
        string $action,
        int $step,
        int $id,
        array $summary,
        array $files,
        \context $context,
        bool $is_ajax
    ): void {
        global $PAGE, $CFG;

        // Header bar with subtitle and link to go back to course view
        $courseurl = new \moodle_url('/course/view.php', ['id' => $id]);
        echo \html_writer::start_tag('div', ['class' => 'rubricai-header-bar']);
        echo \html_writer::tag('p', 'RubricAI · Prototipo', ['class' => 'rubricai-subtitle']);
        echo \html_writer::link($courseurl, '← Volver al Curso', ['class' => 'rubricai-back-link']);
        echo \html_writer::end_tag('div');

        // Tabs
        self::render_tabs($action, $id);

        // Progress bar (scoped to current action steps)
        self::render_progress_bar($action, $step);

        // Card wrapper
        echo \html_writer::start_tag('div', ['class' => 'rubricai-card']);

        // Context array passed to every step
        $ctx = [
            'id'       => $id,
            'summary'  => $summary,
            'files'    => $files,
            'context'  => $context,
            'is_ajax'  => $is_ajax,
            'action'   => $action,
        ];

        // Dispatch to step class
        $class = "\\local_rubricai\\steps\\step{$step}";
        if (class_exists($class)) {
            $class::render($ctx);
        }

        echo \html_writer::end_tag('div'); // card

        // CSS/JS Handler for AI Prompt Preview
        self::render_ai_preview_handler($id);
    }

    /**
     * Render the top tab navigation.
     */
    public static function render_tabs(string $current_action, int $courseid): void {
        echo \html_writer::start_tag('div', ['class' => 'rubricai-tabs']);

        foreach (self::ACTIONS as $key => $cfg) {
            $active = ($key === $current_action) ? 'active' : '';
            // Default step for each action is the first one in its sequence
            $url = new \moodle_url('/local/rubricai/index.php', [
                'id'     => $courseid,
                'action' => $key,
                'step'   => $cfg['steps'][0]
            ]);
            
            echo \html_writer::start_tag('a', [
                'href'  => $url->out(false),
                'class' => "rubricai-tab $active rubricai-btn" // Reuse btn styles partially
            ]);
            echo \html_writer::tag('span', $cfg['icon'], ['class' => 'tab-icon']);
            echo \html_writer::tag('span', $cfg['label'], ['class' => 'tab-label']);
            echo \html_writer::end_tag('a');
        }

        echo \html_writer::end_tag('div');
    }

    /**
     * Render the dot progress bar for the current action sequence.
     */
    private static function render_progress_bar(string $action, int $current_step): void {
        global $PAGE;

        if (!isset(self::ACTIONS[$action])) {
            return;
        }

        $steps = self::ACTIONS[$action]['steps'];
        $count = count($steps);

        echo \html_writer::start_tag('div', ['class' => 'rubricai-progress']);

        foreach ($steps as $index => $snum) {
            // Find if current step is this one, or before/after in the sequence
            $pos = array_search($current_step, $steps);
            if ($pos === false) $pos = 0; // Fallback

            if ($snum === $current_step) {
                $class = 'active';
            } else if ($index < $pos) {
                $class = 'done';
            } else {
                $class = 'pending';
            }

            $url   = new \moodle_url($PAGE->url, ['step' => $snum, 'action' => $action]);
            echo \html_writer::link($url, $index + 1, ['class' => "rubricai-dot $class"]);

            if ($index < $count - 1) {
                $line_class = ($index < $pos) ? 'done' : '';
                echo \html_writer::tag('div', '', ['class' => "rubricai-line $line_class"]);
            }
        }

        echo \html_writer::end_tag('div');
    }

    /**
     * Render the standard bottom navigation bar used by most steps.
     *
     * @param int           $step           Current step number
     * @param \moodle_url   $prev_url       Back button URL (if null, computed from action)
     * @param \moodle_url|null $next_url    Next button URL (if null, computed from action)
     * @param string        $next_label     Label for next button
     * @param array         $next_attrs     Extra attributes for next button
     * @param string|null   $disabled_label If set, show a disabled span instead of next button
     */
    public static function render_nav(
        int $step,
        ?\moodle_url $prev_url = null,
        ?\moodle_url $next_url = null,
        string $next_label = '',
        array $next_attrs = [],
        ?string $disabled_label = null
    ): void {
        global $PAGE;
        
        $action = optional_param('action', 'lib', PARAM_ALPHA);
        $steps  = self::ACTIONS[$action]['steps'] ?? [0];
        $pos    = array_search($step, $steps);
        
        // Compute default URLs if not provided
        if ($prev_url === null && $pos > 0) {
            $prev_url = new \moodle_url($PAGE->url, ['step' => $steps[$pos - 1], 'action' => $action]);
        }
        
        if ($next_url === null && $pos !== false && $pos < count($steps) - 1) {
            $next_url = new \moodle_url($PAGE->url, ['step' => $steps[$pos + 1], 'action' => $action]);
            if (empty($next_label)) {
                $next_label = "Siguiente →";
            }
        }

        echo \html_writer::start_tag('div', ['class' => 'rubricai-nav']);
        
        if ($prev_url) {
            echo \html_writer::link($prev_url, '← Anterior', ['class' => 'rubricai-btn']);
        } else {
            echo '<span></span>';
        }

        $display_pos = ($pos !== false) ? ($pos + 1) : '?';
        $total = count($steps);
        

        if ($disabled_label !== null) {
            echo \html_writer::tag('span', $disabled_label, [
                'class' => 'rubricai-btn disabled',
                'style' => 'opacity:0.5; cursor:not-allowed;',
            ]);
        } else if ($next_url || !empty($next_label)) {
            $attrs = array_merge(['class' => 'rubricai-btn rubricai-btn-primary'], $next_attrs);
            if ($next_url) {
                echo \html_writer::link($next_url, $next_label, $attrs);
            } else {
                // If no URL, render a button (useful for JS/Form interception)
                echo \html_writer::tag('button', $next_label, $attrs);
            }
        } else {
            echo '<span></span>';
        }

        echo \html_writer::end_tag('div');
    }

    public static function render_preview_button(int $step): string {
        return \html_writer::tag('button', '👁️ Ver Prompt', [
            'type'        => 'button',
            'class'       => 'rubricai-btn rubricai-btn-preview',
            'data-p-step' => $step,
            'title'       => 'Ver el diseño del prompt que se enviará a la IA',
            'style'       => 'display: none;'
        ]);
    }

    /**
     * Render the JS/CSS handler and modal for prompt preview.
     */
    private static function render_ai_preview_handler(int $courseid): void {
        echo '
        <div id="prompt-preview-overlay" class="rubricai-preview-overlay">
            <div class="rubricai-preview-card">
                <div class="rubricai-preview-header">
                    <div class="rubricai-preview-title">✨ Previsualización del Prompt</div>
                    <button class="rubricai-preview-close" onclick="closePromptPreview()">&times;</button>
                </div>
                <div class="rubricai-preview-body">
                    <div class="rubricai-preview-section">
                        <span class="rubricai-preview-label">SYSTEM PROMPT (Rol)</span>
                        <div id="preview-system-content" class="rubricai-preview-content"></div>
                    </div>
                    <div class="rubricai-preview-section">
                        <span class="rubricai-preview-label">USER PROMPT (Instrucciones y Contexto)</span>
                        <div id="preview-user-content" class="rubricai-preview-content"></div>
                    </div>
                </div>
                <div class="rubricai-preview-footer">
                    <button class="btn-copy-prompt" onclick="copyPromptToClipboard()">📋 Copiar Prompt Completo</button>
                </div>
            </div>
        </div>
        ';
    }

    /**
     * Render a premium badge showing token usage and estimated cost.
     * 
     * @param array|object|null $usage {input_tokens, output_tokens, total_tokens}
     */
    public static function render_ai_usage_badge($usage = null): string {
        if (!$usage) return '';
        
        $usage = (object)$usage;
        $in  = $usage->input_tokens ?? 0;
        $out = $usage->output_tokens ?? 0;
        $tot = $usage->total_tokens ?? 0;

        $html = \html_writer::start_tag('div', [
            'class' => 'rubricai-usage-badge',
            'style' => 'display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:rgba(108, 99, 255, 0.08); border:1px solid rgba(108, 99, 255, 0.2); border-radius:100px; font-size:11px; color:#5549d6; margin-top:10px; margin-bottom:10px;'
        ]);
        $html .= \html_writer::tag('span', '✨', ['style' => 'font-size:14px;']);
        $html .= \html_writer::start_tag('div', ['style' => 'line-height:1.2;']);
        $html .= \html_writer::tag('div', "Consumo: <strong>" . number_format($tot) . " tokens</strong>", ['style' => 'font-weight:600;']);
        $html .= \html_writer::tag('div', "Input: " . number_format($in) . " | Output: " . number_format($out), ['style' => 'font-size:9px; opacity:0.8;']);
        $html .= \html_writer::end_tag('div');
        $html .= \html_writer::end_tag('div');

        return $html;
    }

    /**
     * Render an informative note about RAG usage based on objectives.
     */
    public static function render_rag_info(): void {
        echo \html_writer::start_tag('div', ['class' => 'rubricai-note', 'style' => 'margin-top:10px;']);
        echo \html_writer::tag('strong', '💡 Alineación Pedagógica Inteligente: ');
        echo 'La IA ha analizado tus objetivos y ha extraído fragmentos relevantes de los materiales de tu curso para asegurar que esta propuesta esté 100% alineada con tus contenidos.';
        echo \html_writer::end_tag('div');
    }
}
