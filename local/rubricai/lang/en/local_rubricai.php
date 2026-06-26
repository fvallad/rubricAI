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
 * Language strings for local_rubricai (English).
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Core
$string['pluginname']        = 'RubricAI — AI-Powered Pedagogical Audit Assistant';
$string['introduction']      = 'Welcome to RubricAI, your pedagogical evaluation assistant.';
$string['coursereport']      = 'Course Summary Report';
$string['section']           = 'Section';
$string['activity']          = 'Activity';

// Capabilities
$string['rubricai:use']       = 'Use RubricAI (build library and run audits)';
$string['rubricai:viewaudit'] = 'View RubricAI audit results';

// Admin settings
$string['settings_connection']      = 'Python Service Connection';
$string['settings_connection_desc'] = 'Configure the connection to the RubricAI Python backend service.';
$string['service_url']              = 'Service URL';
$string['service_url_desc']         = 'Base URL of the RubricAI Python backend (e.g. http://localhost:8000). No trailing slash.';
$string['llm_provider']             = 'LLM Provider';
$string['llm_provider_desc']        = 'Language model provider used by the backend service.';
$string['api_key']                  = 'API Key';
$string['api_key_desc']             = 'API key for the selected LLM provider. Stored encrypted. Leave blank for Ollama.';

// Rubrics admin
$string['rubrics']               = 'Rubrics';
$string['manage_rubrics']        = 'Manage Rubrics';
$string['rubric_list_title']     = 'Rubric Management';
$string['rubric_create']         = 'Create Rubric';
$string['rubric_edit']           = 'Edit Rubric';
$string['rubric_delete']         = 'Delete Rubric';
$string['rubric_import']         = 'Import from JSON';
$string['rubric_import_generic'] = 'Import Sample Rubric';
$string['no_rubrics']            = 'No rubrics found. Create one or import the sample rubric.';
$string['rubric_title_field']    = 'Title';
$string['rubric_desc_field']     = 'Description';
$string['rubric_criteria']       = 'Criteria';
$string['criterion_name']        = 'Criterion Name';
$string['criterion_description'] = 'Description';
$string['criterion_weight']      = 'Weight (%)';
$string['criterion_dimension']   = 'Pedagogical Dimension';
$string['add_criterion']         = 'Add Criterion';
$string['remove_criterion']      = 'Remove';
$string['save_rubric']           = 'Save Rubric';
$string['cancel']                = 'Cancel';
$string['rubric_saved']          = 'Rubric saved successfully.';
$string['rubric_deleted']        = 'Rubric deleted.';
$string['rubric_imported']       = 'Rubric imported successfully.';
$string['rubric_not_found']      = 'Rubric not found.';
$string['rubric_service_error']  = 'Could not connect to the RubricAI service. Check the Service URL in admin settings.';
$string['delete_confirm']        = 'Are you sure you want to delete this rubric? This cannot be undone.';
$string['import_json_label']     = 'JSON File';
$string['import_json_desc']      = 'Upload a rubric in JSON format (exported from RubricAI).';

// Privacy
$string['privacy:metadata'] = 'RubricAI stores course-level audit results (scores and recommendations) in the Moodle config table, keyed by course ID. No personal user data is stored.';
