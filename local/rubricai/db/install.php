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
 * Post-install hook for local_rubricai.
 *
 * Attempts to import the generic rubric if the backend is already configured.
 * Silently skips if the backend is unavailable — admin can import later via
 * the Rubrics management page.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_rubricai_install() {
    $fixture_path = __DIR__ . '/fixtures/rubric_generic.json';
    if (!file_exists($fixture_path)) {
        return true;
    }

    $service_url = get_config('local_rubricai', 'service_url');
    if (!$service_url) {
        return true;
    }

    $rubric = json_decode(file_get_contents($fixture_path), true);
    if (!is_array($rubric)) {
        return true;
    }

    try {
        \local_rubricai\rag_client::save_rubric($rubric);
    } catch (\Throwable $e) {
        // Backend unavailable at install time — admin can import from admin settings later.
        debugging('RubricAI: could not import generic rubric at install: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    return true;
}
