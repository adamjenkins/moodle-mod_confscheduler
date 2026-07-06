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
 * Notification template management screen for mod_confscheduler.
 *
 * A single notification type (the schedule-change notification), so -- like
 * mod_confprogram's equivalent page -- there are no tabs, just one form. One
 * row per instance (confscheduler_notiftemplate, unique on
 * confscheduler+notiftype). Pre-fills the editor with the built-in fallback
 * content when no row exists yet.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confscheduler/lib.php');

use mod_confscheduler\form\notiftemplate_form;
use mod_confscheduler\local\notifier;

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'confscheduler');
$confscheduler = $DB->get_record('confscheduler', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/confscheduler:managenotifications', $context);

$pageurl = new moodle_url('/mod/confscheduler/notifications.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confscheduler->name) . ': ' . get_string('managenotifications', 'mod_confscheduler'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$existing = $DB->get_record('confscheduler_notiftemplate', [
    'confscheduler' => $confscheduler->id,
    'notiftype'     => 'scheduled',
]);

$form = new notiftemplate_form($pageurl, ['context' => $context]);

$default = notifier::default_template();
$form->set_data((object) [
    'subject' => $existing->subject ?? $default['subject'],
    'body'    => [
        'text'   => $existing->body ?? $default['body'],
        'format' => $existing->bodyformat ?? FORMAT_HTML,
    ],
]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/confscheduler/view.php', ['id' => $cm->id]));
} else if ($data = $form->get_data()) {
    $now = time();
    $record = (object) [
        'confscheduler' => $confscheduler->id,
        'notiftype'     => 'scheduled',
        'subject'       => $data->subject,
        'body'          => $data->body['text'],
        'bodyformat'    => $data->body['format'],
        'timemodified'  => $now,
    ];

    if ($existing) {
        $record->id = $existing->id;
        $DB->update_record('confscheduler_notiftemplate', $record);
    } else {
        $record->timecreated = $now;
        $DB->insert_record('confscheduler_notiftemplate', $record);
    }

    redirect($pageurl, get_string('notiftemplatesaved', 'mod_confscheduler'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($confscheduler->name), 2);
echo $OUTPUT->heading(get_string('managenotifications', 'mod_confscheduler'), 3);

$placeholderlist = implode(', ', array_map(
    static fn (string $name): string => "[[{$name}]]",
    ['fullname', 'submissiontitle', 'coursename', 'roomnames', 'starttime', 'endtime']
));
echo $OUTPUT->notification(
    get_string('notifplaceholders', 'mod_confscheduler', $placeholderlist),
    'info'
);

$form->display();

echo $OUTPUT->footer();
