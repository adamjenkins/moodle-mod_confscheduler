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
 * List of all confscheduler instances in a course.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confscheduler/lib.php');

$courseid = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);

$coursecontext = context_course::instance($course->id);

$PAGE->set_url('/mod/confscheduler/index.php', ['id' => $course->id]);
$PAGE->set_title(get_string('modulenameplural', 'mod_confscheduler'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_confscheduler'));

$modinfo = get_fast_modinfo($course);
$instances = $modinfo->get_instances_of('confscheduler');

if (!$instances) {
    echo $OUTPUT->notification(get_string('noinstances', 'mod_confscheduler'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('name'),
];
$table->attributes['class'] = 'generaltable mod_index';

foreach ($instances as $cm) {
    if (!$cm->uservisible) {
        continue;
    }

    $confscheduler = $DB->get_record('confscheduler', ['id' => $cm->instance], '*', MUST_EXIST);
    $link = html_writer::link(
        new moodle_url('/mod/confscheduler/view.php', ['id' => $cm->id]),
        format_string($confscheduler->name)
    );

    $table->data[] = [
        $link,
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
