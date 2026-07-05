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
 * Pure, framework-agnostic conference-date-bounds clamp (user feedback, 2026-07-05),
 * used by amd/src/scheduler_grid.js's drag handlers to "bounce" a dropped/dragged
 * block back inside the instance's configured conference start/end dates instead of
 * submitting an invalid position and showing a hard-rejection error -- the exact same
 * UX pattern already established for SnapGap in amd/src/snapgap_utils.js (see that
 * module's own docblock for the full rationale; this one is deliberately much
 * simpler, since a conference date range is a single fixed window, not a set of
 * other slots to search around).
 *
 * ****************************************************************************
 * IMPORTANT -- kept in sync BY HAND with \mod_confscheduler\api::validate_placement()'s
 * out-of-conference-hours check in classes/api.php. This is the one other place (after
 * SnapGap) where the same placement-validation logic genuinely needs to exist in two
 * languages: there is no way to call PHP synchronously mid-drag on the client. If
 * validate_placement()'s conference-bounds check ever changes, this module's
 * clampToConferenceBounds() must change identically, and vice versa. Both currently
 * implement: invalid if starttime < conferencestart OR endtime > conferenceend, but
 * ONLY when both bounds are set (an existing instance saved before conference dates
 * were made a required field may still have neither, and must keep scheduling exactly
 * as before rather than suddenly rejecting every placement).
 * ****************************************************************************
 *
 * Authority: this client-side computation is a UX convenience ONLY.
 * \mod_confscheduler\api::validate_placement() remains the sole authoritative,
 * server-side check and is entirely unchanged by this module -- every position this
 * module computes is still submitted to, and independently re-validated by, the
 * existing AJAX write endpoints.
 *
 * @module     mod_confscheduler/conference_bounds_utils
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Clamps a candidate [start, start+durationseconds) placement so it falls entirely
 * within [conferencestart, conferenceend), sliding it (never resizing it) back inside
 * the range when it would spill over either edge. A no-op (returns desiredstart
 * unchanged) when either bound is unset, or when the candidate already fits.
 *
 * @param {Number} desiredstart Candidate start, unix timestamp (seconds)
 * @param {Number} durationseconds The candidate block's duration
 * @param {Number|null} conferencestart Unix timestamp, or null/0/undefined if unset
 * @param {Number|null} conferenceend Unix timestamp, or null/0/undefined if unset
 * @return {Number} The clamped start time
 */
export const clampToConferenceBounds = (desiredstart, durationseconds, conferencestart, conferenceend) => {
    if (!conferencestart || !conferenceend) {
        return desiredstart;
    }

    let start = desiredstart;
    if (start < conferencestart) {
        start = conferencestart;
    }
    if (start + durationseconds > conferenceend) {
        // A block longer than the whole conference range can't be clamped to fit at
        // all -- this leaves start earlier than conferencestart again, which is still
        // an invalid position, but that's correct: there is no valid position to
        // offer, and the server's validate_placement() will reject it, same fallback
        // as snapgap_utils.js's "no valid nudge exists nearby" case.
        start = conferenceend - durationseconds;
    }

    return start;
};

/**
 * Whether a candidate [start, start+durationseconds) placement falls entirely within
 * [conferencestart, conferenceend). Always true when either bound is unset (the
 * feature is inert for an instance without both conference dates configured).
 *
 * @param {Number} start Candidate start, unix timestamp (seconds)
 * @param {Number} durationseconds The candidate block's duration
 * @param {Number|null} conferencestart Unix timestamp, or null/0/undefined if unset
 * @param {Number|null} conferenceend Unix timestamp, or null/0/undefined if unset
 * @return {Boolean}
 */
export const isWithinConferenceBounds = (start, durationseconds, conferencestart, conferenceend) => {
    if (!conferencestart || !conferenceend) {
        return true;
    }
    return start >= conferencestart && (start + durationseconds) <= conferenceend;
};
