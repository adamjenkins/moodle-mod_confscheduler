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
 * chain this confscheduler is configured against, and the SnapGap/overlap
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
     * Fallback presentation duration, in minutes, for a submission with no
     * mod_confsubmissions submission type assigned (an instance with none
     * configured yet, or a submission that predates the feature) -- used both when
     * dragging a card out of the unscheduled panel and by run_autoscheduler(), so a
     * submission's own chosen type is always preferred but a missing one never
     * blocks scheduling. Not a setting: Revision round 1 (2026-07-04) replaced the
     * former per-instance/per-run "default duration" input with this fixed,
     * last-resort value once every submission carries its own type-based duration.
     */
    public const DEFAULT_DURATION_MINUTES = 30;

    /** @var int Default row height (vertical pixels per hour), also install.xml's schema default. */
    public const DEFAULT_PX_PER_HOUR = 144;

    /** @var int Lowest row height a user may configure -- below this, blocks become illegibly thin. */
    public const MIN_PX_PER_HOUR = 60;

    /** @var int Highest row height a user may configure -- above this, a normal conference day scrolls absurdly long. */
    public const MAX_PX_PER_HOUR = 480;

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
     * Sets a confscheduler instance's SnapGap minimum gap. Moved here from a
     * mod_form.php field (Revision round 1 follow-up, 2026-07-04 -- see
     * classes/external/set_gap_minutes.php's docblock for the full reasoning).
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $gapminutes The new SnapGap minimum gap, in minutes
     * @return void
     * @throws \invalid_parameter_exception if $gapminutes is negative
     */
    public static function set_gap_minutes(int $confschedulerid, int $gapminutes): void {
        global $DB;

        if ($gapminutes < 0) {
            throw new \invalid_parameter_exception(get_string('error:invalidnumber', 'mod_confscheduler'));
        }

        $DB->set_field('confscheduler', 'gapminutes', $gapminutes, ['id' => $confschedulerid]);
    }

    /**
     * Sets a confscheduler instance's row height (vertical pixels per hour of scheduled
     * time), organiser-adjustable via a quick control at the top of the schedule grid in
     * edit mode -- same pattern as set_gap_minutes() above.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $pxperhour The new row height, in pixels per hour
     * @return void
     * @throws \invalid_parameter_exception if $pxperhour is outside [MIN_PX_PER_HOUR, MAX_PX_PER_HOUR]
     */
    public static function set_pxperhour(int $confschedulerid, int $pxperhour): void {
        global $DB;

        if ($pxperhour < self::MIN_PX_PER_HOUR || $pxperhour > self::MAX_PX_PER_HOUR) {
            throw new \invalid_parameter_exception(get_string('error:invalidpxperhour', 'mod_confscheduler'));
        }

        $DB->set_field('confscheduler', 'pxperhour', $pxperhour, ['id' => $confschedulerid]);
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
     * confscheduler.gapminutes > 0) that it respects the configured SnapGap
     * minimum gap from every other slot in the same room(s).
     *
     * Boundary handling: a slot ending exactly at T and another starting
     * exactly at T are flush (gap = 0). That is allowed when gapminutes is 0,
     * and rejected when gapminutes > 0 (the gap must be >= gapminutes minutes,
     * inclusive: a gap of exactly gapminutes is allowed, anything less is
     * not).
     *
     * Two column-spanning blocks (both have submissionid === null) are exempt
     * from the SnapGap check (but never from the true-overlap check): flush
     * adjacency between e.g. a Lunch block and a following Plenary block is
     * normal and should not require an artificial gap.
     *
     * ****************************************************************************
     * IMPORTANT -- kept in sync BY HAND with amd/src/gapsnap_utils.js (Revision
     * round 1 batch B, 2026-07-03). That module re-implements this exact
     * overlap/gap logic client-side so a drag-and-drop drop can be auto-nudged
     * to the nearest valid position instead of being submitted invalid and
     * hard-rejected -- there is no way to call this PHP method synchronously
     * mid-drag on the client, so this is the one place in the project where the
     * same validation logic genuinely needs to exist in two languages. THIS
     * method remains the sole authoritative check regardless of what the client
     * computes: gapsnap_utils.js's nudge is a UX convenience only, and every
     * position it computes is still submitted here and re-validated for real.
     * If this method's overlap/gap math ever changes, gapsnap_utils.js's
     * overlapsOrViolatesGap()/requiredGapSeconds() must change identically.
     * ****************************************************************************
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int[] $roomids The room id(s) this slot occupies
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @param int|null $submissionid The submission this slot is for, or null for a span-block
     * @param int $gapminutes The instance's configured SnapGap minimum gap, in minutes
     * @param int|null $excludeslotid A slot id to exclude from the conflict check (when rescheduling it)
     * @param int|null $conferencestart The instance's configured conference start, unix timestamp, or null if unset
     * @param int|null $conferenceend The instance's configured conference end, unix timestamp, or null if unset
     * @return void
     * @throws \invalid_parameter_exception if $roomids is empty or references a room outside this instance
     * @throws \moodle_exception if the time range is invalid, falls outside the conference dates (when
     *         both are set), overlaps another slot, or violates SnapGap
     */
    protected static function validate_placement(
        int $confschedulerid,
        array $roomids,
        int $starttime,
        int $endtime,
        ?int $submissionid,
        int $gapminutes,
        ?int $excludeslotid = null,
        ?int $conferencestart = null,
        ?int $conferenceend = null
    ): void {
        global $DB;

        if ($endtime <= $starttime) {
            throw new \moodle_exception('error:invalidtimerange', 'mod_confscheduler');
        }

        // Out-of-conference-hours check (user feedback, 2026-07-05): only enforced
        // when BOTH bounds are actually set -- an existing instance saved before
        // conference dates were made a required field may still have neither, and
        // must keep working exactly as before rather than suddenly rejecting every
        // placement. Client-side, amd/src/conference_bounds_utils.js mirrors this
        // exact check to auto-nudge a drag back inside the range (the same
        // "bounce back rather than hard-reject" pattern already established for
        // SnapGap in amd/src/snapgap_utils.js) -- this method remains the sole
        // authoritative check regardless of what the client computes or submits.
        if ($conferencestart !== null && $conferenceend !== null) {
            if ($starttime < $conferencestart || $endtime > $conferenceend) {
                throw new \moodle_exception('error:outsideconferencedates', 'mod_confscheduler');
            }
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
     * Also validates SnapGap/overlap placement (see validate_placement()) for
     * every given room.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int[] $roomids The room id(s) this slot occupies; more than one for a spanning block
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @param int|null $submissionid The mod_confsubmissions confsubmissions_submission id; null for a span-block
     * @param string|null $label Used only when submissionid is null, e.g. "Lunch Break"
     * @param string|null $colour A hex colour (e.g. #3366cc) to theme a span block with; must be null when
     *        $submissionid is non-null (colour theming applies only to span blocks, Revision round 1)
     * @return int The confscheduler_slot id
     * @throws \moodle_exception if the submission's chain of custody is invalid, or the placement
     *         overlaps/violates SnapGap
     * @throws \invalid_parameter_exception if a room does not belong to this instance, $colour is set and not a
     *         valid hex colour, or $colour is given together with a non-null $submissionid
     */
    public static function add_slot(
        int $confschedulerid,
        array $roomids,
        int $starttime,
        int $endtime,
        ?int $submissionid = null,
        ?string $label = null,
        ?string $colour = null
    ): int {
        global $DB;

        self::validate_colour($colour);
        if ($colour !== null && $submissionid !== null) {
            throw new \invalid_parameter_exception(get_string('error:notaspanblock', 'mod_confscheduler'));
        }

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
            (int) $confscheduler->gapminutes,
            null,
            $confscheduler->conferencestart !== null ? (int) $confscheduler->conferencestart : null,
            $confscheduler->conferenceend !== null ? (int) $confscheduler->conferenceend : null
        );

        $now = time();
        $slotid = $DB->insert_record('confscheduler_slot', (object) [
            'confscheduler' => $confschedulerid,
            'submissionid'  => $submissionid,
            'label'         => $label,
            'colour'        => $colour,
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
     * Re-runs the same SnapGap/overlap validation as add_slot(), excluding
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
     * @throws \moodle_exception if the new placement overlaps/violates SnapGap
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
            (int) $slotid,
            $confscheduler->conferencestart !== null ? (int) $confscheduler->conferencestart : null,
            $confscheduler->conferenceend !== null ? (int) $confscheduler->conferenceend : null
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
     * Updates an existing span block's label, colour, time range, and room-range.
     *
     * Span blocks (submissionid IS NULL) previously supported only add/delete; this
     * (Revision round 1, 2026-07-03) is the edit-in-place path. Deliberately refuses to
     * operate on a presentation slot (submissionid non-null) -- editing a presentation's
     * label/colour has no meaning, and colour theming is scoped to span blocks only, so
     * this is a data-integrity check that belongs here (see this class's docblock on
     * where capability checks vs. data-integrity checks live), not merely a capability
     * check delegated to the caller.
     *
     * Re-runs the same SnapGap/overlap validation as update_slot(), excluding the slot's
     * own current confscheduler_slotroom rows from the conflict check.
     *
     * @param int $slotid The confscheduler_slot id (must be a span block)
     * @param string $label The new label
     * @param string|null $colour A hex colour (e.g. #3366cc), or null to clear the theme
     * @param int[] $roomids The new room id(s) this block should span
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @return void
     * @throws \moodle_exception if the slot is not a span block, or the new placement overlaps/violates SnapGap
     * @throws \invalid_parameter_exception if a room does not belong to this instance, or $colour is set and
     *         not a valid hex colour
     */
    public static function update_span_block(
        int $slotid,
        string $label,
        ?string $colour,
        array $roomids,
        int $starttime,
        int $endtime
    ): void {
        global $DB;

        self::validate_colour($colour);

        $slot = $DB->get_record('confscheduler_slot', ['id' => $slotid], '*', MUST_EXIST);
        if ($slot->submissionid !== null) {
            throw new \moodle_exception('error:notaspanblock', 'mod_confscheduler');
        }

        $confscheduler = $DB->get_record('confscheduler', ['id' => $slot->confscheduler], '*', MUST_EXIST);

        self::validate_placement(
            (int) $slot->confscheduler,
            $roomids,
            $starttime,
            $endtime,
            null,
            (int) $confscheduler->gapminutes,
            (int) $slotid,
            $confscheduler->conferencestart !== null ? (int) $confscheduler->conferencestart : null,
            $confscheduler->conferenceend !== null ? (int) $confscheduler->conferenceend : null
        );

        $DB->update_record('confscheduler_slot', (object) [
            'id'           => $slot->id,
            'label'        => $label,
            'colour'       => $colour,
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

    /**
     * Runs the autoscheduler: places as many accepted-but-unscheduled
     * submissions as it can into the given time window, honouring SnapGap and
     * overlap via add_slot() for every placement it makes.
     *
     * Candidate pool: exactly the same "accepted, unscheduled" set the grid's
     * unscheduled panel shows (see \mod_confscheduler\local\grid_data::build()).
     * Each candidate is given its own mod_confsubmissions submission type's
     * duration (falling back to DEFAULT_DURATION_MINUTES if it has none) -- this
     * method previously took one $defaultdurationminutes parameter applied
     * uniformly to every placement, but that became meaningless once every
     * submission carries its own type-based duration (Revision round 1,
     * 2026-07-04), so the parameter and its "Run autoscheduler" form field were
     * both removed rather than left as a confusing, ignored input.
     *
     * Placement priority (highest first):
     *   1. Submissions sharing a trackid are placed with a same-room
     *      preference (the room the first successfully-placed member of the
     *      track group lands in is preferred, but not required, for the
     *      rest) and a best-effort preference for time slots that do not
     *      overlap another same-track submission already scheduled in a
     *      *different* room.
     *   2. Everything else (no track) is placed with no grouping preference.
     * Processing order *within* each of the two tiers above is itself
     * shuffled (which track goes first, the order ungrouped submissions are
     * attempted in) via the optional $seed parameter, so repeated runs over
     * the same input vary while still respecting the priority rules -- see
     * make_random_source()'s docblock for why a self-contained seedable
     * generator is used instead of mt_srand()/shuffle() (which would mutate
     * global PHP RNG state). The order rooms are searched in is *also*
     * freshly shuffled for every individual placement attempt (see
     * try_place_single()'s docblock) -- without that, the room-preference
     * mechanism used for track groups would be a no-op, since a fixed room
     * order always fills the same "first" room to capacity before ever
     * trying the next one anyway.
     *
     * Removed (Revision round 1 batch B, 2026-07-03): this method previously
     * had a higher-priority tier ("same-session-tag consecutive-same-room")
     * above track grouping, backed by a plugin-local confscheduler_sessiontag
     * table and api::set_session_tag()/get_session_tags(). The "session"
     * tagging feature was removed entirely per explicit user feedback; the
     * former priority 2/3 (track grouping / ungrouped) tiers above are now
     * priority 1/2, unchanged in their own logic.
     *
     * Placement search: rather than re-implementing validate_placement()'s
     * SnapGap/overlap math a second time (which would risk drifting out of
     * sync with it), every candidate placement this method considers is
     * attempted via add_slot() itself, wrapped in a try/catch (see
     * attempt_place()) -- a rejected candidate throws, is caught, and the
     * search moves on to the next candidate; nothing is written on a
     * rejected attempt, and a successful attempt IS the real, final
     * placement (there is no separate "simulate then commit" step). This
     * trades a small amount of redundant validation-query overhead per
     * rejected candidate for a guarantee that the autoscheduler can never
     * place something add_slot() would have refused.
     *
     * Candidate start times, per room, are not a brute-force scan of the
     * whole window: they are $windowstart plus (existing slot's endtime +
     * gap) for every slot already in that room, restricted to times that
     * still leave room for the full duration before $windowend. This mirrors
     * how a human would look for the next free gap, and keeps the number of
     * add_slot() attempts proportional to the number of slots already
     * scheduled in a room, not to the window's length.
     *
     * If $clearfirst is true, every existing slot in this instance that
     * overlaps [$windowstart, $windowend) (using the identical overlap
     * definition validate_placement() uses: starttime < windowend AND endtime
     * > windowstart) is deleted via delete_slot() before any new placement is
     * attempted. Slots entirely outside the window are never touched.
     *
     * Partial failure is not fatal: a candidate that cannot be placed
     * anywhere in the window after a reasonable search is skipped and
     * recorded in the returned summary's skippedreasons, rather than
     * aborting the whole run.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $windowstart Unix timestamp; start of the window to schedule into
     * @param int $windowend Unix timestamp; end of the window to schedule into
     * @param bool $clearfirst Whether to first clear existing slots that overlap the window
     * @param int|null $seed Optional seed for deterministic randomisation (tests only); null (the
     *        default) means production callers get a different, non-reproducible ordering each run
     * @return array{scheduled: int, skipped: int, skippedreasons: array} Run summary
     * @throws \invalid_parameter_exception if $windowend <= $windowstart
     */
    public static function run_autoscheduler(
        int $confschedulerid,
        int $windowstart,
        int $windowend,
        bool $clearfirst,
        ?int $seed = null
    ): array {
        global $DB;

        if ($windowend <= $windowstart) {
            throw new \invalid_parameter_exception(get_string('error:invalidtimerange', 'mod_confscheduler'));
        }

        $confscheduler = $DB->get_record('confscheduler', ['id' => $confschedulerid], '*', MUST_EXIST);
        $gapseconds = (int) $confscheduler->gapminutes * MINSECS;

        if ($clearfirst) {
            self::clear_slots_in_window($confschedulerid, $windowstart, $windowend);
        }

        $rooms = self::get_rooms($confschedulerid);
        $roomids = array_values(array_map(static fn($room) => (int) $room->id, $rooms));

        $randomsource = self::make_random_source($seed);

        $summary = ['scheduled' => 0, 'skipped' => 0, 'skippedreasons' => []];

        // Candidate pool: exactly what the grid's unscheduled panel shows, minus
        // anything already scheduled (see grid_data::build() for the equivalent
        // read-side logic this mirrors).
        $confprogramcm = get_coursemodule_from_id('confprogram', $confscheduler->confprogramcmid, 0, false, MUST_EXIST);
        $confprogram = $DB->get_record('confprogram', ['id' => $confprogramcm->instance], '*', MUST_EXIST);
        $confsubmissionscm = get_coursemodule_from_id(
            'confsubmissions',
            $confprogram->confsubmissionscmid,
            0,
            false,
            MUST_EXIST
        );

        $accepted = \mod_confprogram\local\display_list::get_accepted_submissions(
            (int) $confprogram->id,
            (int) $confsubmissionscm->instance
        );

        // Per-submission duration, from its own mod_confsubmissions submission type
        // (falling back to DEFAULT_DURATION_MINUTES for a submission with none) --
        // see this method's docblock for why there is no longer a single uniform
        // duration parameter.
        $typedurationsbyid = [];
        foreach (\mod_confsubmissions\api::get_submission_types($confsubmissionscm->id) as $submissiontype) {
            $typedurationsbyid[(int) $submissiontype->id] = (int) $submissiontype->durationminutes;
        }
        $durationsecondsfor = static function (\stdClass $submission) use ($typedurationsbyid): int {
            $typeid = !empty($submission->submissiontypeid) ? (int) $submission->submissiontypeid : null;
            $minutes = ($typeid !== null && isset($typedurationsbyid[$typeid]))
                ? $typedurationsbyid[$typeid]
                : self::DEFAULT_DURATION_MINUTES;
            return $minutes * MINSECS;
        };

        // Preferred conference days (user feedback, 2026-07-05): an empty array means
        // "no preference recorded" (see mod_confsubmissions\api::get_date_preferences()'s
        // docblock) and must not restrict placement at all -- most submissions predate
        // this feature or belong to an instance that never enabled it.
        $preferreddaysfor = static function (\stdClass $submission): array {
            return \mod_confsubmissions\api::get_date_preferences((int) $submission->id);
        };

        $alreadyscheduled = [];
        foreach (self::get_slots($confschedulerid) as $slot) {
            if ($slot->submissionid !== null) {
                $alreadyscheduled[(int) $slot->submissionid] = true;
            }
        }

        $candidates = [];
        foreach ($accepted as $submission) {
            $sid = (int) $submission->id;
            if (isset($alreadyscheduled[$sid])) {
                continue;
            }
            $candidates[$sid] = $submission;
        }

        if (empty($roomids)) {
            foreach ($candidates as $submission) {
                $summary['skipped']++;
                $summary['skippedreasons'][] = self::autoscheduler_skip_reason($submission, 'noroomsconfigured');
            }
            return $summary;
        }

        // Group candidates: track groups (priority 1) take every submission with a
        // trackid; anything without one is ungrouped (priority 2). "Session" tagging
        // (formerly an even-higher priority tier above track grouping) was removed
        // entirely (Revision round 1 batch B, 2026-07-03) -- see this method's docblock.
        $trackgroups = [];
        $ungrouped = [];
        foreach ($candidates as $submission) {
            $trackid = !empty($submission->trackid) ? (int) $submission->trackid : null;
            if ($trackid !== null) {
                $trackgroups[$trackid][] = $submission;
            } else {
                $ungrouped[] = $submission;
            }
        }

        // Stable, deterministic order *within* a group (not shuffled -- only
        // the order groups/submissions are processed IN is shuffled, per the
        // docblock above).
        $byidasc = static function (\stdClass $first, \stdClass $second): int {
            return $first->id <=> $second->id;
        };
        foreach ($trackgroups as &$members) {
            usort($members, $byidasc);
        }
        unset($members);

        $trackids = self::fisher_yates_shuffle(array_keys($trackgroups), $randomsource);
        $ungrouped = self::fisher_yates_shuffle($ungrouped, $randomsource);

        $trackidcache = [];

        // Priority 1: track groups (same-room preference, soft same-track/
        // different-room overlap avoidance).
        foreach ($trackids as $trackid) {
            $preferredroomid = null;
            foreach ($trackgroups[$trackid] as $submission) {
                $placed = self::try_place_single(
                    $confschedulerid,
                    (int) $submission->id,
                    $roomids,
                    $durationsecondsfor($submission),
                    $windowstart,
                    $windowend,
                    $gapseconds,
                    $preferredroomid,
                    $randomsource,
                    $trackid,
                    $trackidcache,
                    $preferreddaysfor($submission)
                );
                if ($placed !== null) {
                    $summary['scheduled']++;
                    if ($preferredroomid === null) {
                        $preferredroomid = $placed['roomid'];
                    }
                } else {
                    $summary['skipped']++;
                    $summary['skippedreasons'][] = self::autoscheduler_skip_reason($submission, 'nofit');
                }
            }
        }

        // Priority 2: everything else, no grouping preference.
        foreach ($ungrouped as $submission) {
            $placed = self::try_place_single(
                $confschedulerid,
                (int) $submission->id,
                $roomids,
                $durationsecondsfor($submission),
                $windowstart,
                $windowend,
                $gapseconds,
                null,
                $randomsource,
                null,
                $trackidcache,
                $preferreddaysfor($submission)
            );
            if ($placed !== null) {
                $summary['scheduled']++;
            } else {
                $summary['skipped']++;
                $summary['skippedreasons'][] = self::autoscheduler_skip_reason($submission, 'nofit');
            }
        }

        return $summary;
    }

    /**
     * Deletes every existing slot in a confscheduler instance that overlaps a
     * given window, using the identical overlap definition validate_placement()
     * uses. Used by run_autoscheduler() when $clearfirst is true.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $windowstart Unix timestamp
     * @param int $windowend Unix timestamp
     * @return void
     */
    protected static function clear_slots_in_window(int $confschedulerid, int $windowstart, int $windowend): void {
        global $DB;

        $slots = $DB->get_records_select(
            'confscheduler_slot',
            'confscheduler = :confschedulerid AND starttime < :windowend AND endtime > :windowstart',
            ['confschedulerid' => $confschedulerid, 'windowend' => $windowend, 'windowstart' => $windowstart]
        );

        foreach ($slots as $slot) {
            self::delete_slot((int) $slot->id);
        }
    }

    /**
     * Returns every currently-scheduled slot occupying a specific room, in
     * start-time order. Queried fresh (not cached) each call, since
     * run_autoscheduler() commits placements to the database as it goes, and
     * each subsequent search must see them.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $roomid The confscheduler_room id
     * @return \stdClass[] Records with id, starttime, endtime, submissionid
     */
    protected static function get_slots_in_room(int $confschedulerid, int $roomid): array {
        global $DB;

        $sql = "SELECT s.id, s.starttime, s.endtime, s.submissionid
                  FROM {confscheduler_slot} s
                  JOIN {confscheduler_slotroom} sr ON sr.slotid = s.id
                 WHERE s.confscheduler = :confschedulerid AND sr.roomid = :roomid
              ORDER BY s.starttime ASC";

        return array_values($DB->get_records_sql($sql, [
            'confschedulerid' => $confschedulerid,
            'roomid'          => $roomid,
        ]));
    }

    /**
     * Computes the candidate start times to try in a single room: the window
     * start, plus (every existing slot's endtime + gap) in that room,
     * restricted to times that still leave room for the full duration before
     * the window ends. See run_autoscheduler()'s docblock for why this is
     * used instead of a brute-force scan of the whole window.
     *
     * @param \stdClass[] $roomslots Slots already in the room (from get_slots_in_room())
     * @param int $windowstart Unix timestamp
     * @param int $windowend Unix timestamp
     * @param int $durationseconds Duration the candidate must fit
     * @param int $gapseconds The instance's configured SnapGap gap, in seconds
     * @return int[] Sorted, de-duplicated candidate start times (unix timestamps)
     */
    protected static function candidate_start_times_for_room(
        array $roomslots,
        int $windowstart,
        int $windowend,
        int $durationseconds,
        int $gapseconds
    ): array {
        $times = [$windowstart];
        foreach ($roomslots as $slot) {
            $times[] = (int) $slot->endtime + $gapseconds;
        }

        $times = array_values(array_unique($times));
        sort($times);

        return array_values(array_filter($times, static function (int $time) use ($windowstart, $windowend, $durationseconds) {
            return $time >= $windowstart && ($time + $durationseconds) <= $windowend;
        }));
    }

    /**
     * Attempts a single candidate placement via add_slot(), swallowing a
     * validation rejection (SnapGap/overlap/invalid room) rather than letting
     * it propagate, so the caller's search can move on to the next candidate.
     * A successful call here IS the real, final placement -- see
     * run_autoscheduler()'s docblock for why nothing is "simulated" first.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $roomid The room to attempt
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @param int $submissionid The confsubmissions_submission id being placed
     * @return int|null The new confscheduler_slot id, or null if this candidate was rejected
     */
    protected static function attempt_place(
        int $confschedulerid,
        int $roomid,
        int $starttime,
        int $endtime,
        int $submissionid
    ): ?int {
        try {
            return self::add_slot($confschedulerid, [$roomid], $starttime, $endtime, $submissionid);
        } catch (\moodle_exception $e) {
            return null;
        }
    }

    /**
     * Whether a candidate [starttime, endtime) placement in $roomid would
     * overlap another currently-scheduled submission that shares $trackid and
     * is scheduled in a *different* room. Used only as a soft preference (see
     * try_place_single()) -- never a hard constraint.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $trackid The trackid to check against
     * @param int $roomid The candidate's room (excluded from the search: only *other* rooms count)
     * @param int $starttime Candidate start, unix timestamp
     * @param int $endtime Candidate end, unix timestamp
     * @param array $trackidcache Submission-id => trackid memo, shared across calls within one
     *        run_autoscheduler() call to avoid repeat mod_confsubmissions lookups
     * @return bool
     */
    protected static function track_overlaps_elsewhere(
        int $confschedulerid,
        int $trackid,
        int $roomid,
        int $starttime,
        int $endtime,
        array &$trackidcache
    ): bool {
        global $DB;

        $sql = "SELECT s.id, s.starttime, s.endtime, s.submissionid
                  FROM {confscheduler_slot} s
                  JOIN {confscheduler_slotroom} sr ON sr.slotid = s.id
                 WHERE s.confscheduler = :confschedulerid
                   AND s.submissionid IS NOT NULL
                   AND sr.roomid <> :roomid";
        $slots = $DB->get_records_sql($sql, ['confschedulerid' => $confschedulerid, 'roomid' => $roomid]);

        foreach ($slots as $slot) {
            $overlaps = $starttime < (int) $slot->endtime && $endtime > (int) $slot->starttime;
            if (!$overlaps) {
                continue;
            }

            $othersubmissionid = (int) $slot->submissionid;
            if (!array_key_exists($othersubmissionid, $trackidcache)) {
                $othersubmission = \mod_confsubmissions\api::get_submission($othersubmissionid);
                $trackidcache[$othersubmissionid] = ($othersubmission && !empty($othersubmission->trackid))
                    ? (int) $othersubmission->trackid
                    : null;
            }

            if ($trackidcache[$othersubmissionid] === $trackid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Searches for, and commits, a single placement for one submission.
     *
     * Search order: when no $preferredroomid is given, rooms are tried in a
     * FRESH shuffle of $roomids for this call (via $randomsource) rather than
     * always $roomids's own (fixed) order -- if every call used the same
     * fixed order, the greedy search would always fill the first room to
     * capacity before ever trying the second, which would make
     * $preferredroomid (see below) provably unable to change any outcome:
     * the "preferred" room would always already BE whichever room a
     * preference-less search tries first anyway. Shuffling per call makes
     * "preferred room" a real, load-bearing preference rather than a no-op,
     * and as a side effect spreads placements across rooms more realistically
     * run to run. When $preferredroomid IS given, it is tried first, ahead of
     * that call's shuffled order for the rest.
     *
     * Within a room, candidate start times come from
     * candidate_start_times_for_room(). If $preferreddays is non-empty (user
     * feedback, 2026-07-05), the candidate list is first stably partitioned into
     * "falls on a preferred day" (tried first) and "does not" (fallback) -- time of
     * day within each day is unaffected, since this only reorders which day's
     * candidates are tried first, not the candidates themselves. When $avoidtrackid
     * is given, EACH of those two day groups is then separately, stably partitioned
     * again into "does not overlap another $avoidtrackid submission in a different
     * room" (tried first within its group) and "does" (fallback within its group) --
     * see track_overlaps_elsewhere() -- so a day-preference match always outranks
     * track-overlap avoidance. The first candidate that add_slot() accepts wins.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $submissionid The confsubmissions_submission id to place
     * @param int[] $roomids All room ids in this instance
     * @param int $durationseconds Duration this submission needs
     * @param int $windowstart Unix timestamp
     * @param int $windowend Unix timestamp
     * @param int $gapseconds The instance's configured SnapGap gap, in seconds
     * @param int|null $preferredroomid A room id to try first, or null for no preference
     * @param \Closure $randomsource Source of randomness for this call's room-order shuffle (see make_random_source())
     * @param int|null $avoidtrackid A trackid whose different-room overlaps should be avoided if possible, or null
     * @param array $trackidcache Shared submission-id => trackid memo (see track_overlaps_elsewhere())
     * @param int[] $preferreddays Midnight timestamps of days to prefer, or empty for no preference
     *        (see mod_confsubmissions\api::get_date_preferences())
     * @return array{slotid: int, roomid: int}|null The placement made, or null if none was possible
     */
    protected static function try_place_single(
        int $confschedulerid,
        int $submissionid,
        array $roomids,
        int $durationseconds,
        int $windowstart,
        int $windowend,
        int $gapseconds,
        ?int $preferredroomid,
        \Closure $randomsource,
        ?int $avoidtrackid,
        array &$trackidcache,
        array $preferreddays = []
    ): ?array {
        $searchorder = self::fisher_yates_shuffle($roomids, $randomsource);
        if ($preferredroomid !== null && in_array($preferredroomid, $roomids, true)) {
            $searchorder = array_values(array_unique(array_merge([$preferredroomid], $searchorder)));
        }

        $candidates = [];
        foreach ($searchorder as $roomid) {
            $roomslots = self::get_slots_in_room($confschedulerid, $roomid);
            $starts = self::candidate_start_times_for_room(
                $roomslots,
                $windowstart,
                $windowend,
                $durationseconds,
                $gapseconds
            );
            foreach ($starts as $start) {
                $candidates[] = ['roomid' => $roomid, 'starttime' => $start];
            }
        }

        // Track-overlap avoidance, as its own reusable partition -- applied per day-
        // preference group below, not globally, so a day-preference match always
        // outranks track-overlap avoidance rather than the two getting reshuffled
        // together (a "good" but wrong-day candidate must not jump ahead of a "bad"
        // but right-day one).
        $avoidoverlap = static function (array $group) use (
            $confschedulerid,
            $avoidtrackid,
            $durationseconds,
            &$trackidcache
        ): array {
            if ($avoidtrackid === null) {
                return $group;
            }
            $good = [];
            $bad = [];
            foreach ($group as $candidate) {
                $endtime = $candidate['starttime'] + $durationseconds;
                $overlaps = self::track_overlaps_elsewhere(
                    $confschedulerid,
                    $avoidtrackid,
                    $candidate['roomid'],
                    $candidate['starttime'],
                    $endtime,
                    $trackidcache
                );
                if ($overlaps) {
                    $bad[] = $candidate;
                } else {
                    $good[] = $candidate;
                }
            }
            return array_merge($good, $bad);
        };

        // Preferred conference days (user feedback, 2026-07-05): reorder candidates so
        // one falling on a day the submitter preferred is tried before one that
        // doesn't, WITHOUT changing which candidates exist or their time-of-day order
        // within each day -- an empty $preferreddays ("no preference recorded") skips
        // this partition entirely, leaving every candidate in its original,
        // already-shuffled order (subject only to the track-overlap partition below).
        if ($preferreddays) {
            $preferred = [];
            $other = [];
            foreach ($candidates as $candidate) {
                $day = usergetmidnight($candidate['starttime']);
                if (in_array($day, $preferreddays, true)) {
                    $preferred[] = $candidate;
                } else {
                    $other[] = $candidate;
                }
            }
            $candidates = array_merge($avoidoverlap($preferred), $avoidoverlap($other));
        } else {
            $candidates = $avoidoverlap($candidates);
        }

        foreach ($candidates as $candidate) {
            $endtime = $candidate['starttime'] + $durationseconds;
            $slotid = self::attempt_place(
                $confschedulerid,
                $candidate['roomid'],
                $candidate['starttime'],
                $endtime,
                $submissionid
            );
            if ($slotid !== null) {
                return ['slotid' => $slotid, 'roomid' => $candidate['roomid']];
            }
        }

        return null;
    }

    /**
     * Builds a skippedreasons entry for the run_autoscheduler() summary.
     *
     * @param \stdClass $submission The submission that could not be placed
     * @param string $reasoncode 'nofit' or 'noroomsconfigured'
     * @return array{submissionid: int, title: string, reason: string}
     */
    protected static function autoscheduler_skip_reason(\stdClass $submission, string $reasoncode): array {
        return [
            'submissionid' => (int) $submission->id,
            'title'        => format_string($submission->title),
            'reason'       => get_string('autoscheduler' . $reasoncode, 'mod_confscheduler'),
        ];
    }

    /**
     * Returns a source of pseudo-random non-negative integers for
     * fisher_yates_shuffle(), either seeded (deterministic, for tests) or
     * unseeded (uses random_int(), for production).
     *
     * Deliberately does NOT use PHP's global mt_srand()/shuffle(): seeding
     * the global Mersenne Twister would be a process-wide side effect (every
     * later unrelated mt_rand()/shuffle() call in the same request would
     * become deterministic too, since PHP has no API to save/restore the
     * generator's internal state). This closure-based linear congruential
     * generator is self-contained instead: its state lives only in the
     * closure, so seeding it here can never affect anything else.
     *
     * @param int|null $seed Deterministic seed, or null for production randomness
     * @return \Closure(): int
     */
    protected static function make_random_source(?int $seed): \Closure {
        if ($seed === null) {
            return static function (): int {
                return random_int(0, PHP_INT_MAX - 1);
            };
        }

        $state = $seed;
        return static function () use (&$state): int {
            $state = ($state * 1103515245 + 12345) % 2147483648;
            return (int) abs($state);
        };
    }

    /**
     * Fisher-Yates shuffles an array using the given random source.
     *
     * @param array $items The items to shuffle
     * @param \Closure $randomsource A source of non-negative integers, e.g. from make_random_source()
     * @return array The shuffled items, reindexed from 0
     */
    protected static function fisher_yates_shuffle(array $items, \Closure $randomsource): array {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $randomsource() % ($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
