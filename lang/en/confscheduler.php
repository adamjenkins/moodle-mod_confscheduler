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

$string['addroom'] = 'Add room';
$string['addspanblock'] = 'Add span block';
$string['alldays'] = 'All days';
$string['autoscheduler'] = 'Autoscheduler';
$string['autoschedulerclearfirst'] = 'Clear existing schedule in this window first';
$string['autoschedulerclearfirst_help'] = 'If checked, every block already scheduled anywhere in the chosen window is removed before the autoscheduler places anything new. Blocks outside the window are never touched. If unchecked (the default), the autoscheduler simply avoids colliding with whatever is already scheduled.';
$string['autoschedulerignorepreferreddates'] = 'Ignore preferred dates';
$string['autoschedulerignorepreferreddates_help'] = 'By default, a submission whose submitter recorded preferred conference days is only ever placed on one of those days -- if none of them have room, it is left unscheduled and reported as "could not be placed" rather than landing on a day it was not offered as acceptable. Check this to instead treat preferred dates as a soft preference: the autoscheduler will still try a preferred day first, but falls back to placing on any day in the window if none of the preferred ones have room.';
$string['autoschedulernofit'] = 'No room/time combination in the chosen window fits this submission without a placement conflict.';
$string['autoschedulernopreferreddatefit'] = 'No available room/time on any of this submission\'s preferred dates within the chosen window. Check "Ignore preferred dates" to allow scheduling it on a different day instead.';
$string['autoschedulernoroomsconfigured'] = 'This scheduler has no rooms configured yet.';
$string['autoschedulerrun'] = 'Run autoscheduler';
$string['autoschedulersummary'] = '{$a->scheduled} scheduled, {$a->skipped} could not be placed.';
$string['autoschedulerwindowend'] = 'Window end';
$string['autoschedulerwindowstart'] = 'Window start';
$string['blackandwhite'] = 'Black & white';
$string['blocknonpreferredday'] = 'Scheduled on a day the submitter did not mark as preferred.';
$string['blockoverbooked'] = 'More people have favourited this presentation than this room\'s capacity allows (favourites/capacity):';
$string['colour'] = 'Colour';
$string['colourmode'] = 'Colour mode';
$string['conferenceend'] = 'Conference end';
$string['conferenceend_help'] = 'The date/time the conference ends. Required: this drives which days are selectable on the schedule grid (including "All days"), the autoscheduler\'s default window, and greys out (and refuses to schedule into) times after this point.';
$string['conferencestart'] = 'Conference start';
$string['conferencestart_help'] = 'The date/time the conference starts. Required: this drives which days are selectable on the schedule grid (including "All days"), the autoscheduler\'s default window, and greys out (and refuses to schedule into) times before this point.';
$string['confirmdeleteroom'] = 'Delete this room? This cannot be undone.';
$string['confirmsendnotifications'] = 'Send the schedule-change notification to every presentation with a scheduling change since it was last notified? {$a} presentation(s) currently have a pending change.';
$string['confprogramcmid'] = 'Conference Program activity';
$string['confprogramcmid_help'] = 'The Conference Program activity in this course whose accepted submissions this Conference Scheduler instance will schedule.';
$string['confscheduler:addinstance'] = 'Add a new Conference Scheduler activity';
$string['confscheduler:favourite'] = 'Toggle "my timetable" for a scheduled presentation';
$string['confscheduler:managenotifications'] = 'Manage the schedule-change notification template';
$string['confscheduler:manageschedule'] = 'Manage the schedule (drag-and-drop scheduling, room management, autoscheduler)';
$string['confscheduler:viewschedule'] = 'View the conference schedule';
$string['day'] = 'Day';
$string['daybounds_automatic'] = 'Automatic';
$string['dayend'] = 'Day end';
$string['dayend_help'] = 'The latest time of day the schedule grid displays by default. A presentation scheduled outside the day start/end window is still shown in full -- the grid widens just enough to include it -- but the area outside the configured window is greyed out, the same as the existing out-of-conference-hours band.';
$string['daystart'] = 'Day start';
$string['daystart_help'] = 'The earliest time of day the schedule grid displays by default. Leave Automatic checked to size the grid from whatever is actually scheduled, as before this setting existed.';
$string['deleteroom'] = 'Delete room';
$string['editroom'] = 'Edit room';
$string['editspanblock'] = 'Edit span block';
$string['error:conferenceendbeforestart'] = 'The conference end date must be after the conference start date.';
$string['error:gapviolation'] = 'This placement is too close to another presentation in the same room; the configured minimum gap is not met.';
$string['error:invalidcapacity'] = 'Room capacity must be left blank (unlimited) or a whole number of 0 or more.';
$string['error:invalidcolour'] = 'Room colour must be left blank or a 6-digit hex colour (e.g. #3366cc).';
$string['error:invalidconfprogramcmid'] = 'Choose a Conference Program activity from this course.';
$string['error:invaliddaybounds'] = 'Day end must be after day start, both must be times of day (00:00-23:59), and both must be set together (or both left as "Automatic").';
$string['error:invalidnumber'] = 'Please enter a whole number of 0 or more.';
$string['error:invalidpxperhour'] = 'Row height must be a whole number between 60 and 480 pixels per hour.';
$string['error:invalidroom'] = 'One or more of the selected rooms could not be found.';
$string['error:invalidslot'] = 'This scheduled block could not be found.';
$string['error:invalidsubmission'] = 'This submission cannot be scheduled here.';
$string['error:invalidtimerange'] = 'The end time must be after the start time.';
$string['error:labelrequired'] = 'Enter a label for this block.';
$string['error:noconfprogram'] = 'There are no Conference Program activities in this course yet. Add one first.';
$string['error:notaspanblock'] = 'This operation only applies to a column-spanning block with no presentation.';
$string['error:outsideconferencedates'] = 'This placement falls outside the conference start/end dates.';
$string['error:roomhasslots'] = 'This room still has scheduled blocks in it. Unschedule them first.';
$string['error:roomnamerequired'] = 'Enter a room name.';
$string['error:timeoverlap'] = 'This placement overlaps another block already scheduled in the same room.';
$string['exportmytimetable'] = 'Export my timetable (.ics)';
$string['favourite'] = 'Add to my timetable';
$string['filterbytrack'] = 'Show the conference programme filtered to track: {$a}';
$string['fullscreen'] = 'Fullscreen';
$string['gapminutes'] = 'SnapGap minimum gap (minutes)';
$string['gapminutes_help'] = 'The minimum gap, in minutes, enforced between presentations scheduled in the same room while dragging in the schedule grid. 0 means no minimum gap is enforced.';
$string['hour'] = 'Hour';
$string['landscape'] = 'Landscape';
$string['managenotifications'] = 'Manage notifications';
$string['messageprovider:scheduleupdated'] = 'The scheduling information for a presentation you are a speaker on has changed';
$string['minute'] = 'Minute';
$string['modulename'] = 'Conference Scheduler';
$string['modulename_help'] = 'The Conference Scheduler activity pulls accepted submissions from a Conference Program activity and lets organisers build a drag-and-drop room x time block schedule.';
$string['modulenameplural'] = 'Conference Schedulers';
$string['month'] = 'Month';
$string['movecolumn'] = 'Move column';
$string['mytimetable'] = 'My timetable';
$string['nocolour'] = 'No colour theme';
$string['noinstances'] = 'There are no Conference Scheduler activities in this course yet.';
$string['notifbody'] = 'Message';
$string['notifbody_help'] = 'The notification email body, sent to every speaker via Moodle\'s own notification system (and by email by default) when an organiser clicks "Send notifications" in the schedule editor. Use [[fullname]], [[submissiontitle]], [[coursename]], [[roomnames]], [[starttime]], [[endtime]].';
$string['notifdefaultbody:scheduled'] = '<p>Hello [[fullname]],</p><p>The scheduling information for your presentation "[[submissiontitle]]" for [[coursename]] has changed. It is now scheduled in [[roomnames]] from [[starttime]] to [[endtime]].</p>';
$string['notifdefaultsubject:scheduled'] = 'Schedule update: [[submissiontitle]]';
$string['notificationsenabled'] = 'Enable notifications';
$string['notificationsenabled_help'] = 'Master switch for this activity: when unchecked, no schedule-change notification is ever sent from this instance, regardless of the template configured below or how many presentations have a pending change.';
$string['notifplaceholders'] = 'Available placeholders: {$a}.';
$string['notifsubject'] = 'Subject';
$string['notifsubject_help'] = 'The notification email subject line. Same placeholders as the message body below.';
$string['notiftemplatesaved'] = 'Notification template saved.';
$string['orientation'] = 'Orientation';
$string['papersize'] = 'Paper size';
$string['pluginadministration'] = 'Conference Scheduler administration';
$string['pluginname'] = 'Conference Scheduler';
$string['portrait'] = 'Portrait';
$string['print'] = 'Print';
$string['privacy:metadata'] = 'The Conference Scheduler plugin does not store any personal data. Its tables hold only room/column configuration and scheduled time-block data (each of which reference a cross-plugin submission id or a plain text label, never a user), and the "my timetable" favourite state it toggles is stored entirely by the Conference Program plugin.';
$string['pxperhour'] = 'Row height (pixels per hour)';
$string['pxperhour_help'] = 'How tall one hour of scheduled time appears in the grid. Increase this if short presentations don\'t have enough room to show their title and speakers without overlapping other blocks; decrease it to fit a longer day on screen with less scrolling.';
$string['removeschedule'] = 'Delete the schedule (all scheduled slots)';
$string['roomcapacity'] = 'Capacity';
$string['roomcapacity_help'] = 'Maximum attendee capacity for this room. When set, a scheduled presentation whose mod_confprogram favourite count exceeds this capacity is highlighted in the edit-mode grid as a possible overbooking. Leave blank for unlimited (never warn).';
$string['roomcolour'] = 'Colour theme';
$string['roomname'] = 'Room name';
$string['schedulingsettings'] = 'Scheduling settings';
$string['sendnotifications'] = 'Send notifications';
$string['sendnotificationsnonepending'] = 'No presentations have a scheduling change pending notification.';
$string['sendnotificationssummary'] = '{$a} presentation(s) notified.';
$string['spanblockcolour'] = 'Colour theme';
$string['spanblockend'] = 'End time';
$string['spanblockendroom'] = 'End room';
$string['spanblocklabel'] = 'Label';
$string['spanblockstart'] = 'Start time';
$string['spanblockstartroom'] = 'Start room';
$string['unschedule'] = 'Unschedule';
$string['unscheduledheading'] = 'Unscheduled';
$string['year'] = 'Year';
