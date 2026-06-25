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
 * Admin settings for local_rubricai.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    // --- Connection settings ---
    $settings->add(new admin_setting_heading(
        'local_rubricai/connectionheading',
        get_string('settings_connection', 'local_rubricai'),
        get_string('settings_connection_desc', 'local_rubricai')
    ));

    $settings->add(new admin_setting_configtext(
        'local_rubricai/service_url',
        get_string('service_url', 'local_rubricai'),
        get_string('service_url_desc', 'local_rubricai'),
        'http://localhost:8000',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configselect(
        'local_rubricai/llm_provider',
        get_string('llm_provider', 'local_rubricai'),
        get_string('llm_provider_desc', 'local_rubricai'),
        'openai',
        [
            'openai' => 'OpenAI',
            'google' => 'Google Gemini',
            'ollama' => 'Ollama (local)',
        ]
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_rubricai/api_key',
        get_string('api_key', 'local_rubricai'),
        get_string('api_key_desc', 'local_rubricai'),
        ''
    ));

    // --- Rubrics management link ---
    $settings->add(new admin_setting_heading(
        'local_rubricai/rubricsheading',
        get_string('rubrics', 'local_rubricai'),
        ''
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_rubricai_rubrics',
        get_string('manage_rubrics', 'local_rubricai'),
        new moodle_url('/local/rubricai/admin/rubrics.php'),
        'moodle/site:config'
    ));
}
