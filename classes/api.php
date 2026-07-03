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
 * get_schedule_for_submission() is the contract \mod_confprogram\local\schedule_info
 * expects and calls whenever mod_confscheduler is installed (see that class's
 * docblock); it must not be changed.
 *
 * Capability contract: these methods do NOT check capabilities or context
 * themselves — they are a raw data-access layer only. Any caller MUST verify
 * the current user's capability (e.g. mod/confscheduler:viewschedule,
 * mod/confscheduler:manageschedule) against the relevant \context_module before
 * calling, or before exposing the returned data to a user/response.
 *
 * That said, some validation performed here is NOT a capability check but a
 * data-integrity/ownership check (e.g. add_slot()'s chain-of-custody check that
 * a submissionid actually belongs to the confprogram/confsubmissions instance
 * chain this confscheduler is configured against, and the GapSnap/overlap
 * checks). Those checks answer "does this data make sense", not "is this user
 * allowed to try this" -- both matter, and both must be enforced somewhere; the
 * ownership/integrity half belongs here because it is intrinsic to the data
 * model, not to any particular caller's capabilities.
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
     * Does not eager-load room(s) or submission details; see
     * \mod_confscheduler\local\grid_data::build() for the decorated version the
     * grid AJAX endpoint returns, which avoids the N+1 pattern that would
     * result from resolving those per-slot here.
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
     * Validates that a colour is either null or a 6-digit hex colour
     * (e.g. #3366cc).
     *
     * This value is interpolated into a style="..." attribute when the grid is
     * rendered, so it must be strictly validated (and rejected, not
     * silently sanitised/truncated) before it is ever stored.
     *
     * @param string|null $colour
     * @return void
     * @throws \invalid_parameter_exception if $colour is non-null and not a valid hex colour
     */
    protected static function validate_colour(?string $colour): void {
        if ($colour === null) {
            return;
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
            throw new \invalid_parameter_exception(get_string('error:invalidcolour', 'mod_confscheduler'));
        }
    }

    /**
     * Creates a room (column) in a confscheduler instance.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param string $name The room name
     * @param int|null $sortorder The column order; null to append at the end (max existing + 1)
     * @param string|null $colour A hex colour (e.g. #3366cc), or null for no theme
     * @return int The confscheduler_room id
     * @throws \invalid_parameter_exception if $colour is set and not a valid hex colour
     */
    public static function add_room(int $confschedulerid, string $name, ?int $sortorder = null, ?string $colour = null): int {
        global $DB;

        self::validate_colour($colour);

        if ($sortorder === null) {
            $max = $DB->get_field_sql(
                'SELECT MAX(sortorder) FROM {confscheduler_room} WHERE confscheduler = ?',
                [$confschedulerid]
            );
            $sortorder = $max === false || $max === null ? 0 : ((int) $max + 1);
        }

        return $DB->insert_record('confscheduler_room', (object) [
            'confscheduler' => $confschedulerid,
            'name'          => $name,
            'sortorder'     => $sortorder,
            'colour'        => $colour,
        ]);
    }

    /**
     * Updates a room's name and/or colour theme.
     *
     * @param int $roomid The confscheduler_room id
     * @param string $name The new room name
     * @param string|null $colour A hex colour (e.g. #3366cc), or null to clear the theme
     * @return void
     * @throws \invalid_parameter_exception if $colour is set and not a valid hex colour
     */
    public static function update_room(int $roomid, string $name, ?string $colour): void {
        global $DB;

        self::validate_colour($colour);

        $room = $DB->get_record('confscheduler_room', ['id' => $roomid], '*', MUST_EXIST);

        $DB->update_record('confscheduler_room', (object) [
            'id'     => $room->id,
            'name'   => $name,
            'colour' => $colour,
        ]);
    }

    /**
     * Deletes a room.
     *
     * Design decision: deleting a room that still has scheduled slots (of
     * either kind: single-room presentations or column-spanning blocks) in it
     * is refused outright, rather than silently cascade-deleting the slot (for
     * a single-room slot, that would destroy a schedule entry the organiser
     * may not have intended to remove) or silently shrinking a spanning
     * block's room set (there is no UI to remove a single room from an
     * existing span, so doing this implicitly here would leave the block in a
     * state the organiser cannot see or undo). The caller must unschedule (or,
     * for a spanning block, delete and recreate over the remaining rooms)
     * first.
     *
     * @param int $roomid The confscheduler_room id
     * @return void
     * @throws \moodle_exception if the room still has any scheduled slot referencing it
     */
    public static function delete_room(int $roomid): void {
        global $DB;

        $DB->get_record('confscheduler_room', ['id' => $roomid], '*', MUST_EXIST);

        if ($DB->record_exists('confscheduler_slotroom', ['roomid' => $roomid])) {
            throw new \moodle_exception('error:roomhasslots', 'mod_confscheduler');
        }

        $DB->delete_records('confscheduler_room', ['id' => $roomid]);
    }

    /**
     * Rewrites the sortorder of all given rooms to match the given order
     * (0-indexed), scoped to a single confscheduler instance.
     *
     * The full set of room ids belonging to the instance must be given (in
     * the new desired order); the call is rejected outright (nothing is
     * written) if any given id does not belong to $confschedulerid, rather
     * than silently skipping unknown ids, since a partial reorder would leave
     * the room list in a state the caller did not ask for.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int[] $roomidsinorder Room ids in the desired left-to-right order
     * @return void
     * @throws \invalid_parameter_exception if any room id does not belong to this instance
     */
    public static function reorder_rooms(int $confschedulerid, array $roomidsinorder): void {
        global $DB;

        $uniqueids = array_values(array_unique(array_map('intval', $roomidsinorder)));
        if (empty($uniqueids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($uniqueids, SQL_PARAMS_NAMED);
        $params['confschedulerid'] = $confschedulerid;
        $count = $DB->count_records_select(
            'confscheduler_room',
            "confscheduler = :confschedulerid AND id $insql",
            $params
        );
        if ($count !== count($uniqueids)) {
            throw new \invalid_parameter_exception(get_string('error:invalidroom', 'mod_confscheduler'));
        }

        $sortorder = 0;
        foreach ($uniqueids as $roomid) {
            $DB->set_field('confscheduler_room', 'sortorder', $sortorder, [
                'id'            => $roomid,
                'confscheduler' => $confschedulerid,
            ]);
            $sortorder++;
        }
    }

    /**
     * Validates that a submissionid may legitimately be scheduled by a given
     * confscheduler instance: it must (a) exist, (b) belong to the
     * mod_confsubmissions instance that the confprogram instance linked via
     * confscheduler.confprogramcmid itself vets, and (c) have been accepted
     * ('accept' decision) by that specific confprogram instance.
     *
     * This is the core chain-of-custody check: confsubmissions_submission ids
     * are globally unique across the whole site, not scoped per course, so
     * without this check an unvalidated write could pull another course's
     * (or another confprogram instance's) submission/decision data into this
     * confscheduler's schedule.
     *
     * Uses \mod_confprogram\api::get_decision(), which is NOT itself scoped by
     * confprogram instance (it returns the submission's overall most recent
     * decision, across every confprogram instance that has ever decided it) --
     * that global-latest decision is then checked here for BOTH decision ===
     * 'accept' AND decision->confprogram === the confprogram instance this
     * confscheduler is linked to, which is what actually makes the check
     * instance-scoped and correct.
     *
     * @param \stdClass $confscheduler The confscheduler record
     * @param int $submissionid The mod_confsubmissions confsubmissions_submission id
     * @return void
     * @throws \moodle_exception if the submission does not exist, belongs to a different
     *         confsubmissions instance, or has not been accepted by the linked confprogram instance
     */
    protected static function validate_submission_chain_of_custody(\stdClass $confscheduler, int $submissionid): void {
        global $DB;

        $confprogramcm = get_coursemodule_from_id('confprogram', $confscheduler->confprogramcmid, 0, false, MUST_EXIST);
        $confprogram = $DB->get_record('confprogram', ['id' => $confprogramcm->instance], '*', MUST_EXIST);

        $submission = \mod_confsubmissions\api::get_submission($submissionid);
        if (!$submission) {
            throw new \moodle_exception('error:invalidsubmission', 'mod_confscheduler');
        }

        $confsubmissionscm = get_coursemodule_from_id(
            'confsubmissions',
            $confprogram->confsubmissionscmid,
            0,
            false,
            MUST_EXIST
        );
        if ((int) $submission->confsubmissions !== (int) $confsubmissionscm->instance) {
            throw new \moodle_exception('error:invalidsubmission', 'mod_confscheduler');
        }

        $decision = \mod_confprogram\api::get_decision($submissionid);
        if ($decision === null || $decision->decision !== 'accept' || (int) $decision->confprogram !== (int) $confprogram->id) {
            throw new \moodle_exception('error:invalidsubmission', 'mod_confscheduler');
        }
    }

    /**
     * Public wrapper around validate_submission_chain_of_custody(), for callers
     * that need to assert the same chain-of-custody without going through
     * add_slot()/update_slot() -- specifically, the favourite-toggle external
     * function (requirement: the favourited submission must belong to this
     * confscheduler instance's linked confprogram/confsubmissions chain, not
     * merely to *a* slot that happens to carry the given id).
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $submissionid The mod_confsubmissions confsubmissions_submission id
     * @return void
     * @throws \moodle_exception if the submission does not exist, belongs to a different
     *         confsubmissions instance, or has not been accepted by the linked confprogram instance
     */
    public static function assert_submission_belongs_to_instance(int $confschedulerid, int $submissionid): void {
        global $DB;

        $confscheduler = $DB->get_record('confscheduler', ['id' => $confschedulerid], '*', MUST_EXIST);
        self::validate_submission_chain_of_custody($confscheduler, $submissionid);
    }

    /**
     * Validates a proposed (or updated) slot placement: that every given room
     * belongs to the instance, that it does not truly overlap any other slot
     * already scheduled in any of the same rooms, and (when
     * confscheduler.gapminutes > 0) that it respects the configured GapSnap
     * minimum gap from every other slot in the same room(s).
     *
     * Boundary handling: a slot ending exactly at T and another starting
     * exactly at T are flush (gap = 0). That is allowed when gapminutes is 0,
     * and rejected when gapminutes > 0 (the gap must be >= gapminutes minutes,
     * inclusive: a gap of exactly gapminutes is allowed, anything less is
     * not).
     *
     * Two column-spanning blocks (both have submissionid === null) are exempt
     * from the GapSnap check (but never from the true-overlap check): flush
     * adjacency between e.g. a Lunch block and a following Plenary block is
     * normal and should not require an artificial gap.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int[] $roomids The room id(s) this slot occupies
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @param int|null $submissionid The submission this slot is for, or null for a span-block
     * @param int $gapminutes The instance's configured GapSnap minimum gap, in minutes
     * @param int|null $excludeslotid A slot id to exclude from the conflict check (when rescheduling it)
     * @return void
     * @throws \invalid_parameter_exception if $roomids is empty or references a room outside this instance
     * @throws \moodle_exception if the time range is invalid, overlaps another slot, or violates GapSnap
     */
    protected static function validate_placement(
        int $confschedulerid,
        array $roomids,
        int $starttime,
        int $endtime,
        ?int $submissionid,
        int $gapminutes,
        ?int $excludeslotid = null
    ): void {
        global $DB;

        if ($endtime <= $starttime) {
            throw new \moodle_exception('error:invalidtimerange', 'mod_confscheduler');
        }

        $uniqueroomids = array_values(array_unique(array_map('intval', $roomids)));
        if (empty($uniqueroomids)) {
            throw new \invalid_parameter_exception(get_string('error:invalidroom', 'mod_confscheduler'));
        }

        [$roomsql, $roomparams] = $DB->get_in_or_equal($uniqueroomids, SQL_PARAMS_NAMED, 'room');
        $roomparams['confschedulerid'] = $confschedulerid;
        $validcount = $DB->count_records_select(
            'confscheduler_room',
            "confscheduler = :confschedulerid AND id $roomsql",
            $roomparams
        );
        if ($validcount !== count($uniqueroomids)) {
            throw new \invalid_parameter_exception(get_string('error:invalidroom', 'mod_confscheduler'));
        }

        [$roomsql2, $params] = $DB->get_in_or_equal($uniqueroomids, SQL_PARAMS_NAMED, 'r');
        $params['confschedulerid2'] = $confschedulerid;
        $sql = "SELECT DISTINCT s.id, s.starttime, s.endtime, s.submissionid
                  FROM {confscheduler_slot} s
                  JOIN {confscheduler_slotroom} sr ON sr.slotid = s.id
                 WHERE s.confscheduler = :confschedulerid2 AND sr.roomid $roomsql2";
        if ($excludeslotid !== null) {
            $sql .= ' AND s.id <> :excludeslotid';
            $params['excludeslotid'] = $excludeslotid;
        }
        $others = $DB->get_records_sql($sql, $params);

        $isspanblock = $submissionid === null;
        $gapseconds = $gapminutes * MINSECS;

        foreach ($others as $other) {
            $otherstart = (int) $other->starttime;
            $otherend = (int) $other->endtime;

            $overlaps = $starttime < $otherend && $endtime > $otherstart;
            if ($overlaps) {
                throw new \moodle_exception('error:timeoverlap', 'mod_confscheduler');
            }

            if ($gapseconds <= 0) {
                continue;
            }

            $otherisspanblock = $other->submissionid === null;
            if ($isspanblock && $otherisspanblock) {
                continue;
            }

            $gap = $starttime >= $otherend ? ($starttime - $otherend) : ($otherstart - $endtime);
            if ($gap < $gapseconds) {
                throw new \moodle_exception('error:gapviolation', 'mod_confscheduler');
            }
        }
    }

    /**
     * Creates a scheduled slot (either a presentation or a column-spanning
     * block) and assigns it to one or more rooms.
     *
     * When $submissionid is given, validates the full chain-of-custody (see
     * validate_submission_chain_of_custody()) before inserting -- this is the
     * main security property of the scheduling feature: a submissionid must
     * genuinely belong to (and be accepted by) the confprogram/confsubmissions
     * instance chain this confscheduler instance is configured against.
     *
     * Also validates GapSnap/overlap placement (see validate_placement()) for
     * every given room.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int[] $roomids The room id(s) this slot occupies; more than one for a spanning block
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @param int|null $submissionid The mod_confsubmissions confsubmissions_submission id; null for a span-block
     * @param string|null $label Used only when submissionid is null, e.g. "Lunch Break"
     * @return int The confscheduler_slot id
     * @throws \moodle_exception if the submission's chain of custody is invalid, or the placement
     *         overlaps/violates GapSnap
     * @throws \invalid_parameter_exception if a room does not belong to this instance
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

        $confscheduler = $DB->get_record('confscheduler', ['id' => $confschedulerid], '*', MUST_EXIST);

        if ($submissionid !== null) {
            self::validate_submission_chain_of_custody($confscheduler, $submissionid);
        }

        self::validate_placement(
            $confschedulerid,
            $roomids,
            $starttime,
            $endtime,
            $submissionid,
            (int) $confscheduler->gapminutes
        );

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

        foreach (array_unique(array_map('intval', $roomids)) as $roomid) {
            $DB->insert_record('confscheduler_slotroom', (object) [
                'slotid' => $slotid,
                'roomid' => $roomid,
            ]);
        }

        return $slotid;
    }

    /**
     * Reschedules an existing slot to a new time range and/or room set.
     *
     * Re-runs the same GapSnap/overlap validation as add_slot(), excluding
     * the slot's own current confscheduler_slotroom rows from the conflict
     * check (so a slot never conflicts with itself). The slot's submissionid
     * and label are left untouched; chain-of-custody was already validated
     * when the slot was first created, and does not need re-validating on a
     * pure time/room move.
     *
     * @param int $slotid The confscheduler_slot id
     * @param int[] $roomids The new room id(s) this slot should occupy
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @return void
     * @throws \moodle_exception if the new placement overlaps/violates GapSnap
     * @throws \invalid_parameter_exception if a room does not belong to this instance
     */
    public static function update_slot(int $slotid, array $roomids, int $starttime, int $endtime): void {
        global $DB;

        $slot = $DB->get_record('confscheduler_slot', ['id' => $slotid], '*', MUST_EXIST);
        $confscheduler = $DB->get_record('confscheduler', ['id' => $slot->confscheduler], '*', MUST_EXIST);

        self::validate_placement(
            (int) $slot->confscheduler,
            $roomids,
            $starttime,
            $endtime,
            $slot->submissionid !== null ? (int) $slot->submissionid : null,
            (int) $confscheduler->gapminutes,
            (int) $slotid
        );

        $DB->update_record('confscheduler_slot', (object) [
            'id'           => $slot->id,
            'starttime'    => $starttime,
            'endtime'      => $endtime,
            'timemodified' => time(),
        ]);

        $DB->delete_records('confscheduler_slotroom', ['slotid' => $slotid]);
        foreach (array_unique(array_map('intval', $roomids)) as $roomid) {
            $DB->insert_record('confscheduler_slotroom', (object) [
                'slotid' => $slotid,
                'roomid' => $roomid,
            ]);
        }
    }

    /**
     * Unschedules a slot: removes it and its confscheduler_slotroom rows.
     * When the slot was a presentation (non-null submissionid), this returns
     * that submission to the "unscheduled" panel.
     *
     * @param int $slotid The confscheduler_slot id
     * @return void
     */
    public static function delete_slot(int $slotid): void {
        global $DB;

        $DB->delete_records('confscheduler_slotroom', ['slotid' => $slotid]);
        $DB->delete_records('confscheduler_slot', ['id' => $slotid]);
    }
}
