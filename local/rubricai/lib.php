<?php
/**
 * @package    local_rubricai
 * @copyright  2026 Vicente Astorga
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
