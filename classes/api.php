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

namespace mod_confscheduler;

/**
 * Public integration surface for the Conference Scheduler (room x time block
 * schedule) workflow.
 *
 * This is a scaffold: most methods here are stubs (schema/signature only, marked
 * TODO) pending the drag-and-drop grid feature work. The one exception is
 * get_schedule_for_submission(), which is a real, working implementation: it is
 * the contract \mod_confprogram\local\schedule_info expects and calls whenever
 * mod_confscheduler is installed (see that class's docblock), so it must work
 * correctly as soon as any slot data exists, even while the rest of this plugin
 * is still a stub.
 *
 * Capability contract: these methods do NOT check capabilities or context
 * themselves — they are a raw data-access layer only. Any caller MUST verify
 * the current user's capability (e.g. mod/confscheduler:viewschedule,
 * mod/confscheduler:manageschedule) against the relevant \context_module before
 * calling, or before exposing the returned data to a user/response.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /**
     * Returns the rooms (columns) configured for a confscheduler instance, in
     * display (sortorder) order.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @return \stdClass[] Array of confscheduler_room records, keyed by id
     */
    public static function get_rooms(int $confschedulerid): array {
        global $DB;

        return $DB->get_records(
            'confscheduler_room',
            ['confscheduler' => $confschedulerid],
            'sortorder ASC'
        );
    }

    /**
     * Returns the scheduled blocks (slots) for a confscheduler instance, in
     * start-time order.
     *
     * TODO: this is a schema-only stub. The full grid feature will need this to
     * also eager-load each slot's room(s) (via confscheduler_slotroom) and, for
     * presentation slots, the submission's display details from mod_confprogram
     * / mod_confsubmissions, to avoid an N+1 query pattern when rendering the grid.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @return \stdClass[] Array of confscheduler_slot records, keyed by id
     */
    public static function get_slots(int $confschedulerid): array {
        global $DB;

        return $DB->get_records(
            'confscheduler_slot',
            ['confscheduler' => $confschedulerid],
            'starttime ASC'
        );
    }

    /**
     * Returns the scheduled time/room for a submission, or null if it has not
     * been scheduled yet.
     *
     * This is the contract \mod_confprogram\local\schedule_info::get_for_submission()
     * expects and calls (via class_exists()/method_exists() checks, so it is safe
     * for that class to call even while mod_confscheduler is not installed). See
     * that class's docblock for the full informal contract shared between the two
     * plugins.
     *
     * When a slot spans multiple rooms (a column-spanning block, e.g. a plenary),
     * 'room' is a comma-joined string of all the room names it occupies, e.g.
     * "Main Hall, Room B". A submission is expected to have at most one slot; if
     * (through data corruption, or future multi-slot support) more than one slot
     * exists for a submission, the earliest by starttime is returned.
     *
     * @param int $submissionid The mod_confsubmissions confsubmissions_submission id
     * @return array{starttime: int, endtime: int, room: string}|null
     */
    public static function get_schedule_for_submission(int $submissionid): ?array {
        global $DB;

        $slots = $DB->get_records(
            'confscheduler_slot',
            ['submissionid' => $submissionid],
            'starttime ASC',
            '*',
            0,
            1
        );
        if (!$slots) {
            return null;
        }
        $slot = reset($slots);

        $roomnames = $DB->get_fieldset_sql(
            "SELECT r.name
               FROM {confscheduler_slotroom} sr
               JOIN {confscheduler_room} r ON r.id = sr.roomid
              WHERE sr.slotid = :slotid
           ORDER BY r.sortorder ASC",
            ['slotid' => $slot->id]
        );

        return [
            'starttime' => (int) $slot->starttime,
            'endtime'   => (int) $slot->endtime,
            'room'      => implode(', ', $roomnames),
        ];
    }

    /**
     * Creates a room (column) in a confscheduler instance.
     *
     * TODO: schema-only stub. Needs sortorder assignment (append to the end
     * unless one is given) once the room management UI is built.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param string $name The room name
     * @param int|null $sortorder The column order; null to append at the end
     * @param string|null $colour A hex colour (e.g. #3366cc), or null for no theme
     * @return int The confscheduler_room id
     */
    public static function add_room(int $confschedulerid, string $name, ?int $sortorder = null, ?string $colour = null): int {
        global $DB;

        // Stub: defaults sortorder to 0 rather than (max existing sortorder + 1) when null.
        return $DB->insert_record('confscheduler_room', (object) [
            'confscheduler' => $confschedulerid,
            'name'          => $name,
            'sortorder'     => $sortorder ?? 0,
            'colour'        => $colour,
        ]);
    }

    /**
     * Creates a scheduled slot (either a presentation or a column-spanning
     * block) and assigns it to one or more rooms.
     *
     * TODO: schema-only stub. Needs GapSnap enforcement (confscheduler.gapminutes)
     * and overlap/conflict validation once the drag-and-drop grid feature is built.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int[] $roomids The room id(s) this slot occupies; more than one for a spanning block
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @param int|null $submissionid The mod_confsubmissions confsubmissions_submission id; null for a span-block
     * @param string|null $label Used only when submissionid is null, e.g. "Lunch Break"
     * @return int The confscheduler_slot id
     */
    public static function add_slot(
        int $confschedulerid,
        array $roomids,
        int $starttime,
        int $endtime,
        ?int $submissionid = null,
        ?string $label = null
    ): int {
        global $DB;

        $now = time();
        $slotid = $DB->insert_record('confscheduler_slot', (object) [
            'confscheduler' => $confschedulerid,
            'submissionid'  => $submissionid,
            'label'         => $label,
            'starttime'     => $starttime,
            'endtime'       => $endtime,
            'timecreated'   => $now,
            'timemodified'  => $now,
        ]);

        foreach ($roomids as $roomid) {
            $DB->insert_record('confscheduler_slotroom', (object) [
                'slotid' => $slotid,
                'roomid' => $roomid,
            ]);
        }

        return $slotid;
    }
}
