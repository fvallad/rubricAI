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
 * Library functions for local_rubricai.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add nodes to the course navigation.
 *
 * @param navigation_node $navigation The course navigation node
 * @param stdClass $course The course object
 * @param context_course $context The course context
 */
function local_rubricai_extend_navigation_course(navigation_node $navigation, $course, $context) {
    if (has_any_capability(['local/rubricai:use', 'local/rubricai:viewaudit'], $context)) {
        $url = new moodle_url('/local/rubricai/index.php', ['id' => $course->id]);
        $node = navigation_node::create(
            'RubricAI',
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'local_rubricai',
            new pix_icon('i/menu', '')
        );
        $node->set_show_in_secondary_navigation(true);
        $navigation->add_node($node);
    }
}

/**
 * For Moodle 4.0+ secondary navigation (the tabs at the top)
 */
function local_rubricai_extend_navigation_user(navigation_node $navigation, $user, $context) {
    // This can be used to add items to the user profile if needed.
}
