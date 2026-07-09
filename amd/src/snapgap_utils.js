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
 * Pure, framework-agnostic SnapGap conflict-detection and auto-nudge helpers
 * (Revision round 1 batch B, 2026-07-03), used by amd/src/scheduler_grid.js's
 * drag-drop handlers (beginScheduleDrag()/beginMoveDrag()) to snap a
 * dropped/dragged block to the nearest valid position instead of submitting
 * an invalid position and showing a hard-rejection error.
 *
 * Per explicit user feedback ("SnapGap should automatically have sessions
 * 'bounce' off existing blocks without throwing error:gapviolation or
 * error:timeoverlap type errors ... automatically nudges (or snaps) the
 * blocks the appropriate distance away"), this module re-implements the
 * SAME overlap/gap math as the server, so the client can compute a valid
 * nudge target before ever asking the server.
 *
 * ****************************************************************************
 * IMPORTANT -- kept in sync BY HAND with \mod_confscheduler\api::validate_placement()
 * in classes/api.php. This is the ONE place in this project where the same
 * placement-validation logic genuinely needs to exist in two languages: there
 * is no way to call PHP synchronously mid-drag on the client. If
 * validate_placement()'s overlap/gap math ever changes, this file's
 * overlapsOrViolatesGap()/requiredGapSeconds() must change identically, and
 * vice versa. Both currently implement:
 *   overlap    = starttime < otherend && endtime > otherstart
 *   gap        = starttime >= otherend ? (starttime - otherend) : (otherstart - endtime)
 *   violation  = !overlap && gapseconds > 0 && gap < gapseconds
 *   exemption  = two column-spanning blocks (both submissionid null/undefined)
 *                are exempt from the gap check (never from the overlap check)
 * ****************************************************************************
 *
 * Authority: this client-side computation is a UX convenience ONLY.
 * \mod_confscheduler\api::validate_placement() remains the sole authoritative,
 * server-side check and is entirely unchanged by this module -- every
 * position this module computes is still submitted to, and independently
 * re-validated by, the existing AJAX write endpoints. A bug here can, at
 * worst, cause the server to reject a badly-nudged position (falling back to
 * the pre-existing error-notification+revert path), never cause an actually
 * invalid placement to be saved.
 *
 * Every function here is a pure function of its arguments (no DOM access, no
 * module-level state), matching amd/src/day_utils.js's/colour_utils.js's
 * shared-helper pattern. Also matching that pattern: no JS unit-test harness
 * (Jest/Karma/etc.) exists anywhere in this project as of this writing, so
 * this module -- like those two -- is verified live/in-browser rather than
 * via an automated JS unit test; see changelog.md for the live verification
 * performed for this feature.
 *
 * @module     mod_confscheduler/snapgap_utils
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Safety bound on how many times the forward/backward search loops may step
 * from one conflicting slot to the next before giving up. Each step resolves
 * at least one specific conflicting slot and strictly increases (forward) or
 * decreases (backward) the candidate position, so `others.length + 2` steps
 * are always mathematically sufficient; this constant is a defensive upper
 * bound against a pathological/malformed `others` list, not a value that
 * should ordinarily be reached.
 *
 * @type {Number}
 */
const MAX_NUDGE_STEPS = 500;

/**
 * How far (in seconds) a nudge may move the block from the position it was
 * actually dropped at before that direction is abandoned as "too far to be a
 * sensible auto-nudge". Bounds the search so a very sparsely-scheduled room
 * can't nudge a block many hours (or days) away and silently place it
 * somewhere the organiser cannot see without changing the day filter.
 * Beyond this distance in both directions, findNudgedPosition() returns
 * null, and scheduler_grid.js falls back to submitting the raw (invalid)
 * position and letting the existing server-rejection+revert path handle it.
 *
 * @type {Number}
 */
export const NUDGE_SEARCH_MAX_SECONDS = 4 * 60 * 60;

/**
 * The SnapGap-required gap, in seconds, between a candidate block and a
 * specific other slot -- 0 when both are column-spanning blocks (mirrors
 * validate_placement()'s span-block exemption), otherwise the instance's
 * configured gap.
 *
 * @param {Number|null} submissionid The candidate block's submissionid, or null/undefined for a span block
 * @param {Object} other Another slot: {starttime, endtime, submissionid}
 * @param {Number} gapseconds The instance's configured SnapGap gap, in seconds
 * @return {Number}
 */
export const requiredGapSeconds = (submissionid, other, gapseconds) => {
    const isspanblock = submissionid === null || submissionid === undefined;
    const otherisspanblock = other.submissionid === null || other.submissionid === undefined;
    if (isspanblock && otherisspanblock) {
        return 0;
    }
    return gapseconds;
};

/**
 * Whether a candidate [starttime, endtime) placement truly overlaps, or (when
 * not exempt) violates the configured SnapGap gap against, a single other
 * slot. Mirrors \mod_confscheduler\api::validate_placement()'s per-slot check
 * exactly -- see this module's docblock.
 *
 * @param {Number} starttime Candidate start, unix timestamp (seconds)
 * @param {Number} endtime Candidate end, unix timestamp (seconds)
 * @param {Number|null} submissionid The candidate block's submissionid, or null/undefined for a span block
 * @param {Object} other Another slot: {starttime, endtime, submissionid}
 * @param {Number} gapseconds The instance's configured SnapGap gap, in seconds
 * @return {Boolean}
 */
export const overlapsOrViolatesGap = (starttime, endtime, submissionid, other, gapseconds) => {
    const otherstart = other.starttime;
    const otherend = other.endtime;

    if (starttime < otherend && endtime > otherstart) {
        return true;
    }

    const required = requiredGapSeconds(submissionid, other, gapseconds);
    if (required <= 0) {
        return false;
    }

    const gap = starttime >= otherend ? (starttime - otherend) : (otherstart - endtime);
    return gap < required;
};

/**
 * Returns the "relevant" subset of `others` for a candidate placement: slots
 * sharing at least one of `roomids`, excluding `excludeslotid` (the block
 * being moved, so it never conflicts with its own previous position) and any
 * slot missing a usable id/time/room shape.
 *
 * @param {Object[]} others Every other currently-scheduled slot: {id, roomids, starttime, endtime, submissionid}
 * @param {Number[]} roomids The candidate's room id(s)
 * @param {Number|null} excludeslotid A slot id to exclude (when moving an existing block), or null
 * @return {Object[]}
 */
const relevantSlots = (others, roomids, excludeslotid) => others.filter((other) => {
    if (excludeslotid !== null && excludeslotid !== undefined && other.id === excludeslotid) {
        return false;
    }
    // A presentation nested inside a container (parentslotid set) occupies no
    // rooms of its own server-side (no confscheduler_slotroom rows; the grid
    // payload gives it its CONTAINER's roomids purely for rendering), and
    // validate_placement() never sees it -- treating it as a normal occupant
    // here made the client nudge/reject flush placements next to a populated
    // container that the server would accept, breaking the documented
    // kept-in-sync-BY-HAND contract with api.php (FABLE.md review, 2026-07-09).
    // The container itself still participates normally.
    if (other.parentslotid) {
        return false;
    }
    return Array.isArray(other.roomids) && other.roomids.some((id) => roomids.includes(id));
});

/**
 * Finds the first (order-independent -- there is normally at most one, since
 * a valid existing schedule cannot itself contain a room double-booking)
 * relevant slot that conflicts with a candidate placement.
 *
 * @param {Number} starttime Candidate start, unix timestamp (seconds)
 * @param {Number} endtime Candidate end, unix timestamp (seconds)
 * @param {Number|null} submissionid The candidate block's submissionid, or null/undefined for a span block
 * @param {Object[]} relevant Slots already filtered via relevantSlots()
 * @param {Number} gapseconds The instance's configured SnapGap gap, in seconds
 * @return {Object|null}
 */
const findFirstConflict = (starttime, endtime, submissionid, relevant, gapseconds) => relevant.find(
    (other) => overlapsOrViolatesGap(starttime, endtime, submissionid, other, gapseconds)
) || null;

/**
 * Searches forward (later) from a desired start time for the nearest start
 * time with no conflict: each time a conflict is found, the candidate start
 * is pushed to exactly that conflicting slot's end plus the required gap --
 * the same "just past the boundary" position validate_placement() treats as
 * the minimum valid gap -- and the search repeats from there.
 *
 * @param {Number} desiredstart Unix timestamp (seconds)
 * @param {Number} durationseconds The candidate block's duration
 * @param {Number|null} submissionid The candidate block's submissionid, or null/undefined for a span block
 * @param {Object[]} relevant Slots already filtered via relevantSlots()
 * @param {Number} gapseconds The instance's configured SnapGap gap, in seconds
 * @return {Number|null} The nudged start time, or null if no clear position was found within MAX_NUDGE_STEPS
 */
const searchForward = (desiredstart, durationseconds, submissionid, relevant, gapseconds) => {
    let start = desiredstart;

    for (let step = 0; step < MAX_NUDGE_STEPS; step++) {
        const end = start + durationseconds;
        const conflict = findFirstConflict(start, end, submissionid, relevant, gapseconds);
        if (!conflict) {
            return start;
        }

        const required = requiredGapSeconds(submissionid, conflict, gapseconds);
        const candidate = conflict.endtime + required;
        if (candidate <= start) {
            // No forward progress possible against this conflict; avoid an infinite loop.
            return null;
        }
        start = candidate;
    }

    return null;
};

/**
 * Searches backward (earlier) from a desired start time for the nearest
 * start time with no conflict: each time a conflict is found, the
 * candidate's END is pulled back to exactly that conflicting slot's start
 * minus the required gap, and the search repeats from there.
 *
 * @param {Number} desiredstart Unix timestamp (seconds)
 * @param {Number} durationseconds The candidate block's duration
 * @param {Number|null} submissionid The candidate block's submissionid, or null/undefined for a span block
 * @param {Object[]} relevant Slots already filtered via relevantSlots()
 * @param {Number} gapseconds The instance's configured SnapGap gap, in seconds
 * @return {Number|null} The nudged start time, or null if no clear position was found within MAX_NUDGE_STEPS
 */
const searchBackward = (desiredstart, durationseconds, submissionid, relevant, gapseconds) => {
    let start = desiredstart;

    for (let step = 0; step < MAX_NUDGE_STEPS; step++) {
        const end = start + durationseconds;
        const conflict = findFirstConflict(start, end, submissionid, relevant, gapseconds);
        if (!conflict) {
            return start;
        }

        const required = requiredGapSeconds(submissionid, conflict, gapseconds);
        const candidateend = conflict.starttime - required;
        const candidatestart = candidateend - durationseconds;
        if (candidatestart >= start) {
            // No backward progress possible against this conflict; avoid an infinite loop.
            return null;
        }
        start = candidatestart;
    }

    return null;
};

/**
 * Computes the nearest valid start time for a candidate block, nudging it
 * away from whatever it conflicts with at the desired position.
 *
 * Direction preference: BOTH directions are searched, and whichever result
 * is numerically closer to the desired start time is returned (a genuinely
 * "nearest valid position" nudge, not a fixed always-forward rule); when both
 * directions succeed at an EQUAL distance, forward (later) wins the tie --
 * matching the literal example in the original feedback ("nudges ... the
 * appropriate distance away (eg. 5 min later)"). A direction whose result
 * would move the block more than NUDGE_SEARCH_MAX_SECONDS away from the
 * desired position is treated as having failed, to avoid ever silently
 * nudging a block many hours away in a sparsely-scheduled room. If neither
 * direction succeeds within that bound, this returns null and the caller
 * (scheduler_grid.js) falls back to submitting the raw desired position,
 * letting the server's authoritative validate_placement() reject it and the
 * existing error-notification+revert path handle it -- the correct behaviour
 * when a room is genuinely too packed for any sensible nudge to exist nearby.
 *
 * @param {Number} desiredstart The raw (post-drop, pre-nudge) candidate start time, unix timestamp (seconds)
 * @param {Number} durationseconds The candidate block's duration
 * @param {Number[]} roomids The candidate's room id(s)
 * @param {Number|null} submissionid The candidate block's submissionid, or null/undefined for a span block
 * @param {Object[]} others Every other currently-scheduled slot: {id, roomids, starttime, endtime, submissionid}
 * @param {Number} gapseconds The instance's configured SnapGap gap, in seconds
 * @param {Number|null} excludeslotid A slot id to exclude (when moving an existing block), or null for a fresh placement
 * @return {Number|null} The nudged start time, or null if no valid nearby position exists
 */
export const findNudgedPosition = (
    desiredstart,
    durationseconds,
    roomids,
    submissionid,
    others,
    gapseconds,
    excludeslotid = null
) => {
    const relevant = relevantSlots(others, roomids, excludeslotid);

    const desiredend = desiredstart + durationseconds;
    if (!findFirstConflict(desiredstart, desiredend, submissionid, relevant, gapseconds)) {
        // Already valid: no nudge needed.
        return desiredstart;
    }

    const forward = searchForward(desiredstart, durationseconds, submissionid, relevant, gapseconds);
    const backward = searchBackward(desiredstart, durationseconds, submissionid, relevant, gapseconds);

    const forwardok = forward !== null && Math.abs(forward - desiredstart) <= NUDGE_SEARCH_MAX_SECONDS;
    const backwardok = backward !== null && Math.abs(backward - desiredstart) <= NUDGE_SEARCH_MAX_SECONDS;

    if (!forwardok && !backwardok) {
        return null;
    }
    if (forwardok && !backwardok) {
        return forward;
    }
    if (!forwardok && backwardok) {
        return backward;
    }

    const forwarddistance = Math.abs(forward - desiredstart);
    const backwarddistance = Math.abs(backward - desiredstart);
    return backwarddistance < forwarddistance ? backward : forward;
};
