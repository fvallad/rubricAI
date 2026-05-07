<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * Centralizes all session state management for the AreteIA pedagogical workflow.
 *
 * Responsibilities:
 * - Initialize the SESSION->areteia namespace
 * - Sync URL parameters into session (small params + large content)
 * - Cascading invalidation: when a dimension changes, all downstream
 *   generated content (suggestions, instrument, rubric) is cleared
 * - Convenience getters/setters so steps never touch $SESSION directly
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
        if (!isset($SESSION->areteia)) {
            $SESSION->areteia = new \stdClass();
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
            if ($val !== null && isset($SESSION->areteia->$dim)) {
                $val_clean  = trim(str_replace("\r\n", "\n", (string)$val));
                $sess_clean = trim(str_replace("\r\n", "\n", (string)$SESSION->areteia->$dim));
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
                    unset($SESSION->areteia->$d);
                }
            }
        }

        // --- 3. Cascade: wipe downstream if dimensions changed ---
        if ($dim_changed) {
            foreach (self::DOWNSTREAM as $key) {
                unset($SESSION->areteia->$key);
            }
        }

        // --- 4. Instrument-change invalidation ---
        $inst_val = optional_param('instrument', null, PARAM_TEXT);
        if ($inst_val !== null
            && isset($SESSION->areteia->instrument)
            && $SESSION->areteia->instrument !== $inst_val
        ) {
            unset($SESSION->areteia->inst_content);
            unset($SESSION->areteia->rubric_content);
        }

        // --- 5. Persist small URL params → SESSION ---
        foreach (self::PARAMS as $p) {
            $type = ($p === 'd2' || $p === 'd2_json') ? PARAM_RAW : PARAM_TEXT;
            $val  = optional_param($p, null, $type);
            if ($val !== null) {
                $SESSION->areteia->$p = $val;
            }
        }

        // --- 6. Capture large content (only if non-empty) ---
        foreach (self::LARGE_CONTENT as $key) {
            $val = optional_param($key, '', PARAM_RAW);
            if ($val) {
                $SESSION->areteia->$key = $val;
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
        return $SESSION->areteia->$key ?? $default;
    }

    /**
     * Set a session value.
     */
    public static function set(string $key, $value): void {
        global $SESSION;
        $SESSION->areteia->$key = $value;
    }

    /**
     * Unset a session key.
     */
    public static function unset_key(string $key): void {
        global $SESSION;
        unset($SESSION->areteia->$key);
    }

    /**
     * Returns true if ANY of the given keys hold a non-empty value.
     */
    public static function has_any(string ...$keys): bool {
        global $SESSION;
        foreach ($keys as $key) {
            if (!empty($SESSION->areteia->$key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether the areteia namespace exists at all.
     */
    public static function exists(): bool {
        global $SESSION;
        return isset($SESSION->areteia);
    }

    /**
     * Destroy the entire areteia session namespace.
     */
    public static function clear(): void {
        global $SESSION;
        unset($SESSION->areteia);
    }
}
