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
 * Step 1 — Contexto objetivo.
 * Shows imported course data and manages RAG embedding build.
 */
class step1 {

    public static function render(array $ctx): void {
        global $PAGE, $OUTPUT;

        $id        = $ctx['id'] ?: optional_param('id', 0, PARAM_INT);
        $summary   = $ctx['summary'];
        $files     = $ctx['files'];
        $use_moodle = session_manager::get('use_moodle', 1);

        
        echo html_writer::tag('p', 'Contexto pedagógico de la asignatura', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p',
            'Verificá y escogé los recursos importados de Moodle para asegurar que tu biblioteca contenga todo lo que necesitás',
            ['class' => 'areteia-sdesc']
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

        if ($use_moodle) {
            self::render_moodle_fields($id, $summary, $files, $already_ingested, $service_down, $status_data, $prev_selected);
        } else {
            echo $OUTPUT->notification('Te pedimos disculpa. La carga manual no está disponible en esta versión .', 'warning');
        }

        // Bottom section depends on ingestion state
        $ingested = optional_param('ingested', 0, PARAM_INT);
        self::render_ingestion_status($id, $ingested, $already_ingested, $service_down);
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

        echo html_writer::start_tag('div', ['class' => 'areteia-fields']);

        // --- Field: Asignatura ---
        echo html_writer::start_tag('div', ['class' => 'areteia-fr']);
        echo html_writer::start_tag('div', ['class' => 'areteia-flbl']);
        echo 'Asignatura';
        echo html_writer::end_tag('div');
        echo html_writer::tag('div', $summary['fullname'], ['class' => 'areteia-fb fc']);
        echo html_writer::end_tag('div');

        // --- Field: Recursos ---
        // --- Field: Recursos ---
if (!$already_ingested) {
    echo html_writer::start_tag('div', ['class' => 'areteia-fr']);
    echo html_writer::start_tag('div', ['class' => 'areteia-flbl']);
    echo 'Recursos detectados';
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('div', ['class' => 'areteia-fb fw']);

    if ($service_down) {
        echo html_writer::tag('div',
            'El servicio de IA aún no ha arrancado (posible reinicio de PC). Reintentando...',
            ['class' => 'areteia-fb fw', 'style' => 'color:#ff9800;']
        );
    } else {
        $tree = \local_areteia\data_provider::get_course_materials_tree($id);
        echo html_writer::start_tag('div', [
            'class' => 'areteia-fb fw',
            'style' => 'margin-bottom:15px; display: flex; justify-content: space-between; align-items: center;'
        ]);
        echo html_writer::tag('span', 'Seleccioná los recursos para AreteIA:', ['style' => 'font-weight:bold;']);
        echo html_writer::tag('span', 'Calculando...', [
            'id' => 'selection-count-badge',
            'class' => 'sb-tag sb-warn',
            'style' => 'margin-left:10px; padding: 4px 10px; border-radius: 12px; font-size: 11px;'
        ]);
        echo html_writer::end_tag('div');

        self::render_materials_tree($tree, $prev_selected);
    }

    echo html_writer::end_tag('div'); // areteia-fb
    echo html_writer::end_tag('div'); // areteia-fr
}
    }

    /**
     * Recursively render the hierarchical materials tree.
     * @param array $selected_files  Relative paths that were previously embedded.
     *                               Empty array = initial load, check everything.
     */
    private static function render_materials_tree(array $tree, array $selected_files = []): void {
        echo html_writer::start_tag('div', ['class' => 'areteia-tree', 'id' => 'materials-tree']);
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
        bool $service_down
    ): void {
        global $PAGE;

        $prev_url = new moodle_url($PAGE->url, ['step' => 0]);

        if ($already_ingested || $ingested == 1) {
            // Success
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left: 5px solid #28a745; background: #f4fff4; margin-bottom:20px; display: flex; justify-content: space-between; align-items: center;',
            ]);
            echo html_writer::start_tag('div');
            echo html_writer::tag('strong', '✨ Biblioteca creada con éxito!', [
                'style' => 'color:#28a745; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p',
                'AreteIA ya tiene acceso a los recursos de tu curso para darte mejores respuestas.',
                ['style' => 'font-size:12px; margin:0;']
            );
            echo html_writer::end_tag('div');

            $delete_url = new moodle_url($PAGE->url, ['step' => 1, 'action' => 'delete_rag', 'sesskey' => sesskey()]);
            echo html_writer::link($delete_url, 'Reconstruir Biblioteca', [
                'class' => 'areteia-btn', 
                'style' => 'background: #fff; color: #dc3545; border: 1px solid #dc3545; font-size: 12px; padding: 6px 12px;',
                'data-confirm' => '¿Estás seguro de que deseas eliminar y reconstruir la biblioteca? Tendrás que volver a procesar los documentos.',
            ]);
            echo html_writer::end_tag('div');
            step_renderer::render_nav(1, $prev_url, new moodle_url($PAGE->url, ['step' => 3, 'action' => 'eval']), 'Continuar a Crear Evaluación →');
        } else if ($ingested == 2) {
            // Empty content
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left: 5px solid #ffca28; background: #fffcf0; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '⚠️ Sin texto extraíble', [
                'style' => 'color:#ffca28; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p',
                'Moodle entregó los archivos, pero AreteIA no encontró texto (quizás sean PDFs escaneados o carpetas vacías). Esto limitará las sugerencias de evaluación.',
                ['style' => 'font-size:12px; line-height:1.4; margin:0;']
            );
            echo html_writer::end_tag('div');
            step_renderer::render_nav(1, $prev_url, new moodle_url($PAGE->url, ['step' => 3, 'action' => 'eval']), 'Continuar al paso 2 →');
        } else if ($ingested == 3) {
            // Processing in background — but check if it's actually already done
            $real_status_raw = \local_areteia\rag_client::status($id)['raw'];
            $real_status_obj = @json_decode($real_status_raw);
            if (!empty($real_status_obj->embedding_exists)) {
                self::render_ingestion_status($id, 1, true, false);
                return;
            }

            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left: 5px solid #17a2b8; background: #f4f8ff; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '⏳ Construyendo biblioteca de AreteIA...', [
                'style' => 'color:#17a2b8; display:block; margin-bottom:10px;',
            ]);
            
            // Progress Bar Container
            echo html_writer::start_tag('div', ['class' => 'areteia-progress-container']);
            echo html_writer::start_tag('div', ['class' => 'areteia-progress-bar-wrap']);
            echo html_writer::tag('div', '', [
                'id' => 'areteia-ingestion-bar',
                'class' => 'areteia-progress-bar-fill',
                'style' => 'width: 5%;'
            ]);
            echo html_writer::end_tag('div');
            
            echo html_writer::start_tag('div', ['class' => 'areteia-progress-info']);
            echo html_writer::tag('span', 'Iniciando...', ['id' => 'areteia-ingestion-status', 'class' => 'areteia-progress-status']);
            echo html_writer::tag('span', '5%', ['id' => 'areteia-ingestion-percent']);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div'); // progress-container

            echo html_writer::tag('p',
                'Una vez finalizado, podrás continuar con el proceso.',
                ['style' => 'font-size:12px; line-height:1.4; margin-top:15px; color:#666;']
            );
            echo html_writer::end_tag('div');
            
            // Initialize the poller
            echo html_writer::tag('script', "document.addEventListener('DOMContentLoaded', () => { initIngestionPoller($id); });");

            
            echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
            echo html_writer::link($prev_url, '← Volver', ['class' => 'areteia-btn']);

            echo html_writer::tag('span', 'Procesando...', [
                'class' => 'areteia-btn disabled',
                'style' => 'opacity:0.7; cursor:wait;',
            ]);
            echo html_writer::end_tag('div');

        } else if ($ingested == -1) {
            // Error
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
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
            echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
            echo html_writer::link($prev_url, '← Volver', ['class' => 'areteia-btn']);
            
            echo html_writer::tag('span', 'Esperando al servicio de AretIA...', [
                'class' => 'areteia-btn disabled',
                'style' => 'opacity:0.7; cursor:wait;',
            ]);
            echo html_writer::end_tag('div');

        } else {
            // Ready to build — native form POST
            echo html_writer::start_tag('form', [
                'action' => new moodle_url($PAGE->url, ['step' => 1, 'action' => 'ingest']),
                'method' => 'POST',
                'id'     => 'areteia-ingest-form',
            ]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', [
                'type'  => 'hidden',
                'name'  => 'selected_files',
                'id'    => 'selected-files-input',
                'value' => ''
            ]);

            echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
            echo html_writer::link($prev_url, '← Anterior', ['class' => 'areteia-btn']);
            
            echo html_writer::tag('button', 'Confirmar y Construir Biblioteca', [
                'type'     => 'submit',
                'id'       => 'confirm-ingest-btn',
                'class'    => 'areteia-btn areteia-btn-primary',
                'data-ia'  => '1'
            ]);
            echo html_writer::end_tag('div');

            echo html_writer::end_tag('form');
        }
    }
}
