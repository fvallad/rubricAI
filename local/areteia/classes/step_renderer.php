<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates rendering: common header (subtitle + progress bar),
 * card wrapper, and dispatches to the correct step class.
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
        'eval' => [
            'label' => 'Crear evaluación',
            'steps' => [3, 4, 5, 7],
            'icon'  => '📝'
        ],
        'crit' => [
            'label' => 'Crear Criterio de Evaluación',
            'steps' => [6, 7],
            'icon'  => '⚖️'
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
        global $PAGE;

        // Subtitle
        echo \html_writer::tag('p', 'AreteIA · Prototipo', ['class' => 'areteia-subtitle']);

        // Tabs
        self::render_tabs($action, $id);

        // Progress bar (scoped to current action steps)
        self::render_progress_bar($action, $step);

        // Card wrapper
        echo \html_writer::start_tag('div', ['class' => 'areteia-card']);

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
        $class = "\\local_areteia\\steps\\step{$step}";
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
        echo \html_writer::start_tag('div', ['class' => 'areteia-tabs']);

        foreach (self::ACTIONS as $key => $cfg) {
            $active = ($key === $current_action) ? 'active' : '';
            // Default step for each action is the first one in its sequence
            $url = new \moodle_url('/local/areteia/index.php', [
                'id'     => $courseid,
                'action' => $key,
                'step'   => $cfg['steps'][0]
            ]);
            
            echo \html_writer::start_tag('a', [
                'href'  => $url->out(false),
                'class' => "areteia-tab $active areteia-btn" // Reuse btn styles partially
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

        echo \html_writer::start_tag('div', ['class' => 'areteia-progress']);

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
            echo \html_writer::link($url, $index + 1, ['class' => "areteia-dot $class"]);

            if ($index < $count - 1) {
                $line_class = ($index < $pos) ? 'done' : '';
                echo \html_writer::tag('div', '', ['class' => "areteia-line $line_class"]);
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

        echo \html_writer::start_tag('div', ['class' => 'areteia-nav']);
        
        if ($prev_url) {
            echo \html_writer::link($prev_url, '← Anterior', ['class' => 'areteia-btn']);
        } else {
            echo '<span></span>';
        }

        $display_pos = ($pos !== false) ? ($pos + 1) : '?';
        $total = count($steps);
        

        if ($disabled_label !== null) {
            echo \html_writer::tag('span', $disabled_label, [
                'class' => 'areteia-btn disabled',
                'style' => 'opacity:0.5; cursor:not-allowed;',
            ]);
        } else if ($next_url || !empty($next_label)) {
            $attrs = array_merge(['class' => 'areteia-btn areteia-btn-primary'], $next_attrs);
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
            'class'       => 'areteia-btn areteia-btn-preview',
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
        <div id="prompt-preview-overlay" class="areteia-preview-overlay">
            <div class="areteia-preview-card">
                <div class="areteia-preview-header">
                    <div class="areteia-preview-title">✨ Previsualización del Prompt</div>
                    <button class="areteia-preview-close" onclick="closePromptPreview()">&times;</button>
                </div>
                <div class="areteia-preview-body">
                    <div class="areteia-preview-section">
                        <span class="areteia-preview-label">SYSTEM PROMPT (Rol)</span>
                        <div id="preview-system-content" class="areteia-preview-content"></div>
                    </div>
                    <div class="areteia-preview-section">
                        <span class="areteia-preview-label">USER PROMPT (Instrucciones y Contexto)</span>
                        <div id="preview-user-content" class="areteia-preview-content"></div>
                    </div>
                </div>
                <div class="areteia-preview-footer">
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
            'class' => 'areteia-usage-badge',
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
        echo \html_writer::start_tag('div', ['class' => 'areteia-note', 'style' => 'margin-top:10px;']);
        echo \html_writer::tag('strong', '💡 Alineación Pedagógica Inteligente: ');
        echo 'La IA ha analizado tus objetivos y ha extraído fragmentos relevantes de los materiales de tu curso para asegurar que esta propuesta esté 100% alineada con tus contenidos.';
        echo \html_writer::end_tag('div');
    }
}
