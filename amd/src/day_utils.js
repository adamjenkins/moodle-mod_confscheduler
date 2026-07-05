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
 * Pure, framework-agnostic day/date-boundary helpers shared by both the
 * edit-mode grid (amd/src/scheduler_grid.js) and the read-only Display mode
 * grid (amd/src/scheduler_display.js), so a multi-day schedule can be paged
 * one calendar day at a time instead of rendering as one long continuous
 * timeline (Phase 3.5; see README's "Architecture notes" for why this module
 * is shared while the drag-and-drop rendering code itself is not).
 *
 * Every function here is a pure function of its arguments (no DOM access, no
 * module-level state), so it carries no risk to the already-shipped,
 * security-reviewed drag-and-drop state machine in scheduler_grid.js: it only
 * ever reduces a slot list down to "which calendar day is this in", using the
 * browser's local timezone (the same timezone formatTime()/toLocaleTimeString
 * already renders block times in) as the day boundary.
 *
 * @module     mod_confscheduler/day_utils
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Returns the local-timezone calendar-day key (YYYY-MM-DD) a unix timestamp falls on.
 *
 * @param {Number} timestamp Unix timestamp (seconds)
 * @return {String}
 */
export const dayKeyForTimestamp = (timestamp) => {
    const date = new Date(timestamp * 1000);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

/**
 * Groups a list of slots (objects with a 'starttime' unix-timestamp property) by the
 * local calendar day their start time falls on.
 *
 * @param {Object[]} slots Slot objects, each with a numeric 'starttime'
 * @return {Object<String, Object[]>} Slots keyed by day (YYYY-MM-DD)
 */
export const groupSlotsByDay = (slots) => {
    const groups = {};
    slots.forEach((slot) => {
        const key = dayKeyForTimestamp(slot.starttime);
        if (!groups[key]) {
            groups[key] = [];
        }
        groups[key].push(slot);
    });
    return groups;
};

/**
 * Returns the day keys of a groupSlotsByDay() result, chronologically sorted
 * (a lexicographic string sort of YYYY-MM-DD keys is already chronological).
 *
 * @param {Object<String, Object[]>} groups A groupSlotsByDay() result
 * @return {String[]}
 */
export const sortedDayKeys = (groups) => Object.keys(groups).sort();

/**
 * Picks the default day key to show: today's day key if it is one of the
 * given keys, otherwise the earliest (chronologically first) key. Returns
 * null if given no keys at all (nothing scheduled yet).
 *
 * @param {String[]} dayKeys Sorted day keys, as returned by sortedDayKeys()
 * @return {String|null}
 */
export const defaultDayKey = (dayKeys) => {
    if (!dayKeys.length) {
        return null;
    }
    const todayKey = dayKeyForTimestamp(Math.floor(Date.now() / 1000));
    return dayKeys.includes(todayKey) ? todayKey : dayKeys[0];
};

/**
 * Returns the [start, end) unix-timestamp bounds (local midnight to the next
 * local midnight) of a day key.
 *
 * @param {String} dayKey A day key (YYYY-MM-DD)
 * @return {{start: Number, end: Number}}
 */
export const dayBounds = (dayKey) => {
    const [year, month, day] = dayKey.split('-').map(Number);
    const start = new Date(year, month - 1, day, 0, 0, 0, 0);
    const end = new Date(year, month - 1, day + 1, 0, 0, 0, 0);
    return {
        start: Math.floor(start.getTime() / 1000),
        end: Math.floor(end.getTime() / 1000),
    };
};

/**
 * Formats a day key as a short human-readable local date (e.g. "Mon, 1 Sep 2026")
 * for use as a day-selector option label.
 *
 * @param {String} dayKey A day key (YYYY-MM-DD)
 * @return {String}
 */
export const formatDayLabel = (dayKey) => {
    const [year, month, day] = dayKey.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.toLocaleDateString(undefined, {weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'});
};

/**
 * Returns the "greyed out" (out-of-conference-hours) vertical bands to render within a
 * single day-table's rendered timeline, given the instance's configured conference
 * start/end dates (user feedback, 2026-07-05) -- a top band (timelineStart -> where
 * the conference actually starts, only non-empty on the day conferencestart falls on)
 * and/or a bottom band (where the conference actually ends -> timelineEnd, only
 * non-empty on the day conferenceend falls on). A day entirely outside the conference
 * range (e.g. a legacy slot's day, before conference dates were set or after they were
 * narrowed) greys its ENTIRE rendered timeline. Returns [] (nothing greyed) when
 * either bound is unset, or the day is fully within the conference range.
 *
 * @param {String} dayKey The day this table is rendering (YYYY-MM-DD)
 * @param {Number} timelineStart This table's own rendered timeline start, unix timestamp
 * @param {Number} timelineEnd This table's own rendered timeline end, unix timestamp
 * @param {Number|null} conferencestart Unix timestamp, or null/0 if unset
 * @param {Number|null} conferenceend Unix timestamp, or null/0 if unset
 * @return {{start: Number, end: Number}[]} 0-2 bands, each within [timelineStart, timelineEnd]
 */
export const outOfHoursBands = (dayKey, timelineStart, timelineEnd, conferencestart, conferenceend) => {
    if (!conferencestart || !conferenceend) {
        return [];
    }

    const {start: dayStart, end: dayEnd} = dayBounds(dayKey);
    const validStart = Math.max(dayStart, conferencestart);
    const validEnd = Math.min(dayEnd, conferenceend);

    if (validStart >= validEnd) {
        // This day is entirely outside the conference range: grey the whole table.
        return [{start: timelineStart, end: timelineEnd}];
    }

    const bands = [];
    if (timelineStart < validStart) {
        bands.push({start: timelineStart, end: Math.min(validStart, timelineEnd)});
    }
    if (timelineEnd > validEnd) {
        bands.push({start: Math.max(validEnd, timelineStart), end: timelineEnd});
    }
    return bands;
};

/**
 * @type {String} Sentinel day-selector value meaning "show every day at once, each as
 * its own table" rather than a single YYYY-MM-DD key (user feedback, 2026-07-05).
 * Deliberately not a valid day-key shape so it can never collide with a real one.
 */
export const ALL_DAYS = 'all';

/**
 * Returns every calendar day key spanning a conference date range, inclusive of both
 * ends, regardless of whether any slot exists on a given day -- unlike
 * groupSlotsByDay()/sortedDayKeys(), which only ever produce keys for days that
 * already have at least one slot. This is what lets an organiser page to, or drop a
 * presentation onto, a day that's part of the conference but has nothing scheduled on
 * it yet.
 *
 * @param {Number|null} conferencestart Unix timestamp, or null/0 if unset
 * @param {Number|null} conferenceend Unix timestamp, or null/0 if unset
 * @return {String[]} Day keys (YYYY-MM-DD), chronologically ordered; [] if either
 *     bound is unset or the range is reversed
 */
export const dayKeysInRange = (conferencestart, conferenceend) => {
    if (!conferencestart || !conferenceend || conferenceend < conferencestart) {
        return [];
    }

    const startDate = new Date(conferencestart * 1000);
    const endDate = new Date(conferenceend * 1000);
    const cursor = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());
    const last = new Date(endDate.getFullYear(), endDate.getMonth(), endDate.getDate());

    const keys = [];
    // Safety cap (~10 years) against an absurd/malformed range looping unreasonably long.
    let guard = 0;
    while (cursor.getTime() <= last.getTime() && guard < 3660) {
        keys.push(dayKeyForTimestamp(Math.floor(cursor.getTime() / 1000)));
        cursor.setDate(cursor.getDate() + 1);
        guard++;
    }
    return keys;
};

/**
 * Returns every day key that should be selectable: the conference's own date range
 * (so an organiser can page to/schedule onto an empty day within it) UNIONED with any
 * day that already has a slot (so pre-existing data is never hidden, e.g. a slot
 * scheduled before conference dates were set, or before they were later narrowed).
 *
 * @param {Number|null} conferencestart Unix timestamp, or null/0 if unset
 * @param {Number|null} conferenceend Unix timestamp, or null/0 if unset
 * @param {Object[]} slots Slot objects, each with a numeric 'starttime'
 * @return {String[]} Day keys (YYYY-MM-DD), chronologically ordered, de-duplicated
 */
export const selectableDayKeys = (conferencestart, conferenceend, slots) => {
    const fromRange = dayKeysInRange(conferencestart, conferenceend);
    const fromSlots = sortedDayKeys(groupSlotsByDay(slots));
    return Array.from(new Set([...fromRange, ...fromSlots])).sort();
};
