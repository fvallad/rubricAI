<?php
namespace local_rubricai\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_rubricai\session_manager;
use local_rubricai\step_renderer;
use local_rubricai\rag_client;
use local_rubricai\data_provider;

/**
 * Step 8 — Auditoría RubricAI
 * Allows selecting a rubric, triggering multi-agent evaluation, and linking to Astro frontend.
 */
class step8 {

    public static function render(array $ctx): void {
        global $PAGE, $CFG;

        $courseid = $ctx['id'];
        $course_name = $ctx['summary']['fullname'] ?? 'Curso';

        // Fetch rubrics
        $base_url = 'http://host.docker.internal:8000'; // Fallback
        $ini_path = __DIR__ . '/../../rubricai.ini';
        if (file_exists($ini_path)) {
            $config = parse_ini_file($ini_path);
            if (!empty($config['rubricai_ai_url'])) {
                $base_url = rtrim($config['rubricai_ai_url'], '/');
            }
        }
        
        $ch = curl_init($base_url . '/rubrics');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $raw_response = curl_exec($ch);
        curl_close($ch);
        
        $rubrics = @json_decode($raw_response, true) ?: [];

        echo html_writer::tag('h2', '🔍 Auditoría Pedagógica y de Formato (RubricAI)', ['class' => 'rubricai-title']);
        echo html_writer::tag('p', 
            'Compara la alineación de todas las actividades y recursos del curso contra una rúbrica utilizando agentes inteligentes.',
            ['class' => 'rubricai-desc', 'style' => 'color: #aaa; margin-bottom: 20px;']
        );

        // Check if there are error messages
        $error = optional_param('error', '', PARAM_TEXT);
        if ($error) {
            $msg = 'Ocurrió un error al procesar la auditoría.';
            if ($error === 'no_rubric') $msg = 'No seleccionaste una rúbrica válida.';
            if ($error === 'evaluation_failed') $msg = 'La evaluación multiagente falló. Verifica que el microservicio de Python esté operativo.';
            echo html_writer::tag('div', '❌ ' . $msg, ['class' => 'alert alert-danger', 'style' => 'padding: 15px; border-radius: 8px; margin-bottom: 20px; background: rgba(220, 53, 69, 0.1); border: 1px solid rgba(220, 53, 69, 0.2); color: #ea868f;']);
        }

        // Try to load results from database first
        $db_results = session_manager::load_audit_results($courseid);
        
        if ($db_results !== null) {
            $score = $db_results['score'];
            $holistic = $db_results['holistic'];
            $format_desc = $db_results['format'];
            $recs = $db_results['recommendations'];
            $rubric_id = $db_results['rubric_id'];
            $compared = true;
        } else {
            $compared = (session_manager::get('compare_score_' . $courseid) !== null);
            if ($compared) {
                $score = session_manager::get('compare_score_' . $courseid, 0.0);
                $holistic = session_manager::get('compare_holistic_' . $courseid, '');
                $format_desc = session_manager::get('compare_format_' . $courseid, '');
                $recs = json_decode(session_manager::get('compare_recommendations_' . $courseid, '[]'), true);
                $rubric_id = session_manager::get('compare_rubric_id_' . $courseid, '');
            }
        }

        if ($compared) {

            // Render score card
            echo html_writer::start_tag('div', ['class' => 'rubricai-results-dashboard', 'style' => 'display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 30px;']);
            
            // Score circle card
            $score_color = '#dc3545'; // red
            if ($score >= 80) $score_color = '#198754'; // green
            else if ($score >= 50) $score_color = '#ffc107'; // yellow
            
            echo html_writer::start_tag('div', ['class' => 'rubricai-score-card', 'style' => 'background: rgba(255,255,255,0.03); border-radius: 12px; padding: 20px; text-align: center; border: 1px solid rgba(255,255,255,0.08); display:flex; flex-direction:column; align-items:center; justify-content:center;']);
            echo html_writer::tag('div', 'Puntuación de Alineación', ['style' => 'font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #aaa; margin-bottom: 10px;']);
            echo html_writer::tag('div', number_format($score, 1) . '%', ['style' => 'font-size: 48px; font-weight: 800; color: ' . $score_color . '; text-shadow: 0 0 10px ' . $score_color . '33;']);
            echo html_writer::tag('div', 'Rúbrica: ' . $rubric_id, ['style' => 'font-size: 11px; color: #888; margin-top: 10px;']);
            
            // Recalculate button
            $recalc_url = new moodle_url($PAGE->url, ['step' => 8, 'action' => 'compare', 'recalc' => 1]);
            echo html_writer::link($recalc_url->out(false), '🔄 Repetir Auditoría', ['class' => 'rubricai-btn', 'style' => 'font-size: 11px; margin-top: 15px; padding: 5px 12px; background: rgba(255,255,255,0.1); color: #fff; border-radius: 6px; text-decoration:none;']);
            echo html_writer::end_tag('div');

            // Quick details card
            echo html_writer::start_tag('div', ['class' => 'rubricai-summary-card', 'style' => 'background: rgba(255,255,255,0.03); border-radius: 12px; padding: 20px; border: 1px solid rgba(255,255,255,0.08);']);
            echo html_writer::tag('h3', '✨ Resumen de Auditoría', ['style' => 'margin-top:0; color:#fff; font-size:16px; margin-bottom:15px;']);
            
            echo html_writer::tag('div', '<strong>Alineación Holística:</strong> ' . nl2br(htmlspecialchars($holistic)), ['style' => 'font-size:13px; color:#ddd; margin-bottom:15px; line-height:1.4;']);
            echo html_writer::tag('div', '<strong>Estado de Formato y Estructura:</strong> ' . nl2br(htmlspecialchars($format_desc)), ['style' => 'font-size:13px; color:#ddd; line-height:1.4;']);
            echo html_writer::end_tag('div');
            
            echo html_writer::end_tag('div');

            // Action Plan / Recommendations table
            echo html_writer::tag('h3', '📋 Plan de Acción Recomendado (Qué cambiar)', ['style' => 'color:#fff; font-size:18px; margin-top:30px; margin-bottom:15px;']);
            
            if (empty($recs)) {
                echo html_writer::tag('div', '✅ ¡Excelente! Los agentes no encontraron problemas de alineación o formato en este curso.', ['class' => 'alert alert-success', 'style' => 'padding: 15px; background: rgba(25, 135, 84, 0.1); color: #75b798; border: 1px solid rgba(25, 135, 84, 0.2); border-radius: 8px;']);
            } else {
                echo html_writer::start_tag('div', ['style' => 'overflow-x:auto;']);
                echo html_writer::start_tag('table', ['class' => 'table', 'style' => 'width:100%; border-collapse:collapse; background:rgba(255,255,255,0.02); border-radius:8px; overflow:hidden; border:1px solid rgba(255,255,255,0.08);']);
                
                // Header
                echo html_writer::start_tag('tr', ['style' => 'background:rgba(255,255,255,0.05); border-bottom:1px solid rgba(255,255,255,0.08); text-align:left;']);
                echo html_writer::tag('th', 'Elemento', ['style' => 'padding:12px; color:#fff; font-size:13px;']);
                echo html_writer::tag('th', 'Tipo', ['style' => 'padding:12px; color:#fff; font-size:13px; width:100px;']);
                echo html_writer::tag('th', 'Problema Encontrado', ['style' => 'padding:12px; color:#fff; font-size:13px;']);
                echo html_writer::tag('th', 'Acción de Cambio en Moodle', ['style' => 'padding:12px; color:#fff; font-size:13px; color:#ffc107;']);
                echo html_writer::end_tag('tr');

                // Rows
                foreach ($recs as $idx => $r) {
                    $row_bg = ($idx % 2 === 0) ? 'rgba(0,0,0,0.1)' : 'rgba(255,255,255,0.01)';
                    $type_badge = ($r['type'] === 'format') ? 
                        '<span style="background:rgba(0,123,255,0.15); color:#66b2ff; border:1px solid rgba(0,123,255,0.3); padding:2px 6px; border-radius:4px; font-size:10px;">Formato</span>' : 
                        '<span style="background:rgba(111,66,193,0.15); color:#b19ffb; border:1px solid rgba(111,66,193,0.3); padding:2px 6px; border-radius:4px; font-size:10px;">Pedagógico</span>';
                    
                    echo html_writer::start_tag('tr', ['style' => "background:{$row_bg}; border-bottom:1px solid rgba(255,255,255,0.05);"]);
                    echo html_writer::tag('td', htmlspecialchars($r['element']), ['style' => 'padding:12px; font-size:13px; font-weight:600; color:#eee;']);
                    echo html_writer::tag('td', $type_badge, ['style' => 'padding:12px; font-size:13px;']);
                    echo html_writer::tag('td', htmlspecialchars($r['issue']), ['style' => 'padding:12px; font-size:13px; color:#ccc; line-height:1.3;']);
                    echo html_writer::tag('td', htmlspecialchars($r['change']), ['style' => 'padding:12px; font-size:13px; color:#fff; font-weight:500; background:rgba(255,193,7,0.02); border-left:2px solid #ffc107; line-height:1.3;']);
                    echo html_writer::end_tag('tr');
                }
                echo html_writer::end_tag('table');
                echo html_writer::end_tag('div');
            }
        } else {
            // Render Compare Trigger Box
            echo html_writer::start_tag('div', ['class' => 'rubricai-compare-box', 'style' => 'margin: 20px 0; padding: 25px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px solid rgba(255,255,255,0.06);']);
            
            if (empty($rubrics)) {
                echo html_writer::tag('div', '⚠️ No se encontraron rúbricas registradas en Neo4j. Por favor utiliza la interfaz de Astro para registrar al menos una rúbrica en la base de datos de grafos.', [
                    'class' => 'alert alert-warning',
                    'style' => 'margin-bottom: 20px; padding: 15px; border-radius: 8px; background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.2); color: #ffda6a;'
                ]);
            } else {
                echo html_writer::start_tag('form', [
                    'id' => 'rubricai-compare-form', 
                    'method' => 'POST', 
                    'action' => new moodle_url('/local/rubricai/index.php', ['action' => 'run_compare', 'id' => $courseid])
                ]);
                
                echo html_writer::tag('label', 'Selecciona la Rúbrica de Referencia para la comparación:', ['style' => 'display:block; margin-bottom:10px; font-weight:600; color:#fff; font-size:14px;']);
                echo html_writer::start_tag('select', ['name' => 'rubric_id', 'id' => 'rubric-selector', 'style' => 'width:100%; padding:12px; border-radius:8px; background:#202026; color:#fff; border:1px solid rgba(255,255,255,0.1); margin-bottom:20px; font-size:14px;']);
                foreach ($rubrics as $rub) {
                    echo html_writer::tag('option', $rub['title'] . ' (' . $rub['id'] . ')', ['value' => $rub['id']]);
                }
                echo html_writer::end_tag('select');
                
                echo html_writer::tag('button', '🚀 Iniciar Auditoría Multi-Agente', [
                    'type' => 'submit',
                    'class' => 'rubricai-btn rubricai-btn-primary',
                    'style' => 'width:100%; padding:14px; font-size:15px; font-weight:bold; border-radius:8px; cursor:pointer; background:#6c63ff; border:none; color:#fff; transition: background 0.2s;'
                ]);
                
                echo html_writer::end_tag('form');
            }
            
            echo html_writer::end_tag('div');
        }

        // Render external link to Astro Dashboard
        $astro_url = "http://localhost:4321/evaluate?course_id={$courseid}&course_name=" . urlencode($course_name);
        
        echo html_writer::start_tag('div', ['class' => 'rubricai-astro-box', 'style' => 'margin-top: 30px; text-align:center; padding: 25px; background: linear-gradient(135deg, rgba(108, 99, 255, 0.08), rgba(255, 94, 58, 0.08)); border-radius: 12px; border: 1px solid rgba(108, 99, 255, 0.15);']);
        echo html_writer::tag('h3', '✨ Panel Premium de RubricAI (Astro)', ['style' => 'margin-top:0; margin-bottom:10px; color:#8881ff; font-weight:bold; font-size:16px;']);
        echo html_writer::tag('p', 'Accede a la interfaz interactiva para subir rúbricas, visualizar la ontología pedagógica en grafos interactivos 2D/3D y gestionar tus reportes de auditoría.', ['style' => 'font-size:13px; color:#aaa; margin-bottom:20px; line-height:1.4;']);
        
        echo html_writer::link(
            $astro_url,
            'Abrir Dashboard RubricAI 🔗',
            ['class' => 'rubricai-btn', 'target' => '_blank', 'style' => 'background:#ff5e3a; color:#fff; font-weight:bold; border-radius:8px; padding:10px 20px; text-decoration:none; display:inline-block; border:none; box-shadow: 0 4px 15px rgba(255, 94, 58, 0.2);']
        );
        echo html_writer::end_tag('div');

        // Reset recalculation param if requested
        if (optional_param('recalc', 0, PARAM_INT)) {
            session_manager::clear_audit_results($courseid);
            session_manager::unset_key('compare_score_' . $courseid);
            session_manager::unset_key('compare_holistic_' . $courseid);
            session_manager::unset_key('compare_format_' . $courseid);
            session_manager::unset_key('compare_recommendations_' . $courseid);
            session_manager::unset_key('compare_rubric_id_' . $courseid);
            redirect(new moodle_url($PAGE->url, ['step' => 8, 'action' => 'compare']));
        }

        // Navigation bar
        step_renderer::render_nav(
            8,
            new moodle_url($PAGE->url, ['step' => 1, 'action' => 'lib']),
            null,
            ''
        );
    }
}
