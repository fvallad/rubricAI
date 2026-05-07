<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * Class to extract course data for AreteIA
 */
class data_provider {
 
    /** Allowed file extensions for RAG processing */
    private const ALLOWED_EXTS = ['pdf', 'ppt', 'pptx', 'docx', 'doc'];

    /** Allowed module types: only Resources, not Activities */
    private const RESOURCE_MODULES = ['resource', 'folder', 'page', 'url', 'book', 'label', 'imscp'];

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
            'summary' => strip_tags($course->summary),
            'sections' => []
        ];

        foreach ($modinfo->get_section_info_all() as $section) {
            if ($section->uservisible && ($section->summary || !empty($modinfo->sections[$section->section]))) {
                $sectiondata = [
                    'name' => get_section_name($course, $section),
                    'summary' => strip_tags($section->summary),
                    'activities' => []
                ];

                if (!empty($modinfo->sections[$section->section])) {
                    foreach ($modinfo->sections[$section->section] as $cmid) {
                        $cm = $modinfo->get_cm($cmid);
                        if ($cm->uservisible) {
                            $sectiondata['activities'][] = [
                                'name' => $cm->name,
                                'type' => $cm->modname,
                                'description' => strip_tags($cm->content) // Simplificado
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
            $base_sync_dir = $CFG->dataroot . '/areteia_sync/course_' . $courseid;
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
            $section_name = clean_param(get_section_name($course, $section), PARAM_FILE);
            $section_folder_name = $section->section . '_' . ($section_name ?: 'Section');
            
            if (!empty($modinfo->sections[$section->section])) {
                foreach ($modinfo->sections[$section->section] as $cmid) {
                    $cm = $modinfo->get_cm($cmid);
                    if (!$cm->uservisible) continue;
                    if (!in_array($cm->modname, self::RESOURCE_MODULES)) continue;

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

                        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                        if (!in_array($ext, self::ALLOWED_EXTS)) {
                            continue;
                        }

                        $activity_name = clean_param($cm->name, PARAM_FILE);
                        $activity_folder_name = $cm->id . '_' . ($activity_name ?: 'Activity');
                        
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
                                    file_put_contents($CFG->dataroot . '/areteia_sync/debug.txt', "Error copying " . $file->get_filename() . ": " . $e->getMessage() . "\n", FILE_APPEND);
                                }
                            }
                            $reldata['localpath'] = $localpath;
                        }
                        $res[] = $reldata;
                    }
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

            $section_name = get_section_name($course, $section);
            $section_node = [
                'id'         => $section->id,
                'name'       => $section_name ?: "Sección {$section->section}",
                'type'       => 'section',
                'activities' => []
            ];

            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->uservisible) continue;
                if (!in_array($cm->modname, self::RESOURCE_MODULES)) continue;

                $activity_node = [
                    'id'    => $cm->id,
                    'name'  => $cm->name,
                    'type'  => 'activity',
                    'files' => []
                ];

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

                        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                        if (!in_array($ext, self::ALLOWED_EXTS)) continue;

                        $activity_node['files'][] = [
                            'id'      => $file->get_id(),
                            'name'    => $file->get_filename(),
                            'type'    => 'file',
                            'relpath' => $section->section . '_' . clean_param($section_name, PARAM_FILE) . '/' .
                                         $cm->id . '_' . clean_param($cm->name, PARAM_FILE) . '/' . $file->get_filename()
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
     * Return the list of visible course sections for the quiz injection selector.
     *
     * @param int $courseid
     * @return array  [['num' => int, 'name' => string], ...]
     */
    public static function get_course_sections(int $courseid): array {
        global $DB;
        $course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo  = get_fast_modinfo($course);
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible) continue;
            $sections[] = [
                'num'  => (int)$section->section,
                'name' => get_section_name($course, $section),
            ];
        }
        return $sections;
    }

    /**
     * Programmatically create a Moodle Quiz with the given questions.
     * Compatible with both Moodle 3.x and Moodle 4.x question bank structures.
     *
     * @param int    $courseid
     * @param int    $section_num  Section number (0 = section 0 / General)
     * @param array  $questions    Array of question data from step7::get_fake_questions()
     * @param string $name         Quiz activity name
     * @return array  ['coursemodule' => int]
     */
    public static function create_quiz_activity(
        int $courseid,
        int $section_num,
        array $questions,
        string $name = 'Cuestionario AreteIA',
        ?float $max_grade = null
    ): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir  . '/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $course         = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $module         = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
        $course_context = \context_course::instance($courseid);

        // ----------------------------------------------------------------
        // 1. Create the quiz instance record
        // ----------------------------------------------------------------
        $quiz = new \stdClass();
        $quiz->course              = $courseid;
        $quiz->name                = $name;
        $quiz->intro               = '';
        $quiz->introformat         = FORMAT_HTML;
        $quiz->timeopen            = 0;
        $quiz->timeclose           = 0;
        $quiz->timelimit           = 0;
        $quiz->overduehandling     = 'autosubmit';
        $quiz->graceperiod         = 0;
        $quiz->preferredbehaviour  = 'deferredfeedback';
        $quiz->canredoquestions    = 0;
        $quiz->attempts            = 0;
        $quiz->attemptonlast       = 0;
        $quiz->grademethod         = 1; // QUIZ_GRADEHIGHEST
        $quiz->decimalpoints       = 2;
        $quiz->questiondecimalpoints = -1;
        $quiz->reviewattempt       = 69904;
        $quiz->reviewcorrectness   = 4368;
        $quiz->reviewmarks         = 4368;
        $quiz->reviewspecificfeedback = 4368;
        $quiz->reviewgeneralfeedback  = 4368;
        $quiz->reviewrightanswer   = 4368;
        $quiz->reviewoverallfeedback  = 4368;
        $quiz->questionsperpage    = 0;
        $quiz->navmethod           = 'free';
        $quiz->shuffleanswers      = 1;
        $quiz->sumgrades           = 0;
        $quiz->grade               = $max_grade !== null ? $max_grade : count($questions) * 1.0;
        $quiz->timecreated         = time();
        $quiz->timemodified        = time();
        $quiz->password            = '';
        $quiz->subnet              = '';
        $quiz->browsersecurity     = '-';
        $quiz->delay1              = 0;
        $quiz->delay2              = 0;
        $quiz->showuserpicture     = 0;
        $quiz->showblocks          = 0;
        $quiz->id = $DB->insert_record('quiz', $quiz);

        // ----------------------------------------------------------------
        // 2. Attach quiz to a course section
        // ----------------------------------------------------------------
        $modinfo    = get_fast_modinfo($course);
        $section_id = 0;
        foreach ($modinfo->get_section_info_all() as $s) {
            if ((int)$s->section === $section_num) {
                $section_id = $s->id;
                break;
            }
        }
        // Fallback: use first non-zero section, or 0
        if (!$section_id) {
            foreach ($modinfo->get_section_info_all() as $s) {
                if ($s->section > 0) {
                    $section_id  = $s->id;
                    $section_num = $s->section;
                    break;
                }
            }
        }

        $cm           = new \stdClass();
        $cm->course   = $courseid;
        $cm->module   = $module->id;
        $cm->instance = $quiz->id;
        $cm->section  = $section_id;
        $cm->id       = add_course_module($cm);
        course_add_cm_to_section($courseid, $cm->id, $section_num);

        // ----------------------------------------------------------------
        // CRITICAL: Set cmid on the quiz object NOW, before adding questions.
        // quiz_add_quiz_question() in Moodle 4.x needs quiz->cmid to create
        // the question_references record. Without it, it falls back to
        // get_coursemodule_from_instance() which can fail silently on a
        // freshly-inserted CM (cache not warmed), leaving quiz_slots with
        // no maxmark and sumgrades = 0  →  "cannot start: no gradeable questions".
        // ----------------------------------------------------------------
        $quiz->cmid = $cm->id;

        // Reload the quiz record from DB so the object reflects the actual
        // persisted state (e.g. sumgrades, grade), then re-attach cmid.
        $quiz = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $quiz->cmid = $cm->id;

        // ----------------------------------------------------------------
        // 3. Get or create default question category for this course
        // ----------------------------------------------------------------
        $category = $DB->get_record_sql(
            "SELECT * FROM {question_categories} WHERE contextid = ? ORDER BY parent ASC, id ASC LIMIT 1",
            [$course_context->id]
        );
        if (!$category) {
            $category              = new \stdClass();
            $category->name        = 'Preguntas de ' . $course->fullname;
            $category->contextid   = $course_context->id;
            $category->info        = '';
            $category->infoformat  = FORMAT_HTML;
            $category->sortorder   = 999;
            $category->parent      = 0;
            $category->stamp       = time() . '+' . make_unique_id_code();
            $category->id = $DB->insert_record('question_categories', $category);
        }

        // ----------------------------------------------------------------
        // 4. Create questions and add them to the quiz
        // ----------------------------------------------------------------
        $quiz_context = \context_module::instance($cm->id);
        $has_question_refs = $DB->get_manager()->table_exists('question_references');

        // Explicitly create the default quiz section for Moodle 4.x +
        if ($has_question_refs) {
            $hqs = $DB->get_manager()->table_exists('quiz_sections');
            if ($hqs) {
                $section = new \stdClass();
                $section->quizid = $quiz->id;
                $section->firstslot = 1;
                $section->heading = '';
                $section->shufflequestions = 0;
                $DB->insert_record('quiz_sections', $section);
            }
        }

        foreach ($questions as $idx => $q_data) {
            $slot_num = $idx + 1;

            // Create the question record in the question bank
            $qid = self::create_question_for_bank(
                $q_data, $category->id, $slot_num
            );

            if ($has_question_refs) {
                // Completely bypass Moodle's native quiz_add_quiz_question which silently
                // fails in bulk custom injection (e.g., deduplication/cache false-positives).
                
                // 4a. quiz_slots
                // For AI generated quizzes (up to 15 questions), it is cleaner
                // to bunch them on a single page (page=1) rather than a page per slot.
                $slot = new \stdClass();
                $slot->quizid = $quiz->id;
                $slot->slot = $slot_num;
                $slot->page = 1; 
                $slot->displaynumber = '';
                $slot->requireprevious = 0;
                $slot->maxmark = isset($q_data['points']) ? (float)$q_data['points'] : 1.0;
                $slot_id = $DB->insert_record('quiz_slots', $slot);

                // 4b. question_references wiring
                $qv = $DB->get_record('question_versions', ['questionid' => $qid]);
                if ($qv) {
                    $qref = new \stdClass();
                    $qref->usingcontextid     = $quiz_context->id;
                    $qref->component          = 'mod_quiz';
                    $qref->questionarea       = 'slot';
                    $qref->itemid             = $slot_id;
                    $qref->questionbankentryid= $qv->questionbankentryid;
                    $qref->version            = null; // always latest
                    $DB->insert_record('question_references', $qref);
                }
            } else {
                // Fallback for Moodle 3.x
                $maxmark = isset($q_data['points']) ? (float)$q_data['points'] : 1.0;
                quiz_add_quiz_question($qid, $quiz, 0, $maxmark);
            }
        }

        // Recalculate sumgrades (sum of maxmark from quiz_slots)
        if (function_exists('quiz_update_sumgrades')) {
            quiz_update_sumgrades($quiz);
        }

        // Verify sumgrades was updated; if still 0 with questions, force-fix.
        $db_sumgrades = $DB->get_field('quiz', 'sumgrades', ['id' => $quiz->id]);
        if ($db_sumgrades == 0 && count($questions) > 0) {
            $expected = 0.0;
            foreach ($questions as $q) {
                $expected += isset($q['points']) ? (float)$q['points'] : 1.0;
            }
            $DB->set_field('quiz', 'sumgrades', $expected, ['id' => $quiz->id]);
            if ($max_grade === null) {
                $DB->set_field('quiz', 'grade', $expected, ['id' => $quiz->id]);
            }
            error_log('[AreteIA] Force-fixed sumgrades=' . $expected . ' for quiz id=' . $quiz->id);
        }

        // Force course cache rebuild
        rebuild_course_cache($courseid, true);
        get_fast_modinfo($courseid, 0, true);

        return ['coursemodule' => $cm->id];
    }

    // ------------------------------------------------------------------
    // Private: question creation helpers
    // ------------------------------------------------------------------

    /**
     * Create a single question in the question bank.
     * Returns the question ID. Moodle's quiz_add_quiz_question() handles
     * all quiz_slots/question_references/question_versions wiring.
     */
    private static function create_question_for_bank(
        array $q_data,
        int $category_id,
        int $slot_num
    ): int {
        global $DB, $USER;

        // --- Core question record ---
        $q                         = new \stdClass();
        $q->name                   = 'Pregunta ' . $slot_num . ': ' . substr($q_data['text'], 0, 50) . '...';
        $q->questiontext           = $q_data['text'];
        $q->questiontextformat     = FORMAT_HTML;
        $q->generalfeedback        = '';
        $q->generalfeedbackformat  = FORMAT_HTML;
        $q->defaultmark            = isset($q_data['points']) ? (float)$q_data['points'] : 1.0;
        $q->penalty                = ($q_data['type'] === 'essay') ? 0.0 : 0.3333333;
        $q->qtype                  = $q_data['type'];
        $q->length                 = ($q_data['type'] === 'description') ? 0 : 1;
        $q->stamp                  = make_unique_id_code();
        $q->category               = $category_id;
        $q->parent                 = 0;
        $q->hidden                 = 0;
        $q->timecreated            = time();
        $q->timemodified           = time();
        $q->createdby              = $USER->id;
        $q->modifiedby             = $USER->id;
        $q->version                = make_unique_id_code();
        $q->id = $DB->insert_record('question', $q);

        // --- Moodle 4.x: question_bank_entries + question_versions ---
        if ($DB->get_manager()->table_exists('question_bank_entries')) {
            $qbe                       = new \stdClass();
            $qbe->questioncategoryid   = $category_id;
            $qbe->idnumber             = null;
            $qbe->ownerid              = $USER->id;
            $qbe_id = $DB->insert_record('question_bank_entries', $qbe);

            $qv                       = new \stdClass();
            $qv->questionbankentryid  = $qbe_id;
            $qv->version              = 1;
            $qv->questionid           = $q->id;
            $qv->status               = 'ready';
            $DB->insert_record('question_versions', $qv);
        }

        // --- Type-specific options ---
        switch ($q_data['type']) {
            case 'multichoice':
                self::create_multichoice_data($q->id, $q_data);
                break;
            case 'truefalse':
                self::create_truefalse_data($q->id, $q_data);
                break;
            case 'match':
                self::create_match_data($q->id, $q_data);
                break;
            case 'shortanswer':
                self::create_shortanswer_data($q->id, $q_data);
                break;
            case 'numerical':
                self::create_numerical_data($q->id, $q_data);
                break;
            case 'gapselect':
                self::create_gapselect_data($q->id, $q_data);
                break;
            case 'multianswer':
                // Cloze questions only need the formatted text in questiontext.
                break;
            case 'essay':
                self::create_essay_data($q->id);
                break;
        }

        return $q->id;
    }

    /** Create answers + options for a multichoice question. */
    private static function create_multichoice_data(int $qid, array $q_data): void {
        global $DB;

        $correct_index = $q_data['correct'] ?? 0;
        foreach ($q_data['options'] as $i => $option_text) {
            $ans                  = new \stdClass();
            $ans->question        = $qid;
            $ans->answer          = $option_text;
            $ans->answerformat    = FORMAT_HTML;
            $ans->fraction        = ($i === $correct_index) ? 1.0 : 0.0;
            $ans->feedback        = '';
            $ans->feedbackformat  = FORMAT_HTML;
            $DB->insert_record('question_answers', $ans);
        }

        $opts                              = new \stdClass();
        $opts->questionid                  = $qid;
        $opts->layout                      = 0;
        $opts->single                      = 1; // single correct answer
        $opts->shuffleanswers              = 1;
        $opts->correctfeedback             = 'Correcto.';
        $opts->correctfeedbackformat       = FORMAT_HTML;
        $opts->partiallycorrectfeedback    = '';
        $opts->partiallycorrectfeedbackformat = FORMAT_HTML;
        $opts->incorrectfeedback           = 'Incorrecto.';
        $opts->incorrectfeedbackformat     = FORMAT_HTML;
        $opts->answernumbering             = 'abc';
        $opts->showstandardinstruction     = 1;
        $DB->insert_record('qtype_multichoice_options', $opts);
    }

    /** Create answers + options for a true/false question. */
    private static function create_truefalse_data(int $qid, array $q_data): void {
        global $DB;

        $correct = $q_data['correct'] ?? true;

        $true_ans               = new \stdClass();
        $true_ans->question     = $qid;
        $true_ans->answer       = 'True';
        $true_ans->answerformat = FORMAT_MOODLE;
        $true_ans->fraction     = $correct ? 1.0 : 0.0;
        $true_ans->feedback     = '';
        $true_ans->feedbackformat = FORMAT_HTML;
        $true_id = $DB->insert_record('question_answers', $true_ans);

        $false_ans               = new \stdClass();
        $false_ans->question     = $qid;
        $false_ans->answer       = 'False';
        $false_ans->answerformat = FORMAT_MOODLE;
        $false_ans->fraction     = $correct ? 0.0 : 1.0;
        $false_ans->feedback     = '';
        $false_ans->feedbackformat = FORMAT_HTML;
        $false_id = $DB->insert_record('question_answers', $false_ans);

        $opts               = new \stdClass();
        $opts->question     = $qid;
        $opts->trueanswer   = $true_id;
        $opts->falseanswer  = $false_id;
        $DB->insert_record('question_truefalse', $opts);
    }

    /** Create options for an essay question. */
    private static function create_essay_data(int $qid): void {
        global $DB;

        $opts                          = new \stdClass();
        $opts->questionid              = $qid;
        $opts->responseformat          = 'editor';
        $opts->responserequired        = 1;
        $opts->responsefieldlines      = 15;
        $opts->minwordlimit            = null;
        $opts->maxwordlimit            = null;
        $opts->attachments             = 0;
        $opts->attachmentsrequired     = 0;
        $opts->graderinfo              = '';
        $opts->graderinfoformat        = FORMAT_HTML;
        $opts->responsetemplate        = '';
        $opts->responsetemplateformat  = FORMAT_HTML;
        $opts->maxbytes                = 0;
        $DB->insert_record('qtype_essay_options', $opts);
    }

    /** Create subquestions and options for a matching question. */
    private static function create_match_data(int $qid, array $q_data): void {
        global $DB;

        foreach (($q_data['pairs'] ?? []) as $pair) {
            $sub = new \stdClass();
            $sub->questionid = $qid;
            $sub->questiontext = $pair['premise'];
            $sub->questiontextformat = FORMAT_HTML;
            $sub->answertext = $pair['answer'];
            $DB->insert_record('qtype_match_subquestions', $sub);
        }

        $opts                              = new \stdClass();
        $opts->questionid                  = $qid;
        $opts->shuffleanswers              = 1;
        $opts->correctfeedback             = 'Correcto.';
        $opts->correctfeedbackformat       = FORMAT_HTML;
        $opts->partiallycorrectfeedback    = '';
        $opts->partiallycorrectfeedbackformat = FORMAT_HTML;
        $opts->incorrectfeedback           = 'Incorrecto.';
        $opts->incorrectfeedbackformat     = FORMAT_HTML;
        $DB->insert_record('qtype_match_options', $opts);
    }

    /** Create answer and options for a shortanswer question. */
    private static function create_shortanswer_data(int $qid, array $q_data): void {
        global $DB;

        $ans                  = new \stdClass();
        $ans->question        = $qid;
        $ans->answer          = $q_data['correct'] ?? '';
        $ans->answerformat    = FORMAT_PLAIN;
        $ans->fraction        = 1.0;
        $ans->feedback        = 'Correcto.';
        $ans->feedbackformat  = FORMAT_HTML;
        $DB->insert_record('question_answers', $ans);

        $opts               = new \stdClass();
        $opts->questionid   = $qid;
        $opts->usecase      = 0; // Case insensitive
        $DB->insert_record('qtype_shortanswer_options', $opts);
    }

    /** Create answer and options for a numerical question. */
    private static function create_numerical_data(int $qid, array $q_data): void {
        global $DB;

        $ans                  = new \stdClass();
        $ans->question        = $qid;
        $ans->answer          = (string)($q_data['correct'] ?? '0');
        $ans->answerformat    = FORMAT_PLAIN;
        $ans->fraction        = 1.0;
        $ans->feedback        = 'Correcto.';
        $ans->feedbackformat  = FORMAT_HTML;
        $ans_id = $DB->insert_record('question_answers', $ans);

        // Numerical specific version of the answer
        $num = new \stdClass();
        $num->answer   = $ans_id;
        $num->tolerance = '0.01'; // Default tolerance
        $DB->insert_record('question_numerical', $num);

        $opts = new \stdClass();
        $opts->questionid = $qid;
        $opts->showunits = 0;
        $opts->unitgradingtype = 0;
        $opts->unitpenalty = 0.1;
        $opts->unitsleft = 0;
        $opts->instructions = '';
        $opts->instructionsformat = FORMAT_HTML;
        $DB->insert_record('qtype_numerical_options', $opts);
    }

    /** Create options for a gapselect (select missing words) question. */
    private static function create_gapselect_data(int $qid, array $q_data): void {
        global $DB;

        // Choices go to question_answers, grouped by 'feedback' field (used as group id)
        if (!empty($q_data['options'])) {
            foreach ($q_data['options'] as $choice) {
                $ans                  = new \stdClass();
                $ans->question        = $qid;
                $ans->answer          = $choice;
                $ans->answerformat    = FORMAT_PLAIN;
                $ans->fraction        = 0.0;
                $ans->feedback        = '1'; // Group 1
                $ans->feedbackformat  = FORMAT_PLAIN;
                $DB->insert_record('question_answers', $ans);
            }
        }

        $opts                              = new \stdClass();
        $opts->questionid                  = $qid;
        $opts->shuffleanswers              = 1;
        $opts->correctfeedback             = 'Correcto.';
        $opts->correctfeedbackformat       = FORMAT_HTML;
        $opts->partiallycorrectfeedback    = '';
        $opts->partiallycorrectfeedbackformat = FORMAT_HTML;
        $opts->incorrectfeedback           = 'Incorrecto.';
        $opts->incorrectfeedbackformat     = FORMAT_HTML;
        $DB->insert_record('qtype_gapselect', $opts);
    }

    /**
     * Create a new Moodle Assign activity programmatically
     * 
     * @param int $courseid
     * @param string $name
     * @param string $description
     * @return object The created course module info
     */
    public static function create_assign_activity($courseid, $name, $description) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $module = $DB->get_record('modules', ['name' => 'assign'], '*', MUST_EXIST);
        
        // Find or create a valid section
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();
        $sectionid = 0;
        foreach ($sections as $s) {
            if ($s->section > 0) {
                $sectionid = $s->id;
                $sectionnum = $s->section;
                break;
            }
        }
        
        // 1. Create assignment instance
        $assign = new \stdClass();
        $assign->course = $courseid;
        $assign->name = $name;
        $assign->intro = $description;
        $assign->introformat = FORMAT_MARKDOWN;
        $assign->grade = 100;
        $assign->timemodified = time();
        $assign->id = $DB->insert_record('assign', $assign);
        
        // 2. Add course module
        $cm = new \stdClass();
        $cm->course = $courseid;
        $cm->module = $module->id;
        $cm->instance = $assign->id;
        $cm->section = $sectionid;
        $cm->id = add_course_module($cm);
        
        // 3. Add cm to section
        course_add_cm_to_section($courseid, $cm->id, $sectionnum);
        
        // 4. Rebuild cache
        rebuild_course_cache($courseid, true);
        
        return (object)['coursemodule' => $cm->id];
    }

    /**
     * Recursively delete the sync directory for a course.
     *
     * @param int $courseid
     */
    public static function delete_sync_dir($courseid) {
        global $CFG;
        $base_sync_dir = $CFG->dataroot . '/areteia_sync/course_' . $courseid;
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
}
