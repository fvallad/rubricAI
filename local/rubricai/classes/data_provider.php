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
 * Course data provider.
 *
 * @package    local_rubricai
 * @copyright  2024 Vicente Astorga (areteIA original)
 * @copyright  2026 Fernando Valladares, Diego Racero
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_provider {
 
    /** Allowed file extensions for RAG processing */
    private const ALLOWED_EXTS = ['pdf', 'ppt', 'pptx', 'docx', 'doc', 'txt'];

    /** Allowed module types for building the library */
    private const RESOURCE_MODULES = ['resource', 'folder', 'page', 'url', 'book', 'label', 'imscp', 'assign', 'forum', 'quiz'];

    /**
     * Get a summary of the course content
     * 
     * @param int $courseid
     * @return array
     */
    public static function get_course_summary($courseid) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course);
        
        $data = [
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'summary' => self::clean_html($course->summary),
            'sections' => []
        ];

        foreach ($modinfo->get_section_info_all() as $section) {
            if ($section->uservisible && ($section->summary || !empty($modinfo->sections[$section->section]))) {
                $sectiondata = [
                    'name' => self::get_section_name_clean($course, $section),
                    'summary' => self::clean_html($section->summary),
                    'activities' => []
                ];

                if (!empty($modinfo->sections[$section->section])) {
                    foreach ($modinfo->sections[$section->section] as $cmid) {
                        $cm = $modinfo->get_cm($cmid);
                        if ($cm->uservisible) {
                            $sectiondata['activities'][] = [
                                'name' => $cm->name,
                                'type' => $cm->modname,
                                'description' => self::clean_html($cm->content) // Simplificado
                            ];
                        }
                    }
                }
                $data['sections'][] = $sectiondata;
            }
        }

        return $data;
    }

    /**
     * Get all files associated with the course
     * 
     * @param int $courseid
     * @return array
     */
    public static function get_course_files($courseid, $extract_to_sync_dir = false) {
        global $CFG, $DB;
        $fs = get_file_storage();
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course);
        $res = [];
        
        $base_sync_dir = '';
        if ($extract_to_sync_dir) {
            self::delete_sync_dir($courseid);
            $base_sync_dir = $CFG->dataroot . '/rubricai_sync/course_' . $courseid;
            if (!file_exists($base_sync_dir)) {
                mkdir($base_sync_dir, 0777, true);
            }
        }

        // 1. Files in the course context itself (Intro, general files)
        $course_context = \context_course::instance($courseid);
        $course_files = $fs->get_area_files($course_context->id, 'course', 'section');
        foreach ($course_files as $file) {
            if (!$file->is_directory()) {
                $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                if (!in_array($ext, self::ALLOWED_EXTS)) {
                    continue;
                }
                
                $section_folder = $base_sync_dir . '/0_General';
                $reldata = [
                    'filename' => $file->get_filename(),
                    'mimetype' => $file->get_mimetype(),
                    'size' => $file->get_filesize(),
                    'section' => 'General'
                ];
                if ($extract_to_sync_dir) {
                    if (!file_exists($section_folder)) mkdir($section_folder, 0777, true);
                    $localpath = $section_folder . '/' . $file->get_filename();
                    if (file_exists($localpath)) $localpath = $section_folder . '/' . $file->get_contenthash() . '_' . $file->get_filename();
                    if (!file_exists($localpath)) $file->copy_content_to($localpath);
                    $reldata['localpath'] = $localpath;
                }
                $res[] = $reldata;
            }
        }

        // 2. Traverse sections and modules
        foreach ($modinfo->get_section_info_all() as $section) {
            $section_name = clean_param(self::get_section_name_clean($course, $section), PARAM_FILE);
            $section_folder_name = $section->section . '_' . ($section_name ?: 'Section');
            
                foreach ($modinfo->sections[$section->section] as $cmid) {
                    $cm = $modinfo->get_cm($cmid);
                    if (!$cm->uservisible) continue;
                    if (!in_array($cm->modname, self::RESOURCE_MODULES)) continue;

                    $activity_name = clean_param($cm->name, PARAM_FILE);
                    $activity_folder_name = $cm->id . '_' . ($activity_name ?: 'Activity');

                    // A. Export virtual text file representing activity instructions/questions
                    $activity_text = self::get_activity_text($cm);
                    if (!empty($activity_text)) {
                        $v_filename = clean_param($cm->name, PARAM_FILE);
                        $v_filename = trim($v_filename, ' .-_');
                        if (empty($v_filename)) {
                            $v_filename = 'actividad_' . $cm->id;
                        }
                        $v_filename .= '.txt';

                        $reldata = [
                            'filename' => $v_filename,
                            'mimetype' => 'text/plain',
                            'size'     => strlen($activity_text),
                            'section'  => $section_name,
                            'module'   => $cm->name,
                            'modname'  => $cm->modname
                        ];

                        if ($extract_to_sync_dir) {
                            $target_dir = $base_sync_dir . '/' . $section_folder_name . '/' . $activity_folder_name;
                            if (!file_exists($target_dir)) {
                                mkdir($target_dir, 0777, true);
                            }
                            $localpath = $target_dir . '/' . $v_filename;
                            try {
                                file_put_contents($localpath, $activity_text);
                            } catch (\Exception $e) {
                                file_put_contents($CFG->dataroot . '/rubricai_sync/debug.txt', "Error writing virtual file for " . $cm->name . ": " . $e->getMessage() . "\n", FILE_APPEND);
                            }
                            $reldata['localpath'] = $localpath;
                        }
                        $res[] = $reldata;
                    }

                    // B. Export actual physical files in this module's context
                    $mod_context = \context_module::instance($cm->id);
                    
                    // Fetch all files associated with this module's context, regardless of component/filearea
                    $module_files = [];
                    $filerecords = $DB->get_records('files', ['contextid' => $mod_context->id]);
                    foreach ($filerecords as $r) {
                        if ($r->filename !== '.') {
                            try {
                                $module_files[] = $fs->get_file_instance($r);
                            } catch (\Exception $e) {
                                // Ignore missing file data
                            }
                        }
                    }
                    
                    foreach ($module_files as $file) {
                        if ($file->is_directory()) continue;
                        // Avoid system/temp files
                        if ($file->get_component() == 'user' || $file->get_filearea() == 'draft') continue;
                        // Only allow teacher/module files, avoid student submissions or feedback files
                        if (strpos($file->get_component(), 'mod_') !== 0) continue;

                        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                        if (!in_array($ext, self::ALLOWED_EXTS)) {
                            continue;
                        }

                        $reldata = [
                            'filename' => $file->get_filename(),
                            'mimetype' => $file->get_mimetype(),
                            'size' => $file->get_filesize(),
                            'section' => $section_name,
                            'module' => $cm->name,
                            'modname' => $cm->modname
                        ];

                        if ($extract_to_sync_dir) {
                            $target_dir = $base_sync_dir . '/' . $section_folder_name . '/' . $activity_folder_name;
                            if (!file_exists($target_dir)) {
                                mkdir($target_dir, 0777, true);
                            }
                            $localpath = $target_dir . '/' . $file->get_filename();
                            if (file_exists($localpath)) {
                                $localpath = $target_dir . '/' . $file->get_contenthash() . '_' . $file->get_filename();
                            }
                            if (!file_exists($localpath)) {
                                try {
                                    $file->copy_content_to($localpath);
                                } catch (\Exception $e) {
                                    file_put_contents($CFG->dataroot . '/rubricai_sync/debug.txt', "Error copying " . $file->get_filename() . ": " . $e->getMessage() . "\n", FILE_APPEND);
                                }
                            }
                            $reldata['localpath'] = $localpath;
                        }
                        $res[] = $reldata;
                    }
                }
        }
        
        return $res;
    }

    /**
     * Get a hierarchical tree of course materials (Course > Sections > Activities > Files).
     *
     * @param int $courseid
     * @return array
     */
    public static function get_course_materials_tree(int $courseid): array {
        global $DB;
        $fs = get_file_storage();
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course);

        $tree = [
            'id'       => $courseid,
            'name'     => $course->fullname,
            'type'     => 'course',
            'sections' => []
        ];

        // 1. Files in the course context itself (Intro / General files)
        $course_context = \context_course::instance($courseid);
        $course_files = $fs->get_area_files($course_context->id, 'course', 'section');
        $general_files = [];
        foreach ($course_files as $file) {
            if ($file->is_directory()) continue;
            $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTS)) continue;

            $general_files[] = [
                'id'       => $file->get_id(),
                'name'     => $file->get_filename(),
                'type'     => 'file',
                'relpath'  => '0_General/' . $file->get_filename()
            ];
        }

        if (!empty($general_files)) {
            $tree['sections'][] = [
                'id'         => 0,
                'name'       => 'Materiales generales del curso',
                'type'       => 'section',
                'activities' => [
                    [
                        'id'    => 'gen',
                        'name'  => 'Archivos intro',
                        'type'  => 'activity',
                        'files' => $general_files
                    ]
                ]
            ];
        }

        // 2. Traverse sections and activities
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible) continue;
            if (empty($modinfo->sections[$section->section])) continue;

            $section_name_raw = self::get_section_name_clean($course, $section);
            $section_name_clean = clean_param($section_name_raw, PARAM_FILE);
            $section_folder_name = $section->section . '_' . ($section_name_clean ?: 'Section');

            $section_node = [
                'id'         => $section->id,
                'name'       => $section_name_raw,
                'type'       => 'section',
                'activities' => []
            ];

            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->uservisible) continue;
                if (!in_array($cm->modname, self::RESOURCE_MODULES)) continue;

                $activity_name_clean = clean_param($cm->name, PARAM_FILE);
                $activity_folder_name = $cm->id . '_' . ($activity_name_clean ?: 'Activity');

                $activity_node = [
                    'id'    => $cm->id,
                    'name'  => $cm->name,
                    'type'  => 'activity',
                    'files' => []
                ];

                // Generate virtual file node representing the activity's details/description
                $activity_text = self::get_activity_text($cm);
                if (!empty($activity_text)) {
                    $v_filename = clean_param($cm->name, PARAM_FILE);
                    $v_filename = trim($v_filename, ' .-_');
                    if (empty($v_filename)) {
                        $v_filename = 'actividad_' . $cm->id;
                    }
                    $v_filename .= '.txt';

                    $activity_node['files'][] = [
                        'id'      => 'virtual_' . $cm->id,
                        'name'    => $v_filename,
                        'type'    => 'file',
                        'relpath' => $section_folder_name . '/' . $activity_folder_name . '/' . $v_filename
                    ];
                }

                // Use DB to get all files in this module's context, regardless of filearea
                try {
                    $mod_context = \context_module::instance($cm->id);
                    $filerecords = $DB->get_records('files', ['contextid' => $mod_context->id]);
                    
                    foreach ($filerecords as $r) {
                        try {
                            $file = $fs->get_file_instance($r);
                        } catch (\Exception $e) {
                            continue;
                        }

                        if ($file->is_directory()) continue;
                        if ($file->get_component() === 'user' || $file->get_filearea() === 'draft') continue;
                        // Only allow teacher/module files, avoid student submissions or feedback files
                        if (strpos($file->get_component(), 'mod_') !== 0) continue;

                        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                        if (!in_array($ext, self::ALLOWED_EXTS)) continue;

                        $activity_node['files'][] = [
                            'id'      => $file->get_id(),
                            'name'    => $file->get_filename(),
                            'type'    => 'file',
                            'relpath' => $section_folder_name . '/' . $activity_folder_name . '/' . $file->get_filename()
                        ];
                    }
                } catch (\Exception $e) {
                    continue; // Skip activities with context errors
                }

                if (!empty($activity_node['files'])) {
                    $section_node['activities'][] = $activity_node;
                }
            }

            if (!empty($section_node['activities'])) {
                $tree['sections'][] = $section_node;
            }
        }

        return $tree;
    }

    /**
     * Extracts ALL resources and activities in the course (with their settings and contents)
     * for evaluation against the rubric by the python microservice.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_course_full_evaluation_payload(int $courseid): array {
        global $DB;
        
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course);
        
        $payload = [
            'id' => $courseid,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'summary' => self::clean_html($course->summary),
            'sections' => []
        ];
        
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible) continue;
            
            $sectiondata = [
                'num' => (int)$section->section,
                'name' => self::get_section_name_clean($course, $section),
                'summary' => self::clean_html($section->summary),
                'activities' => []
            ];
            
            if (!empty($modinfo->sections[$section->section])) {
                foreach ($modinfo->sections[$section->section] as $cmid) {
                    $cm = $modinfo->get_cm($cmid);
                    if (!$cm->uservisible) continue;
                    if ($cm->modname === 'label') {
                        $record = $DB->get_record('label', ['id' => $cm->instance]);
                        if ($record) {
                            $act = [
                                'id' => (int)$cm->id,
                                'name' => 'Etiqueta',
                                'type' => $cm->modname,
                                'description' => $record->intro,
                                'settings' => []
                            ];
                            $sectiondata['activities'][] = $act;
                        }
                        continue;
                    }
                    
                    $act = [
                        'id' => (int)$cm->id,
                        'name' => $cm->name,
                        'type' => $cm->modname,
                        'description' => '',
                        'settings' => []
                    ];
                    
                    // Fetch details depending on module type
                    if ($cm->modname === 'assign') {
                        $record = $DB->get_record('assign', ['id' => $cm->instance]);
                        if ($record) {
                            $desc = preg_replace('/\s+/', ' ', self::clean_html($record->intro));
                            $act['description'] = strlen($desc) > 1000 ? substr($desc, 0, 1000) . '...' : $desc;
                            $act['settings'] = [
                                'duedate' => (int)$record->duedate,
                                'allowsubmissionsfromdate' => (int)$record->allowsubmissionsfromdate,
                                'grade' => (float)$record->grade,
                                'teamsubmission' => (int)$record->teamsubmission,
                            ];
                        }
                    } else if ($cm->modname === 'forum') {
                        $record = $DB->get_record('forum', ['id' => $cm->instance]);
                        if ($record) {
                            $desc = preg_replace('/\s+/', ' ', self::clean_html($record->intro));
                            $act['description'] = strlen($desc) > 1000 ? substr($desc, 0, 1000) . '...' : $desc;
                            $act['settings'] = [
                                'type' => $record->type,
                                'assessed' => (int)$record->assessed,
                                'scale' => (float)$record->scale,
                            ];
                        }
                    } else if ($cm->modname === 'quiz') {
                        $record = $DB->get_record('quiz', ['id' => $cm->instance]);
                        if ($record) {
                            $desc = preg_replace('/\s+/', ' ', self::clean_html($record->intro));
                            $act['description'] = strlen($desc) > 1000 ? substr($desc, 0, 1000) . '...' : $desc;
                            $act['settings'] = [
                                'timeopen' => (int)$record->timeopen,
                                'timeclose' => (int)$record->timeclose,
                                'timelimit' => (int)$record->timelimit,
                                'grade' => (float)$record->grade,
                                'sumgrades' => (float)$record->sumgrades,
                            ];
                            
                            // Query questions inside this quiz
                            $questions = [];
                            $has_question_refs = $DB->get_manager()->table_exists('question_references');
                            if ($has_question_refs) {
                                $sql = "SELECT q.id, q.name, q.questiontext, q.qtype, q.defaultmark
                                        FROM {quiz_slots} slot
                                        JOIN {question_references} qref ON qref.itemid = slot.id
                                        JOIN {question_bank_entries} qbe ON qbe.id = qref.questionbankentryid
                                        JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                        JOIN {question} q ON q.id = qv.questionid
                                        WHERE slot.quizid = ? AND qv.status = 'ready'";
                                $qrecords = $DB->get_records_sql($sql, [$record->id]);
                            } else {
                                $sql = "SELECT q.id, q.name, q.questiontext, q.qtype, q.defaultmark
                                        FROM {quiz_slots} slot
                                        JOIN {question} q ON q.id = slot.questionid
                                        WHERE slot.quizid = ?";
                                $qrecords = $DB->get_records_sql($sql, [$record->id]);
                            }
                            if ($qrecords) {
                                foreach ($qrecords as $qr) {
                                    $qtext = preg_replace('/\s+/', ' ', self::clean_html($qr->questiontext));
                                    $questions[] = [
                                        'id' => (int)$qr->id,
                                        'name' => $qr->name,
                                        'text' => strlen($qtext) > 500 ? substr($qtext, 0, 500) . '...' : $qtext,
                                        'type' => $qr->qtype,
                                        'points' => (float)$qr->defaultmark
                                    ];
                                }
                            }
                            $act['questions'] = $questions;
                        }
                    } else if (in_array($cm->modname, ['resource', 'page', 'url', 'book', 'folder'])) {
                        // For resource/resource modules
                        $desc = $cm->content;
                        if ($cm->modname === 'page') {
                            $record = $DB->get_record('page', ['id' => $cm->instance]);
                            if ($record) {
                                $desc .= "\n" . $record->content;
                            }
                        } else if ($cm->modname === 'url') {
                            $record = $DB->get_record('url', ['id' => $cm->instance]);
                            if ($record) {
                                $act['settings']['externalurl'] = $record->externalurl;
                            }
                        }
                        $cleaned_desc = preg_replace('/\s+/', ' ', self::clean_html($desc));
                        $act['description'] = strlen($cleaned_desc) > 1000 ? substr($cleaned_desc, 0, 1000) . '...' : $cleaned_desc;
                    }
                    
                    $sectiondata['activities'][] = $act;
                }
            }
            $payload['sections'][] = $sectiondata;
        }
        
        return $payload;
    }

    /**
     * Recursively delete the sync directory for a course.
     *
     * @param int $courseid
     */
    public static function delete_sync_dir($courseid) {
        global $CFG;
        $base_sync_dir = $CFG->dataroot . '/rubricai_sync/course_' . $courseid;
        if (file_exists($base_sync_dir)) {
            self::rrmdir($base_sync_dir);
        }
    }

    /**
     * Internal recursive directory removal helper.
     */
    private static function rrmdir($dir) {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Get a cleaned section name, falling back to a default if empty/spaces.
     *
     * @param \stdClass $course
     * @param \section_info $section
     * @return string
     */
    public static function get_section_name_clean($course, $section): string {
        $name = trim(get_section_name($course, $section));
        if ($name === '') {
            if ($section->section == 0) {
                // Section 0 is always General/General Info
                $name = get_string('general', 'moodle');
                if (empty($name) || $name === 'general') {
                    $name = 'General';
                }
            } else {
                // Other sections, e.g. Tema 1, Semana 1, etc.
                try {
                    $name = get_string('sectionname', 'format_' . $course->format);
                } catch (\Exception $e) {
                    $name = 'Sección';
                }
                if (empty($name) || $name === 'sectionname') {
                    $name = 'Sección';
                }
                $name .= ' ' . $section->section;
            }
        }
        return $name;
    }

    /**
     * Get the text content representing a Moodle activity (assignment, quiz, forum, page, book, etc.)
     *
     * @param object $cm
     * @return string
     */
    public static function get_activity_text($cm): string {
        global $DB;
        $text = "Actividad: " . $cm->name . "\nTipo: " . $cm->modname . "\n\n";

        try {
            if ($cm->modname === 'assign') {
                $record = $DB->get_record('assign', ['id' => $cm->instance]);
                if ($record) {
                    $text .= "Instrucciones de la tarea:\n" . self::clean_html($record->intro);
                }
            } else if ($cm->modname === 'forum') {
                $record = $DB->get_record('forum', ['id' => $cm->instance]);
                if ($record) {
                    $text .= "Descripción del foro:\n" . self::clean_html($record->intro);
                }
            } else if ($cm->modname === 'page') {
                $record = $DB->get_record('page', ['id' => $cm->instance]);
                if ($record) {
                    $text .= "Descripción:\n" . self::clean_html($record->intro) . "\n\nContenido de la página:\n" . self::clean_html($record->content);
                }
            } else if ($cm->modname === 'url') {
                $record = $DB->get_record('url', ['id' => $cm->instance]);
                if ($record) {
                    $text .= "Descripción:\n" . self::clean_html($record->intro) . "\nURL: " . $record->externalurl;
                }
            } else if ($cm->modname === 'book') {
                $record = $DB->get_record('book', ['id' => $cm->instance]);
                if ($record) {
                    $text .= "Libro: " . $record->name . "\n" . self::clean_html($record->intro) . "\n\nCapítulos:\n";
                    $chapters = $DB->get_records('book_chapters', ['bookid' => $record->id], 'pagenum');
                    if ($chapters) {
                        foreach ($chapters as $ch) {
                            $text .= "\nTítulo: " . $ch->title . "\n" . self::clean_html($ch->content);
                        }
                    }
                }
            } else if ($cm->modname === 'quiz') {
                $record = $DB->get_record('quiz', ['id' => $cm->instance]);
                if ($record) {
                    $text .= "Descripción del cuestionario:\n" . self::clean_html($record->intro) . "\n\nPreguntas:\n";
                    $has_question_refs = $DB->get_manager()->table_exists('question_references');
                    if ($has_question_refs) {
                        $sql = "SELECT q.id, q.name, q.questiontext
                                FROM {quiz_slots} slot
                                JOIN {question_references} qref ON qref.itemid = slot.id
                                JOIN {question_bank_entries} qbe ON qbe.id = qref.questionbankentryid
                                JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                JOIN {question} q ON q.id = qv.questionid
                                WHERE slot.quizid = ? AND qv.status = 'ready'";
                        $qrecords = $DB->get_records_sql($sql, [$record->id]);
                    } else {
                        $sql = "SELECT q.id, q.name, q.questiontext
                                FROM {quiz_slots} slot
                                JOIN {question} q ON q.id = slot.questionid
                                WHERE slot.quizid = ?";
                        $qrecords = $DB->get_records_sql($sql, [$record->id]);
                    }
                    if ($qrecords) {
                        foreach ($qrecords as $qr) {
                            $text .= "- " . $qr->name . ": " . self::clean_html($qr->questiontext) . "\n";
                        }
                    }
                }
            } else if ($cm->modname === 'label') {
                $record = $DB->get_record('label', ['id' => $cm->instance]);
                if ($record) {
                    $text .= "Contenido de la etiqueta:\n" . self::clean_html($record->intro);
                }
            } else {
                if ($cm->content) {
                     $text .= "Detalles:\n" . self::clean_html($cm->content);
                }
            }
        } catch (\Exception $e) {
            $text .= "\nError al obtener detalles: " . $e->getMessage();
        }

        return trim($text);
    }

    /**
     * Cleans HTML content by removing style and script tags and their contents,
     * stripping other HTML tags, and decoding entities.
     *
     * @param string $html
     * @return string
     */
    public static function clean_html(?string $html): string {
        if (empty($html)) {
            return '';
        }
        // Remove style blocks and their contents
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        // Remove script blocks and their contents
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        // Now strip tags
        $text = strip_tags($html);
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text);
    }
}
