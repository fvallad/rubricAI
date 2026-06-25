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

/**
 * Strings for component 'local_rubricai', language 'es'.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Core
$string['pluginname']        = 'RubricAI — Asistente de Auditoría Pedagógica con IA';
$string['introduction']      = 'Bienvenido a RubricAI, tu asistente de evaluación pedagógica.';
$string['coursereport']      = 'Informe resumen del curso';
$string['section']           = 'Sección';
$string['activity']          = 'Actividad';

// Capabilities
$string['rubricai:use']       = 'Usar RubricAI (construir biblioteca y ejecutar auditorías)';
$string['rubricai:viewaudit'] = 'Ver resultados de auditoría RubricAI';

// Admin settings
$string['settings_connection']      = 'Conexión al servicio Python';
$string['settings_connection_desc'] = 'Configura la conexión al backend Python de RubricAI.';
$string['service_url']              = 'URL del servicio';
$string['service_url_desc']         = 'URL base del backend Python de RubricAI (ej: http://localhost:8000). Sin barra al final.';
$string['llm_provider']             = 'Proveedor LLM';
$string['llm_provider_desc']        = 'Proveedor del modelo de lenguaje utilizado por el backend.';
$string['api_key']                  = 'API Key';
$string['api_key_desc']             = 'Clave API del proveedor LLM seleccionado. Se almacena encriptada. Dejar vacío para Ollama.';

// Rubrics admin
$string['rubrics']               = 'Rúbricas';
$string['manage_rubrics']        = 'Gestionar rúbricas';
$string['rubric_list_title']     = 'Gestión de rúbricas';
$string['rubric_create']         = 'Crear rúbrica';
$string['rubric_edit']           = 'Editar rúbrica';
$string['rubric_delete']         = 'Eliminar rúbrica';
$string['rubric_import']         = 'Importar desde JSON';
$string['rubric_import_generic'] = 'Importar rúbrica de ejemplo';
$string['no_rubrics']            = 'No hay rúbricas. Crea una nueva o importa la rúbrica de ejemplo.';
$string['rubric_title_field']    = 'Título';
$string['rubric_desc_field']     = 'Descripción';
$string['rubric_criteria']       = 'Criterios';
$string['criterion_name']        = 'Nombre del criterio';
$string['criterion_description'] = 'Descripción';
$string['criterion_weight']      = 'Peso (%)';
$string['criterion_dimension']   = 'Dimensión pedagógica';
$string['add_criterion']         = 'Agregar criterio';
$string['remove_criterion']      = 'Quitar';
$string['save_rubric']           = 'Guardar rúbrica';
$string['cancel']                = 'Cancelar';
$string['rubric_saved']          = 'Rúbrica guardada exitosamente.';
$string['rubric_deleted']        = 'Rúbrica eliminada.';
$string['rubric_imported']       = 'Rúbrica importada exitosamente.';
$string['rubric_not_found']      = 'Rúbrica no encontrada.';
$string['rubric_service_error']  = 'No se pudo conectar al servicio RubricAI. Revisá la URL del servicio en configuración.';
$string['delete_confirm']        = '¿Estás seguro de que querés eliminar esta rúbrica? Esta acción no se puede deshacer.';
$string['import_json_label']     = 'Archivo JSON';
$string['import_json_desc']      = 'Subí una rúbrica en formato JSON (exportada desde RubricAI).';

// Privacy
$string['privacy:metadata'] = 'RubricAI almacena resultados de auditoría a nivel de curso (puntajes y recomendaciones) en la tabla de configuración de Moodle, indexados por ID de curso. No se almacenan datos personales de usuarios.';
