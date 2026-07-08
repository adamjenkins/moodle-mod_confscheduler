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

namespace mod_confscheduler\local;

/**
 * Builds an iCalendar (.ics) export of a user's "my timetable" -- the
 * presentations they have favourited that are scheduled in a confscheduler
 * instance (user request, 2026-07-06).
 *
 * Reuses \mod_confscheduler\local\grid_data::build() rather than re-querying
 * slots/rooms/favourite state itself: that method already resolves everything
 * an event needs (title, speakers, track, room names, per-user favourited
 * flag) with the same N+1-avoiding queries the grid AJAX endpoint relies on,
 * so this class only has to filter and map, not re-implement that data
 * assembly a second time.
 *
 * Uses Moodle's own Bennu iCalendar library (lib/bennu), the same one
 * core's calendar/export_execute.php uses for its "export calendar" feature --
 * not a bespoke ICS string builder, so date-escaping/line-folding/property
 * quoting are all handled by code already exercised across every Moodle site.
 *
 * This class does not check capabilities: the caller (export.php) is
 * responsible for require_capability('mod/confscheduler:viewschedule', ...)
 * before calling build(), matching grid_data::build()'s own documented split.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ics_export {
    /**
     * Builds the serialized .ics content for a user's favourited, scheduled
     * presentations in a confscheduler instance.
     *
     * A column-spanning block (submissionid null) can never be favourited (the
     * favourite star only ever appears on a presentation block -- see
     * scheduler_display.js's renderBlock()) and is always excluded here too. A
     * user with no favourites in this instance gets a validly-serialized, empty
     * calendar (zero VEVENT components), not an error -- there is nothing
     * exceptional about that state.
     *
     * @param \stdClass $confscheduler The confscheduler record
     * @param int $userid The user whose favourited presentations to export
     * @return string The serialized iCalendar (.ics) content
     */
    public static function build(\stdClass $confscheduler, int $userid): string {
        global $CFG;

        require_once($CFG->libdir . '/bennu/bennu.inc.php');

        $payload = grid_data::build($confscheduler, $userid);

        $roomnamesbyid = [];
        foreach ($payload['rooms'] as $room) {
            $roomnamesbyid[$room['id']] = $room['name'];
        }

        $hostaddress = preg_replace('#^https?://#', '', $CFG->wwwroot);

        $ical = new \iCalendar();
        $ical->add_property('method', 'PUBLISH');
        $ical->add_property('prodid', '-//Moodle Pty Ltd//NONSGML Moodle Version ' . $CFG->version . '//EN');

        foreach ($payload['slots'] as $slot) {
            if ($slot['submissionid'] === null || !$slot['favourited']) {
                continue;
            }

            $event = new \iCalendar_event();
            $event->add_property(
                'uid',
                $slot['id'] . '-confscheduler-' . $confscheduler->id . '@' . $hostaddress
            );
            $event->add_property('summary', $slot['title'] ?? '');

            $descriptionlines = [];
            if (!empty($slot['speakers'])) {
                $descriptionlines[] = $slot['speakers'];
            }
            if (!empty($slot['track'])) {
                $descriptionlines[] = $slot['track'];
            }
            if ($descriptionlines) {
                $event->add_property('description', implode("\n", $descriptionlines));
            }

            if (!empty($slot['roomnameoverride'])) {
                $event->add_property('location', $slot['roomnameoverride']);
            } else {
                $roomnames = array_filter(array_map(
                    static fn (int $roomid): string => $roomnamesbyid[$roomid] ?? '',
                    $slot['roomids']
                ));
                if ($roomnames) {
                    $event->add_property('location', implode(', ', $roomnames));
                }
            }

            $event->add_property('dtstamp', \Bennu::timestamp_to_datetime());
            $event->add_property('dtstart', \Bennu::timestamp_to_datetime($slot['starttime']));
            $event->add_property('dtend', \Bennu::timestamp_to_datetime($slot['endtime']));

            $ical->add_component($event);
        }

        return $ical->serialize();
    }

    /**
     * A sensible download filename for a confscheduler instance's timetable
     * export, based on the instance's own name (same clean_filename()
     * treatment core's own file-download code paths use elsewhere).
     *
     * @param \stdClass $confscheduler The confscheduler record
     * @return string
     */
    public static function filename(\stdClass $confscheduler): string {
        return clean_filename(format_string($confscheduler->name, true) . '-timetable.ics');
    }
}
