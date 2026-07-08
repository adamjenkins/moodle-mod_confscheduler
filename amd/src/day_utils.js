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
 * Computes the vertical time range to render for one day, given the instance's
 * configured daily display-window bounds (user feedback, 2026-07-06) and that day's
 * own scheduled slots. This is the single shared implementation behind what were
 * previously two near-identical copies: amd/src/scheduler_grid.js's
 * computeTimelineBounds() and amd/src/scheduler_display.js's computeDayTimeRange().
 *
 * When daystartminutes/dayendminutes are BOTH set, the default axis for the day is
 * exactly [daystart, dayend] -- no padding or hour-rounding, since an organiser's
 * chosen times are already exact clock times. If a real scheduled slot falls outside
 * that window, the axis quietly widens just enough to include it in full (never hides
 * real data, matching this project's "never hide existing data" convention elsewhere,
 * e.g. the day selector always including a day with an existing slot even outside the
 * conference date range) -- see outOfHoursBands() below for how that widened sliver is
 * visually greyed to show it's outside the normal window.
 *
 * When EITHER is null (the default before this feature is configured, or the
 * "Automatic" checkbox), behaviour is completely unchanged from before this feature
 * existed: the axis is derived purely from the day's own slots (padded 30 minutes,
 * rounded to whole hours, 8-hour minimum), defaulting to 08:00-18:00 local when the day
 * has no slots at all.
 *
 * @param {Object[]} slots Slots to derive the range from (each with starttime/endtime)
 * @param {String} fallbackDayKey The day (YYYY-MM-DD) being rendered -- used both as the
 *     "no slots" fallback anchor and as the day the daystart/dayend minutes are applied to
 * @param {Number|null} daystartminutes Display-window start, minutes since midnight, or null/undefined for "automatic"
 * @param {Number|null} dayendminutes Display-window end, minutes since midnight, or null/undefined for "automatic"
 * @return {{start: Number, end: Number}}
 */
export const computeDayTimelineBounds = (slots, fallbackDayKey, daystartminutes, dayendminutes) => {
    // No valid day key to anchor an empty day's axis to (e.g. a fresh instance with
    // nothing scheduled yet and no day currently selected): fall back to today, matching
    // both this function's former call sites' own pre-existing behaviour.
    const anchorKey = fallbackDayKey || dayKeyForTimestamp(Math.floor(Date.now() / 1000));

    const times = [];
    slots.forEach((slot) => {
        times.push(slot.starttime);
        times.push(slot.endtime);
    });

    const bothConfigured = daystartminutes !== null && daystartminutes !== undefined
        && dayendminutes !== null && dayendminutes !== undefined;

    if (bothConfigured) {
        const dayStartOfDay = dayBounds(anchorKey).start;
        const configuredStart = dayStartOfDay + (daystartminutes * 60);
        const configuredEnd = dayStartOfDay + (dayendminutes * 60);

        return {
            start: times.length ? Math.min(configuredStart, ...times) : configuredStart,
            end: times.length ? Math.max(configuredEnd, ...times) : configuredEnd,
        };
    }

    let start;
    let end;
    if (times.length) {
        start = Math.min(...times);
        end = Math.max(...times);
    } else {
        start = dayBounds(anchorKey).start + (8 * 3600);
        end = start + (10 * 3600);
    }

    start = (Math.floor(start / 3600) * 3600) - 1800;
    end = (Math.ceil(end / 3600) * 3600) + 1800;
    if (end - start < 8 * 3600) {
        end = start + (8 * 3600);
    }

    return {start, end};
};

/**
 * Returns every half-hour tick between start and end (the first at or after start, the
 * last at or before end), each flagged whether it falls on a whole hour -- the shared
 * basis for the grid's horizontal gridlines (solid on the hour, dotted on the half-hour;
 * user report, 2026-07-08).
 *
 * Previously, these gridlines were a single CSS repeating-linear-gradient painted as each
 * room column's own background, anchored to the column's own top edge (i.e. this day's
 * range start, computeDayTimelineBounds()'s return value above). That start time is not,
 * in general, hour-aligned: the automatic case rounds down to the hour and then subtracts
 * a further 30 minutes of padding, and an organiser-configured daystart can be any
 * arbitrary clock time -- so the gradient's lines drifted away from the real hour marks
 * the time-axis labels are drawn at, by whatever offset the range start happened to have
 * from a true hour boundary. It also could not express a DIFFERENT line style for the
 * half-hour ticks the user also asked for here, since a linear-gradient can only paint
 * solid colour stops, never a dotted/dashed line.
 *
 * Ticks are computed from real clock timestamps here, exactly like the existing
 * hour-label loops in scheduler_grid.js/scheduler_display.js -- which is what guarantees
 * a gridline lands in the same place as its corresponding label, by construction, rather
 * than by the range start coincidentally already being hour-aligned.
 *
 * @param {Number} start Unix timestamp, the top of the visible range
 * @param {Number} end Unix timestamp, the bottom of the visible range
 * @return {{time: Number, ishour: Boolean}[]}
 */
