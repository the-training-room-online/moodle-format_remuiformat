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

namespace format_remuiformat\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use renderer_base;
use templatable;
use stdClass;
use context_course;
use html_writer;
use core_completion\progress;

require_once($CFG->dirroot.'/course/format/renderer.php');
require_once($CFG->dirroot.'/course/renderer.php');
require_once($CFG->dirroot.'/course/format/remuiformat/classes/mod_stats.php');
require_once($CFG->dirroot.'/course/format/remuiformat/classes/settings_controller.php');
require_once($CFG->dirroot.'/course/format/remuiformat/lib.php');

/**
 * This file contains the definition for the renderable classes for the sections page.
 *
 * @package   format_remuiformat
 * @copyright  2018 Wisdmlabs
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format_remuiformat_section extends \format_section_renderer_base implements renderable, templatable
{

    private $course;
    private $courseformat;
    protected $courserenderer;
    private $modstats;
    private $settings;

    /**
     * Constructor
     */
    public function __construct($course) {
        global $PAGE;
        $this->courseformat = course_get_format($course);
        $this->course = $this->courseformat->get_course();
        $this->courserenderer = new \core_course_renderer($PAGE, 'format_remuiformat');
        $this->modstats = \format_remuiformat\ModStats::getinstance();
        $this->settings = $this->courseformat->get_settings();
    }
    protected function start_section_list() {
    }

    /**
     * Generate the closing container html for a list of sections
     * @return string HTML to output.
     */
    protected function end_section_list() {
    }

    /**
     * Generate the title for this section page
     * @return string the page title
     */
    protected function page_title() {
    }

    /**
     * Function to export the renderer data in a format that is suitable for a
     * question mustache template.
     *
     * @param renderer_base $output Used to do a final render of any components that need to be rendered for export.
     * @return stdClass|array
     */
    public function export_for_template(renderer_base $output) {
        global $USER, $PAGE, $DB, $OUTPUT, $CFG;
        require_once($CFG->libdir. '/coursecatlib.php');

        $chelper = new \coursecat_helper();
        $export = new \stdClass();
        $renderer = $PAGE->get_renderer('format_remuiformat');
        // $courseformatrenderer = new \format_section_renderer_base($PAGE, 'format_remuiformat');
        $rformat = $this->settings['remuicourseformat'];

        // Get necessary values required to display the UI.
        $editing = $PAGE->user_is_editing();
        $coursecontext = context_course::instance($this->course->id);
        $export->coursefullname = $this->course->fullname;
        $coursesummary = $this->course->summary;
        // $coursesummary = strip_tags($chelper->get_course_formatted_summary($this->course, array('overflowdiv' => false, 'noclean' => false, 'para' => false)));
        $coursesummary = strlen($coursesummary) > 300 ? substr($coursesummary, 0, 300)."..." : $coursesummary;
        $export->coursesummary = $coursesummary;
        $modinfo = get_fast_modinfo($this->course);
        $sections = $modinfo->get_section_info_all();
        $export->editing = $editing;
        $export->courseformat = get_config('format_remuiformat', 'defaultcourseformat');
        // Setting up data for General Section.
        $generalsection = $modinfo->get_section_info(0);
        if ($generalsection) {
            if (!$editing) {
                $export->generalsection['title'] = $this->courseformat->get_section_name($generalsection);
                $export->generalsection['availability'] = $renderer->section_availability($generalsection);
                $export->generalsection['summary'] = $renderer->format_summary_text($generalsection);
            } else {
                $export->generalsection['title'] = $renderer->section_title($generalsection, $this->course);
                $export->generalsection['availability'] = $renderer->section_availability($generalsection);
                $export->generalsection['summary'] = $renderer->format_summary_text($generalsection);
                $export->generalsection['editsetionurl'] = new \moodle_url('editsection.php', array('id' => $generalsection->id));
                $export->generalsection['optionmenu'] = $renderer->section_right_content($generalsection, $this->course, false);

            }
            // Course Modules.
            switch ($rformat) {
                case REMUI_CARD_FORMAT:
                    $PAGE->requires->js(new \moodle_url($CFG->wwwroot . '/course/format/remuiformat/javascript/format_card.js'));
                    $export->generalsection['activities'] = $this->courserenderer->course_section_cm_list($this->course, $generalsection, 0);
                    $export->generalsection['activities'] .= $this->courserenderer->course_section_add_cm_control($this->course, 0, 0);
                    break;
                case REMUI_LIST_FORMAT:
                    $PAGE->requires->js(new \moodle_url($CFG->wwwroot . '/course/format/remuiformat/javascript/format_list.js'));
                    $imgurl = $this->display_file($this->settings['remuicourseimage_filemanager']);
                    $export->generalsection['remuicourseimage'] = $imgurl;
                    // For Completion percentage.
                    $export->generalsection['activities'] = $this->get_activities_details($generalsection);
                    $role = $DB->get_record('role', array('shortname' => 'editingteacher'));
                    $teachers = get_role_users($role->id, $coursecontext);
                    if (empty($teachers)) {
                        break;
                    }
                    $completion = new \completion_info($this->course);
                    // First, let's make sure completion is enabled.
                    if (!$completion->is_enabled()) {
                        continue;
                    }
                    $percentage = progress::get_course_progress_percentage($this->course);
                    if (!is_null($percentage)) {
                        $percentage = floor($percentage);
                        $export->generalsection['percentage'] = $percentage;
                    }

                    // For right side.
                    $rightside = $renderer->section_right_content($generalsection, $this->course, false);
                    $export->generalsection['rightside'] = $rightside;
                    // For displaying teachers.
                    $count = 1;
                    $export->generalsection['teachers'] = $teachers;
                    $export->generalsection['teachers']['teacherimg'] = '<div class="teacher-label"><span>Teachers</span></div>
                    <div class="carousel slide" data-ride="carousel" id="teachersCarousel">
                    <div class="carousel-inner">';

                    // Add new activity.
                    $export->generalsection['addnewactivity'] = $this->courserenderer->course_section_add_cm_control($this->course, 0, 0);

                    foreach ($teachers as $teacher) {
                        if ($count % 2 == 0) {
                            // Skip even members.
                            $count += 1;
                            next($teachers);
                            continue;
                        }
                        $teacher->imagealt = $teacher->firstname . ' ' . $teacher->lastname;
                        if ($count == 1) {
                            $export->generalsection['teachers']['teacherimg'] .= '<div class="carousel-item active">' . $OUTPUT->user_picture($teacher);

                        } else {
                            $export->generalsection['teachers']['teacherimg'] .= '<div class="carousel-item">'. $OUTPUT->user_picture($teacher);
                        }
                        $nextteacher = next($teachers);
                        if (false != $nextteacher) {
                            $nextteacher->imagealt = $nextteacher->firstname . ' ' . $nextteacher->lastname;
                            $export->generalsection['teachers']['teacherimg'] .= $OUTPUT->user_picture($nextteacher);
                        }
                        $export->generalsection['teachers']['teacherimg'] .= '</div>';
                        $count += 1;
                    }
                    $export->generalsection['teachers']['teacherimg'] .= '</div><a class="carousel-control-prev" href="#teachersCarousel" role="button" data-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                            <span class="sr-only">Previous</span>
                        </a>
                        <a class="carousel-control-next" href="#teachersCarousel" role="button" data-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                            <span class="sr-only">Next</span>
                        </a></div>';
                    break;
                default:
                    break;
            }
        }

        $startfrom = 1;
        $end = $this->courseformat->get_last_section_number();
        $sections = array();
        // Setting up data for remianing sections.
        for ($section = $startfrom; $section <= $end; $section++) {
            $sectiondetails = new \stdClass();
            $sectiondetails->index = $section;
            // Get current section info.
            $currentsection = $modinfo->get_section_info($section);
            $showsection = $currentsection->uservisible ||
                    ($currentsection->visible && !$currentsection->available && !empty($currentsection->availableinfo)) ||
                    (!$currentsection->visible && !$this->course->hiddensections);
            if (!$showsection) {
                continue;
            }

            // Get the title of the section.
            if (!$editing) {
                $sectiondetails->title = $this->courseformat->get_section_name($currentsection);
            } else {
                $sectiondetails->title = $renderer->section_title($currentsection, $this->course);
            }

            // Get the section view url.
            $singlepageurl = '';
            if ($this->course->coursedisplay == COURSE_DISPLAY_MULTIPAGE) {
                $singlepageurl = $this->courseformat->get_view_url($section)->out(true);
            }
            $sectiondetails->singlepageurl = $singlepageurl;
            $sectiondetails->summary = $this->modstats->get_formatted_summary(strip_tags($currentsection->summary), $this->settings);
            if ($editing) {
                $sectiondetails->editsectionurl = new \moodle_url('editsection.php', array('id' => $currentsection->id));
                $sectiondetails->leftside = '<div class="left side float-left">' . $renderer->section_left_content($currentsection, $this->course, false) . '</div>';
                $sectiondetails->optionmenu = $renderer->section_right_content($currentsection, $this->course, false);

            }
            $sectiondetails->hiddenmessage = $renderer->section_availability_message($currentsection, has_capability(
                'moodle/course:viewhiddensections',
                $coursecontext
            ));
            if ($sectiondetails->hiddenmessage != "") {
                $sectiondetails->hidden = 1;
            }
            $extradetails = $this->get_section_module_info($currentsection, $this->course, null);
            switch ($rformat) {
                case REMUI_CARD_FORMAT:
                    $sectiondetails->activityinfo = $extradetails['activityinfo'];
                    $sectiondetails->progressinfo = $extradetails['progressinfo'];
                    $sections[] = $sectiondetails;
                    break;
                case REMUI_LIST_FORMAT:
                    $sectiondetails->activityinfostring = implode(', ', $extradetails['activityinfo']);
                    $sectiondetails->sectionactivities = $this->courserenderer->course_section_cm_list($this->course, $currentsection, 0);
                    $sectiondetails->sectionactivities .= $this->courserenderer->course_section_add_cm_control($this->course, $currentsection->section, 0);
                    $sections[] = $sectiondetails;
                    break;
            }
        }
        $export->sections = $sections;
        $addsectionurl = new \moodle_url('/course/changenumsections.php',
                ['courseid' => $this->course->id, 'insertsection' => 0, 'sesskey' => sesskey()]);
        $export->addnewsectionoption = $addsectionurl;
        return  $export;
    }

    private function get_section_module_info($section, $course, $mods) {
        $modinfo = get_fast_modinfo($course);
        $output = array(
            "activityinfo" => array(),
            "progressinfo" => array()
        );
        if (empty($modinfo->sections[$section->section])) {
            return $output;
        }
        // Generate array with count of activities in this section:
        $sectionmods = array();
        $total = 0;
        $complete = 0;
        $cancomplete = isloggedin() && !isguestuser();
        $completioninfo = new \completion_info($course);
        foreach ($modinfo->sections[$section->section] as $cmid) {
            $thismod = $modinfo->cms[$cmid];
            // var_dump($thismod);
            if ($thismod->modname == 'label') {
                // Labels are special (not interesting for students)!
                continue;
            }

            if ($thismod->uservisible) {
                if (isset($sectionmods[$thismod->modname])) {
                    $sectionmods[$thismod->modname]['name'] = $thismod->modplural;
                    $sectionmods[$thismod->modname]['count']++;
                } else {
                    $sectionmods[$thismod->modname]['name'] = $thismod->modfullname;
                    $sectionmods[$thismod->modname]['count'] = 1;
                }
                if ($cancomplete && $completioninfo->is_enabled($thismod) != COMPLETION_TRACKING_NONE) {
                    $total++;
                    $completiondata = $completioninfo->get_data($thismod, true);
                    if ($completiondata->completionstate == COMPLETION_COMPLETE ||
                            $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                        $complete++;
                    }
                }
            }
        }
        foreach ($sectionmods as $mod) {
            $output['activityinfo'][] = $mod['name'].': '.$mod['count'];
        }
        if ($total > 0) {
            $pinfo = new \stdClass();
            $pinfo->percentage = round(($complete / $total) * 100, 0);
            $pinfo->completed = ($complete == $total) ? "completed" : "";
            $pinfo->progress = $complete." / ".$total;
            $output['progressinfo'][] = $pinfo;
        }
        return $output;
    }

    private function get_activities_details($section, $displayoptions = array()) {
        global $PAGE;
        $modinfo = get_fast_modinfo($this->course);
        // var_dump($modinfo);
        // exit;
        $output = array();
        $completioninfo = new \completion_info($this->course);

        if (!empty($modinfo->sections[$section->section])) {
            $count = 1;
            foreach ($modinfo->sections[$section->section] as $modnumber) {
                $mod = $modinfo->cms[$modnumber];
                if (!$mod->is_visible_on_course_page()) {
                    continue;
                }
                $completiondata = $completioninfo->get_data($mod, true);
                $activitydetails = new \stdClass();
                $activitydetails->index = $count;
                $activitydetails->id = $mod->id;
                $activitydetails->completion = $this->courserenderer->course_section_cm_completion($this->course, $completioninfo, $mod, $displayoptions);
                $activitydetails->viewurl = $mod->url;
                $activitydetails->title = $this->courserenderer->course_section_cm_name($mod, $displayoptions);
                $activitydetails->title .= $mod->afterlink;
                $activitydetails->modulename = $mod->modname;
                $activitydetails->summary = $this->courserenderer->course_section_cm_text($mod, $displayoptions);
                $activitydetails->summary = strlen($activitydetails->summary) > 300 ? substr($activitydetails->summary, 0, 300)."..." : $activitydetails->summary;
                $activitydetails->completed = $completiondata->completionstate;
                $modicons = '';
                if ($mod->visible == 0) {
                    $activitydetails->hidden = 1;
                }
                $availstatus = $this->courserenderer->course_section_cm_availability($mod, $modnumber);
                if ($availstatus != "") {
                    $activitydetails->availstatus = $availstatus;
                }
                if ($PAGE->user_is_editing()) {
                    $editactions = course_get_cm_edit_actions($mod, $mod->indent, $section->section);
                    $modicons .= ' '. $this->courserenderer->course_section_cm_edit_actions($editactions, $mod, $section->section);
                    $modicons .= $mod->afterediticons;
                    $activitydetails->modicons = $modicons;
                }
                $output[] = $activitydetails;
                $count++;
            }
        }
        return $output;
    }

    public function display_file($data) {
        global $DB, $CFG, $OUTPUT;
        $itemid = $data;
        $filedata = $DB->get_records('files', array('itemid' => $itemid));
        $tempdata = array();
        foreach ($filedata as $key => $value) {
            if ($value->filesize > 0 && $value->filearea == 'remuicourseimage_filearea') {
                $tempdata = $value;
            }
        }
        $fs = get_file_storage();
        if (!empty($tempdata)) {
            $files = $fs->get_area_files(
                $tempdata->contextid,
                'format_remuiformat',
                'remuicourseimage_filearea',
                $itemid
            );
            $url = '';
            foreach ($files as $key => $file) {
                $file->portfoliobutton = '';

                $path = '/'.
                        $tempdata->contextid.
                        '/'.
                        'format_remuiformat'.
                        '/'.
                        'remuicourseimage_filearea'.
                        '/'.
                        $file->get_itemid().
                        $file->get_filepath().
                        $file->get_filename();
                $url = file_encode_url("$CFG->wwwroot/pluginfile.php", $path, true);
            }
            return $url;
        }
        return '';
    }
}
