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
 * Language strings for mod_confscheduler.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['confprogramcmid'] = 'Conference Program activity';
$string['confprogramcmid_help'] = 'The Conference Program activity in this course whose accepted submissions this Conference Scheduler instance will schedule.';
$string['confscheduler:addinstance'] = 'Add a new Conference Scheduler activity';
$string['confscheduler:favourite'] = 'Toggle "my timetable" for a scheduled presentation';
$string['confscheduler:manageschedule'] = 'Manage the schedule (drag-and-drop scheduling, room management, autoscheduler)';
$string['confscheduler:viewschedule'] = 'View the conference schedule';
$string['error:invalidconfprogramcmid'] = 'Choose a Conference Program activity from this course.';
$string['error:invalidnumber'] = 'Please enter a whole number of 0 or more.';
$string['error:noconfprogram'] = 'There are no Conference Program activities in this course yet. Add one first.';
$string['gapminutes'] = 'GapSnap minimum gap (minutes)';
$string['gapminutes_help'] = 'The minimum gap, in minutes, enforced between presentations scheduled in the same room while dragging in the schedule grid. 0 means no minimum gap is enforced.';
$string['gridnotbuiltyet'] = 'The schedule grid has not been built yet. This is a scaffold release.';
$string['modulename'] = 'Conference Scheduler';
$string['modulename_help'] = 'The Conference Scheduler activity pulls accepted submissions from a Conference Program activity and lets organisers build a drag-and-drop room x time block schedule.';
$string['modulenameplural'] = 'Conference Schedulers';
$string['noinstances'] = 'There are no Conference Scheduler activities in this course yet.';
$string['pluginadministration'] = 'Conference Scheduler administration';
$string['pluginname'] = 'Conference Scheduler';
$string['privacy:metadata'] = 'The Conference Scheduler plugin does not store any personal data. Its tables hold only room/column configuration and scheduled time-block data (which reference a cross-plugin submission id or a plain text label, never a user), and the "my timetable" favourite state it toggles is stored entirely by the Conference Program plugin.';
$string['schedulingsettings'] = 'Scheduling settings';
