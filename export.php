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
 * ICS (iCalendar) export of the current user's "my timetable" -- their
 * favourited presentations, scheduled -- for a confscheduler instance (user
 * request, 2026-07-06).
 *
 * A plain (non-AJAX) download endpoint, same pattern as core's own
 * calendar/export_execute.php and mod_confcheckin's badge.php: the browser is
 * simply navigated here (a plain <a href>, no JS needed) and a file is
 * streamed back. Always exports the CURRENT user's own favourites -- there is
 * no userid parameter, since "my timetable" only ever means "my own", exactly
 * like the Display-mode "my timetable" toggle it mirrors.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confscheduler/lib.php');

use mod_confscheduler\local\ics_export;

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'confscheduler');
$confscheduler = $DB->get_record('confscheduler', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/confscheduler:viewschedule', $context);

$serialized = ics_export::build($confscheduler, (int) $USER->id);
$filename = ics_export::filename($confscheduler);

header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
header('Pragma: no-cache');
header('Content-Disposition: attachment; filename=' . $filename);
header('Content-Length: ' . strlen($serialized));
header('Content-Type: text/calendar; charset=utf-8');

echo $serialized;