export const gridlineTicks = (start, end) => {
    const ticks = [];
    for (let time = Math.ceil(start / 1800) * 1800; time <= end; time += 1800) {
        ticks.push({time, ishour: time % 3600 === 0});
    }
    return ticks;
};

/**
 * Resolves the effective daily display-window minutes for one day: that day's own
 * override if one exists, otherwise the instance-level default (user request,
 * 2026-07-07 -- the window is now settable per conference day, since these often differ
 * from one day to the next). Either returned value may be null, meaning "automatic" (an
 * unset default, or a default the day inherits) -- computeDayTimelineBounds() and
 * outOfHoursBands() already treat a null pair as "derive the axis from the day's slots".
 *
 * @param {String} dayKey The day (YYYY-MM-DD) whose effective window is wanted
 * @param {Number|null} defaultStart Instance default start, minutes since midnight, or null
 * @param {Number|null} defaultEnd Instance default end, minutes since midnight, or null
 * @param {Object.<String, {daystart: Number, dayend: Number}>} perDay Overrides keyed by day key
 * @return {{daystart: Number|null, dayend: Number|null}}
 */
export const boundsForDay = (dayKey, defaultStart, defaultEnd, perDay) => {
    const override = perDay && perDay[dayKey];
    if (override) {
        return {daystart: override.daystart, dayend: override.dayend};
    }
    return {daystart: defaultStart, dayend: defaultEnd};
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
 * Returns the "greyed out" vertical bands to render within a single day-table's
 * rendered timeline, combining two independent sources (user feedback, 2026-07-05 and
 * 2026-07-06):
 *
 * 1. Out-of-conference-hours: given the instance's configured conference start/end
 *    dates -- a top band (timelineStart -> where the conference actually starts, only
 *    non-empty on the day conferencestart falls on) and/or a bottom band (where the
 *    conference actually ends -> timelineEnd, only non-empty on the day conferenceend
 *    falls on). A day entirely outside the conference range (e.g. a legacy slot's day,
 *    before conference dates were set or after they were narrowed) greys its ENTIRE
 *    rendered timeline, and skips source 2 below entirely -- there is nothing left to
 *    usefully distinguish within an already-fully-greyed day.
 * 2. Out-of-daystart/dayend-window: given the instance's configured daily display
 *    window. Only produces a band when a real scheduled slot has pushed the rendered
 *    timeline wider than the configured window itself (see computeDayTimelineBounds()) --
 *    the common case (nothing outside the window) produces no band at all, since the
 *    axis already IS the window in that case.
 *
 * These two sources are orthogonal (one is about which CALENDAR DAYS are valid, the
 * other about TIME-OF-DAY within an already-valid day), so their bands are simply
 * concatenated. Returns [] (nothing greyed) when all four bounds are unset, or the day
 * is fully within both ranges.
 *
 * @param {String} dayKey The day this table is rendering (YYYY-MM-DD)
 * @param {Number} timelineStart This table's own rendered timeline start, unix timestamp
 * @param {Number} timelineEnd This table's own rendered timeline end, unix timestamp
 * @param {Number|null} conferencestart Unix timestamp, or null/0 if unset
 * @param {Number|null} conferenceend Unix timestamp, or null/0 if unset
 * @param {Number|null} daystartminutes Display-window start, minutes since midnight, or null/undefined if unset
 * @param {Number|null} dayendminutes Display-window end, minutes since midnight, or null/undefined if unset
 * @return {{start: Number, end: Number}[]} 0 or more bands, each within [timelineStart, timelineEnd]
 */
export const outOfHoursBands = (
    dayKey,
    timelineStart,
    timelineEnd,
    conferencestart,
    conferenceend,
    daystartminutes,
    dayendminutes
) => {
    const bands = [];

    if (conferencestart && conferenceend) {
        const {start: dayStart, end: dayEnd} = dayBounds(dayKey);
        const validStart = Math.max(dayStart, conferencestart);
        const validEnd = Math.min(dayEnd, conferenceend);

        if (validStart >= validEnd) {
            // This day is entirely outside the conference range: grey the whole table.
            return [{start: timelineStart, end: timelineEnd}];
        }

        if (timelineStart < validStart) {
            bands.push({start: timelineStart, end: Math.min(validStart, timelineEnd)});
        }
        if (timelineEnd > validEnd) {
            bands.push({start: Math.max(validEnd, timelineStart), end: timelineEnd});
        }
    }

    const bothConfigured = daystartminutes !== null && daystartminutes !== undefined
        && dayendminutes !== null && dayendminutes !== undefined;
    if (bothConfigured) {
        const dayStartOfDay = dayBounds(dayKey).start;
        const configuredStart = dayStartOfDay + (daystartminutes * 60);
        const configuredEnd = dayStartOfDay + (dayendminutes * 60);

        if (timelineStart < configuredStart) {
            bands.push({start: timelineStart, end: Math.min(configuredStart, timelineEnd)});
        }
        if (timelineEnd > configuredEnd) {
            bands.push({start: Math.max(configuredEnd, timelineStart), end: timelineEnd});
        }
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
