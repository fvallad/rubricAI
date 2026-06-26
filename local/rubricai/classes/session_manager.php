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

namespace local_rubricai;

defined('MOODLE_INTERNAL') || die();

/**
 * Session state and audit result persistence.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_manager {

    /** Small parameters persisted from URL → SESSION on every request. */
    private const PARAMS = [
        'use_moodle', 'path', 'ingested', 'sum_ok',
        'd1', 'd2', 'd2_json', 'd3', 'd4',
        'sel_sug', 'instrument', 'exported', 'cmid', 'feedback', 'max_grade',
        'correction_instrument'
    ];

    /** Dimensions whose change triggers cascading invalidation. */
    private const DIMENSIONS = ['use_moodle', 'path', 'd1', 'd2', 'd2_json', 'd3', 'd4'];

    /** Keys cleared when any dimension changes. */
    private const DOWNSTREAM = ['s_sugs', 'sel_sug', 'instrument', 'inst_content', 'rubric_content'];

    /** Large-content keys captured separately (PARAM_RAW, non-empty check). */
    private const LARGE_CONTENT = ['s_sugs', 'inst_content', 'rubric_content', 'correction_content'];

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /**
     * Ensure the session namespace exists. Call once per request.
     */
    public static function init(): void {
        global $SESSION;
        if (!isset($SESSION->rubricai)) {
            $SESSION->rubricai = new \stdClass();
        }
    }

    /**
     * Read URL parameters and persist them into the session.
     * Handles cascading invalidation when pedagogical dimensions change
     * and special unlock/clear flows.
     */
    public static function sync_from_request(): void {
        global $SESSION;

        // --- 1. Detect if any dimension changed vs session ---
        $dim_changed = false;
        foreach (self::DIMENSIONS as $dim) {
            $type = ($dim === 'd2' || $dim === 'd2_json') ? PARAM_RAW : PARAM_TEXT;
            $val  = optional_param($dim, null, $type);
            if ($val !== null && isset($SESSION->rubricai->$dim)) {
                $val_clean  = trim(str_replace("\r\n", "\n", (string)$val));
                $sess_clean = trim(str_replace("\r\n", "\n", (string)$SESSION->rubricai->$dim));
                if ($val_clean !== '' && $val_clean !== $sess_clean) {
                    $dim_changed = true;
                }
            }
        }

        // --- 2. Unlock mechanism ---
        $unlock = optional_param('unlock', 0, PARAM_INT);
        if ($unlock) {
            $dim_changed = true;
            if ($unlock == 2) {
                // Hard reset: clear all four pedagogical dimensions
                foreach (['d1', 'd2', 'd3', 'd4'] as $d) {
                    unset($SESSION->rubricai->$d);
                }
            }
        }

        // --- 3. Cascade: wipe downstream if dimensions changed ---
        if ($dim_changed) {
            foreach (self::DOWNSTREAM as $key) {
                unset($SESSION->rubricai->$key);
            }
        }

        // --- 4. Instrument-change invalidation ---
        $inst_val = optional_param('instrument', null, PARAM_TEXT);
        if ($inst_val !== null
            && isset($SESSION->rubricai->instrument)
            && $SESSION->rubricai->instrument !== $inst_val
        ) {
            unset($SESSION->rubricai->inst_content);
            unset($SESSION->rubricai->rubric_content);
        }

        // --- 5. Persist small URL params → SESSION ---
        foreach (self::PARAMS as $p) {
            $type = ($p === 'd2' || $p === 'd2_json') ? PARAM_RAW : PARAM_TEXT;
            $val  = optional_param($p, null, $type);
            if ($val !== null) {
                $SESSION->rubricai->$p = $val;
            }
        }

        // --- 6. Capture large content (only if non-empty) ---
        foreach (self::LARGE_CONTENT as $key) {
            $val = optional_param($key, '', PARAM_RAW);
            if ($val) {
                $SESSION->rubricai->$key = $val;
            }
        }
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    /**
     * Get a session value by key, with an optional default.
     */
    public static function get(string $key, $default = null) {
        global $SESSION;
        return $SESSION->rubricai->$key ?? $default;
    }

    /**
     * Set a session value.
     */
    public static function set(string $key, $value): void {
        global $SESSION;
        $SESSION->rubricai->$key = $value;
    }

    /**
     * Unset a session key.
     */
    public static function unset_key(string $key): void {
        global $SESSION;
        unset($SESSION->rubricai->$key);
    }

    /**
     * Returns true if ANY of the given keys hold a non-empty value.
     */
    public static function has_any(string ...$keys): bool {
        global $SESSION;
        foreach ($keys as $key) {
            if (!empty($SESSION->rubricai->$key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether the rubricai namespace exists at all.
     */
    public static function exists(): bool {
        global $SESSION;
        return isset($SESSION->rubricai);
    }

    /**
     * Destroy the entire rubricai session namespace.
     */
    public static function clear(): void {
        global $SESSION;
        unset($SESSION->rubricai);
    }

    /**
     * Saves course-specific audit results to the Moodle database (config_plugins table).
     */
    public static function save_audit_results(int $course_id, float $score, string $holistic, string $format, array $recommendations, string $rubric_id, int $generated_at = 0): void {
        set_config('compare_score_' . $course_id, $score, 'local_rubricai');
        set_config('compare_holistic_' . $course_id, $holistic, 'local_rubricai');
        set_config('compare_format_' . $course_id, $format, 'local_rubricai');
        set_config('compare_recommendations_' . $course_id, json_encode($recommendations), 'local_rubricai');
        set_config('compare_rubric_id_' . $course_id, $rubric_id, 'local_rubricai');
        set_config('compare_generated_at_' . $course_id, $generated_at ?: time(), 'local_rubricai');
    }

    /**
     * Loads course-specific audit results from the Moodle database (config_plugins table).
     *
     * @return array|null Null if no audit results exist for the course.
     */
    public static function load_audit_results(int $course_id): ?array {
        $score = get_config('local_rubricai', 'compare_score_' . $course_id);
        if ($score === false || $score === null) {
            return null;
        }
        return [
            'score' => (float)$score,
            'holistic' => get_config('local_rubricai', 'compare_holistic_' . $course_id) ?: '',
            'format' => get_config('local_rubricai', 'compare_format_' . $course_id) ?: '',
            'recommendations' => json_decode(get_config('local_rubricai', 'compare_recommendations_' . $course_id) ?: '[]', true),
            'rubric_id' => get_config('local_rubricai', 'compare_rubric_id_' . $course_id) ?: '',
            'generated_at' => (int)(get_config('local_rubricai', 'compare_generated_at_' . $course_id) ?: 0),
        ];
    }

    /**
     * Clears course-specific audit results from the Moodle database.
     */
    public static function clear_audit_results(int $course_id): void {
        unset_config('compare_score_' . $course_id, 'local_rubricai');
        unset_config('compare_holistic_' . $course_id, 'local_rubricai');
        unset_config('compare_format_' . $course_id, 'local_rubricai');
        unset_config('compare_recommendations_' . $course_id, 'local_rubricai');
        unset_config('compare_rubric_id_' . $course_id, 'local_rubricai');
        unset_config('compare_generated_at_' . $course_id, 'local_rubricai');
    }
}
