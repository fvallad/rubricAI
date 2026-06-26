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
 * Step 1 renderer — Data ingestion and vector embeddings.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step1 {

    public static function render(array $ctx): void {
        global $PAGE, $OUTPUT;

        $id        = $ctx['id'] ?: optional_param('id', 0, PARAM_INT);
        $summary   = $ctx['summary'];
        $files     = $ctx['files'];
        $use_moodle = session_manager::get('use_moodle', 1);

        
        echo html_writer::tag('p', 'Contexto pedagógico de la asignatura', ['class' => 'rubricai-stitle']);
        echo html_writer::tag('p',
            'Verificá y escogé los recursos importados de Moodle para asegurar que tu biblioteca contenga todo lo que necesitás',
            ['class' => 'rubricai-sdesc']
        );

        // Lock banner: protect downstream progress
        $is_locked = lock_manager::is_locked(1);
        if ($is_locked) {
            lock_manager::render_lock_banner(
                '🔒 Opción bloqueada',
                'Esta sección está protegida porque ya avanzaste.',
                new moodle_url($PAGE->url, ['step' => 0, 'unlock' => 2]),
                '🔓 Desbloquear (Se borrará el progreso)'
            );
        }

        // Check embedding status from Python service
        $status_result    = rag_client::status($id);
        $status_data      = $status_result['data'];   // puede tener progress durante build
        $status_raw       = $status_result['raw'];
        $status_obj       = @json_decode($status_raw); // objeto completo del Python

        // Nuevo formato: embedding_exists en el root del JSON
        $already_ingested = !empty($status_obj->embedding_exists);
        $service_down     = ($status_raw === false || empty($status_raw));

        // Archivos que fueron usados en el embedding anterior ([] = nunca generados)
        $prev_selected = [];
        if (!empty($status_obj->selected_files) && is_array($status_obj->selected_files)) {
            $prev_selected = $status_obj->selected_files;
        }

        // Banner: evaluation in progress — warn before rebuilding library
        $eval_in_progress = !empty($status_obj->evaluation_in_progress);
        $eval_elapsed     = (int)($status_obj->evaluation_elapsed ?? 0);
        if ($eval_in_progress) {
            $mins = floor($eval_elapsed / 60);
            $secs = $eval_elapsed % 60;
            $time_str = $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";
            echo html_writer::tag('div',
                '⚠️ Hay una <strong>Auditoría en progreso</strong> para este curso (' . $time_str . ' transcurridos). ' .
                'Reconstruir la biblioteca mientras corre puede corromper los resultados.',
                ['class' => 'rubricai-card', 'style' =>
                    'border-left:5px solid #ff9800; background:#fff8f0; color:#7a4e00; margin-bottom:16px;']
            );
        }

        if ($use_moodle) {
            self::render_moodle_fields($id, $summary, $files, $already_ingested, $service_down, $status_data, $prev_selected);
        } else {
            echo $OUTPUT->notification('Te pedimos disculpa. La carga manual no está disponible en esta versión .', 'warning');
        }

        // Bottom section depends on ingestion state
        $ingested = optional_param('ingested', 0, PARAM_INT);
        self::render_ingestion_status($id, $ingested, $already_ingested, $service_down, $eval_in_progress);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function render_moodle_fields(
        int $id,
        array $summary,
        array $files,
        bool $already_ingested,
        bool $service_down,
        ?object $status_data,
        array $prev_selected = []
    ): void {
        global $PAGE;

        echo html_writer::start_tag('div', ['class' => 'rubricai-fields']);

        // --- Field: Asignatura ---
        echo html_writer::start_tag('div', ['class' => 'rubricai-fr']);
        echo html_writer::start_tag('div', ['class' => 'rubricai-flbl']);
        echo 'Asignatura';
        echo html_writer::end_tag('div');
        echo html_writer::tag('div', $summary['fullname'], ['class' => 'rubricai-fb fc']);
        echo html_writer::end_tag('div');

        // --- Field: Recursos ---
        // --- Field: Recursos ---
if (!$already_ingested) {
    echo html_writer::start_tag('div', ['class' => 'rubricai-fr']);
    echo html_writer::start_tag('div', ['class' => 'rubricai-flbl']);
    echo 'Recursos detectados';
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('div', ['class' => 'rubricai-fb fw']);

    if ($service_down) {
        echo html_writer::tag('div',
            'El servicio de IA aún no ha arrancado (posible reinicio de PC). Reintentando...',
            ['class' => 'rubricai-fb fw', 'style' => 'color:#ff9800;']
        );
    } else {
        $tree = \local_rubricai\data_provider::get_course_materials_tree($id);
        echo html_writer::start_tag('div', [
            'class' => 'rubricai-fb fw',
            'style' => 'margin-bottom:15px; display: flex; justify-content: space-between; align-items: center;'
        ]);
        echo html_writer::tag('span', 'Seleccioná los recursos para RubricAI:', ['style' => 'font-weight:bold;']);
        echo html_writer::tag('span', 'Calculando...', [
            'id' => 'selection-count-badge',
            'class' => 'sb-tag sb-warn',
            'style' => 'margin-left:10px; padding: 4px 10px; border-radius: 12px; font-size: 11px;'
        ]);
        echo html_writer::end_tag('div');

        self::render_materials_tree($tree, $prev_selected);
    }

    echo html_writer::end_tag('div'); // rubricai-fb
    echo html_writer::end_tag('div'); // rubricai-fr
}
    }

    /**
     * Recursively render the hierarchical materials tree.
     * @param array $selected_files  Relative paths that were previously embedded.
     *                               Empty array = initial load, check everything.
     */
    private static function render_materials_tree(array $tree, array $selected_files = []): void {
        echo html_writer::start_tag('div', ['class' => 'rubricai-tree', 'id' => 'materials-tree']);
        self::render_tree_node($tree, 0, $selected_files);
        echo html_writer::end_tag('div');
    }

    /**
     * Helper to render a single tree node and its children.
     * @param array $selected_files  Relative paths from embedding. Empty = all checked.
     */
    private static function render_tree_node(array $node, int $depth = 0, array $selected_files = []): void {
        $type = $node['type'];
        $id   = $node['id'];
        $name = $node['name'];
        $uid  = "tree-{$type}-{$id}";
        
        $has_children = !empty($node['sections']) || !empty($node['activities']) || !empty($node['files']);

        // Icons based on type
        $icons = [
            'course'   => '🎓',
            'section'  => '📁',
            'activity' => '🧩',
            'file'     => '📄'
        ];
        $icon = $icons[$type] ?? '•';

        echo html_writer::start_tag('div', ['class' => "tree-node tree-{$type}"]);

        echo html_writer::start_tag('div', ['class' => 'tree-row']);
        
        // Toggle chevron (only if there are children)
        if ($has_children) {
            echo html_writer::tag('span', '▼', ['class' => 'tree-toggle', 'title' => 'Colapsar/Expandir']);
        } else {
            echo html_writer::tag('span', '', ['class' => 'tree-toggle-spacer']);
        }

        // Pre-check: if no prior selection exists (first time), check all.
        // If a prior selection exists, check only files that were embedded.
        $relpath = $node['relpath'] ?? '';
        if ($type === 'file') {
            $is_checked = empty($selected_files) || in_array($relpath, $selected_files, true);
        } else {
            // JS updateParentStates() will accurately calculate checked/indeterminate 
            // states for all parent nodes on initialization.
            $is_checked = false;
        }

        $attr = [
            'type'      => 'checkbox',
            'class'     => 'tree-cb',
            'id'        => $uid,
            'data-type' => $type,
            'data-id'   => $id,
            'value'     => ($type === 'file' ? $relpath : $id)
        ];
        if ($is_checked) $attr['checked'] = 'checked';

        echo html_writer::empty_tag('input', $attr);
        echo html_writer::start_tag('label', ['for' => $uid, 'class' => 'tree-label-text']);
        echo html_writer::tag('span', $icon, ['class' => 'tree-icon']);
        echo html_writer::tag('span', s($name), ['class' => 'tree-name']);
        echo html_writer::end_tag('label');
        echo html_writer::end_tag('div'); // tree-row

        // Children wrapper
        if ($has_children) {
            echo html_writer::start_tag('div', ['class' => 'tree-children']);
            if (!empty($node['sections'])) {
                foreach ($node['sections'] as $s) self::render_tree_node($s, $depth + 1, $selected_files);
            }
            if (!empty($node['activities'])) {
                foreach ($node['activities'] as $a) self::render_tree_node($a, $depth + 1, $selected_files);
            }
            if (!empty($node['files'])) {
                foreach ($node['files'] as $f) self::render_tree_node($f, $depth + 1, $selected_files);
            }
            echo html_writer::end_tag('div');
        }

        echo html_writer::end_tag('div'); // tree-node
    }

    private static function render_ingestion_status(
        int $id,
        int $ingested,
        bool $already_ingested,
        bool $service_down,
        bool $eval_in_progress = false
    ): void {
        global $PAGE;

        $prev_url = new moodle_url($PAGE->url, ['step' => 0, 'force_step' => 0]);

        if ($already_ingested || $ingested == 1) {
            // Success
            echo html_writer::start_tag('div', [
                'class' => 'rubricai-card',
                'style' => 'border-left: 5px solid #28a745; background: #f4fff4; margin-bottom:20px; display: flex; justify-content: space-between; align-items: center;',
            ]);
            echo html_writer::start_tag('div');
            echo html_writer::tag('strong', '✨ Biblioteca creada con éxito!', [
                'style' => 'color:#28a745; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p',
                'RubricAI ya tiene acceso a los recursos de tu curso para darte mejores respuestas.',
                ['style' => 'font-size:12px; margin:0;']
            );
            echo html_writer::end_tag('div');

            if ($eval_in_progress) {
                echo html_writer::tag('span', '⏳ Auditoría en curso...', [
                    'class' => 'rubricai-btn',
                    'style' => 'background:#fff; color:#ff9800; border:1px solid #ff9800; font-size:12px; padding:6px 12px; opacity:0.7; cursor:not-allowed;',
                ]);
            } else {
                $delete_url = new moodle_url($PAGE->url, ['step' => 1, 'action' => 'delete_rag', 'sesskey' => sesskey()]);
                echo html_writer::link($delete_url, 'Reconstruir Biblioteca', [
                    'class' => 'rubricai-btn',
                    'style' => 'background: #fff; color: #dc3545; border: 1px solid #dc3545; font-size: 12px; padding: 6px 12px;',
                    'data-confirm' => '¿Estás seguro de que deseas eliminar y reconstruir la biblioteca? Tendrás que volver a procesar los documentos.',
                ]);
            }
            echo html_writer::end_tag('div');
            step_renderer::render_nav(1, $prev_url, new moodle_url($PAGE->url, ['step' => 8, 'action' => 'compare']), 'Ir a Auditoría RubricAI →');
        } else if ($ingested == 2) {
            // Empty content
            echo html_writer::start_tag('div', [
                'class' => 'rubricai-card',
                'style' => 'border-left: 5px solid #ffca28; background: #fffcf0; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '⚠️ Sin texto extraíble', [
                'style' => 'color:#ffca28; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p',
                'Moodle entregó los archivos, pero RubricAI no encontró texto (quizás sean PDFs escaneados o carpetas vacías). Esto limitará las sugerencias de evaluación.',
                ['style' => 'font-size:12px; line-height:1.4; margin:0;']
            );
            echo html_writer::end_tag('div');
            step_renderer::render_nav(1, $prev_url, new moodle_url($PAGE->url, ['step' => 8, 'action' => 'compare']), 'Ir a Auditoría RubricAI →');
        } else if ($ingested == 3) {
            // Processing in background — but check if it's actually already done
            $real_status_raw = \local_rubricai\rag_client::status($id)['raw'];
            $real_status_obj = @json_decode($real_status_raw);
            if (!empty($real_status_obj->embedding_exists)) {
                self::render_ingestion_status($id, 1, true, false);
                return;
            }

            echo html_writer::start_tag('div', [
                'class' => 'rubricai-card',
                'style' => 'border-left: 5px solid #17a2b8; background: #f4f8ff; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '⏳ Construyendo biblioteca de RubricAI...', [
                'style' => 'color:#17a2b8; display:block; margin-bottom:10px;',
            ]);
            
            // Progress Bar Container
            echo html_writer::start_tag('div', ['class' => 'rubricai-progress-container']);
            echo html_writer::start_tag('div', ['class' => 'rubricai-progress-bar-wrap']);
            echo html_writer::tag('div', '', [
                'id' => 'rubricai-ingestion-bar',
                'class' => 'rubricai-progress-bar-fill',
                'style' => 'width: 5%;'
            ]);
            echo html_writer::end_tag('div');
            
            echo html_writer::start_tag('div', ['class' => 'rubricai-progress-info']);
            echo html_writer::tag('span', 'Iniciando...', ['id' => 'rubricai-ingestion-status', 'class' => 'rubricai-progress-status']);
            echo html_writer::tag('span', '5%', ['id' => 'rubricai-ingestion-percent']);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div'); // progress-container

            echo html_writer::tag('p',
                'Una vez finalizado, podrás continuar con el proceso.',
                ['style' => 'font-size:12px; line-height:1.4; margin-top:15px; color:#666;']
            );
            echo html_writer::end_tag('div');
            
            // Initialize the poller
            echo html_writer::tag('script', "document.addEventListener('DOMContentLoaded', () => { initIngestionPoller($id); });");

            
            echo html_writer::start_tag('div', ['class' => 'rubricai-nav']);
            echo html_writer::link($prev_url, '← Volver', ['class' => 'rubricai-btn']);

            echo html_writer::tag('span', 'Procesando...', [
                'class' => 'rubricai-btn disabled',
                'style' => 'opacity:0.7; cursor:wait;',
            ]);
            echo html_writer::end_tag('div');

        } else if ($ingested == -1) {
            // Error
            echo html_writer::start_tag('div', [
                'class' => 'rubricai-card',
                'style' => 'border-left: 5px solid #dc3545; background: #fff4f4; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '❌ Error de conexión', [
                'style' => 'color:#dc3545; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p',
                'Hubo un fallo al intentar conectarse al servicio Python. Verifica los logs de docker.',
                ['style' => 'font-size:12px; margin:0;']
            );
            echo html_writer::end_tag('div');

            $retry_url = new moodle_url($PAGE->url, ['id' => $id, 'action' => 'ingest', 'sesskey' => sesskey()]);
            step_renderer::render_nav(1, $prev_url, $retry_url, 'Reintentar Construcción');

        } else if ($service_down) {
            // Service not ready
            echo html_writer::start_tag('div', ['class' => 'rubricai-nav']);
            echo html_writer::link($prev_url, '← Volver', ['class' => 'rubricai-btn']);
            
            echo html_writer::tag('span', 'Esperando al servicio de AretIA...', [
                'class' => 'rubricai-btn disabled',
                'style' => 'opacity:0.7; cursor:wait;',
            ]);
            echo html_writer::end_tag('div');

        } else {
            // Ready to build — native form POST
            echo html_writer::start_tag('form', [
                'action' => new moodle_url($PAGE->url, ['step' => 1, 'action' => 'ingest']),
                'method' => 'POST',
                'id'     => 'rubricai-ingest-form',
            ]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', [
                'type'  => 'hidden',
                'name'  => 'selected_files',
                'id'    => 'selected-files-input',
                'value' => ''
            ]);

            echo html_writer::start_tag('div', ['class' => 'rubricai-nav']);
            echo html_writer::link($prev_url, '← Anterior', ['class' => 'rubricai-btn']);
            
            echo html_writer::tag('button', 'Confirmar y Construir Biblioteca', [
                'type'     => 'submit',
                'id'       => 'confirm-ingest-btn',
                'class'    => 'rubricai-btn rubricai-btn-primary',
                'data-ia'  => '1'
            ]);
            echo html_writer::end_tag('div');

            echo html_writer::end_tag('form');
        }
    }
}
