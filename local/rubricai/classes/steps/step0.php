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
use local_rubricai\step_renderer;

/**
 * Step 0 renderer — Course selection and model setup.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step0 {

    public static function render(array $ctx): void {
        global $PAGE, $SESSION;

        $id = $ctx['id'];

        echo html_writer::tag('p', 'Bienvenido a RubricAI', ['class' => 'rubricai-stitle']);
        echo html_writer::tag('p',
            'RubricAI te asistirá en el diseño pedagógico de tu curso importando automáticamente los recursos de Moodle.',
            ['class' => 'rubricai-sdesc']
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
