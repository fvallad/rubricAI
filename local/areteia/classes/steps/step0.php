<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\lock_manager;
use local_areteia\step_renderer;

/**
 * Step 0 — Punto de entrada.
 * The teacher chooses between importing from Moodle or manual context entry.
 */
class step0 {

    public static function render(array $ctx): void {
        global $PAGE, $SESSION;

        $id = $ctx['id'];

        echo html_writer::tag('p', 'Bienvenido a AreteIA', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p',
            'AreteIA te asistirá en el diseño pedagógico de tu curso importando automáticamente los recursos de Moodle.',
            ['class' => 'areteia-sdesc']
        );

        $has_downstream = session_manager::has_any('d1', 's_sugs', 'instrument');
        $is_locked = $has_downstream;

        // Navigation
        $action = $ctx['action'] ?? 'lib';
        $next_url = new moodle_url($PAGE->url, [
            'step'       => 1,
            'use_moodle' => 1,
            'action'     => 'sync', // Auto sync
        ]);

        step_renderer::render_nav(
            0,
            null, // No back button for step 0
            $next_url,
            'Continuar →',
            []
        );

        // Handle clear action
        if (optional_param('clear', 0, PARAM_INT)) {
            session_manager::clear();
            $redir_url = new moodle_url($PAGE->url, ['step' => 0]);
            if ($ctx['is_ajax']) {
                $redir_url->param('ajax', 1);
            }
            redirect($redir_url);
        }
    }
}
