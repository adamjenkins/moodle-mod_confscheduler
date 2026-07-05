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

import $ from 'jquery';
import Dragdrop from 'core/dragdrop';
import SortableList from 'core/sortable_list';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {getString, getStrings} from 'core/str';
import * as Repository from 'mod_confscheduler/repository';
import * as DayUtils from 'mod_confscheduler/day_utils';
import * as ColourUtils from 'mod_confscheduler/colour_utils';
import * as SnapGapUtils from 'mod_confscheduler/snapgap_utils';
import * as ConferenceBoundsUtils from 'mod_confscheduler/conference_bounds_utils';

/**
 * Edit-mode drag-and-drop schedule grid (Phase 3.3).
 *
 * Renders entirely from data fetched via mod_confscheduler_get_grid_data
 * (never from PHP-rendered grid markup): rooms as columns, time on the
 * vertical axis, an "unscheduled" panel of accepted-but-not-yet-scheduled
 * submissions, and scheduled blocks positioned/sized proportional to their
 * duration. Every mutation (schedule/reschedule/unschedule, room CRUD,
 * reorder, favourite) goes through the corresponding AJAX write endpoint;
 * this module never writes to confscheduler's tables itself.
 *
 * Drag-and-drop uses core/dragdrop for free-form placement (scheduling an
 * unscheduled submission, moving or resizing a scheduled block) and
 * core/sortable_list for 1D column-header reordering, per this project's
 * README architecture decision. SnapGap is enforced authoritatively
 * server-side (see \mod_confscheduler\api::validate_placement()), which is
 * entirely UNCHANGED by the auto-nudge redesign below: it remains the sole
 * authoritative check regardless of what the client computes or submits.
 *
 * SnapGap auto-nudge (Revision round 1 batch B, 2026-07-03): a drop that would
 * violate SnapGap or truly overlap another block is no longer submitted as-is
 * to be hard-rejected with an error notification. beginScheduleDrag() and
 * beginMoveDrag() instead compute the nearest valid position via the shared,
 * pure amd/src/snapgap_utils.js (a client-side re-implementation of
 * validate_placement()'s overlap/gap math -- see that module's docblock for
 * why, and for the cross-reference comment kept in sync with the PHP side)
 * and submit THAT position instead. If no valid position exists nearby (a
 * genuinely packed room), the original raw position is submitted and the
 * pre-existing error-notification+revert behaviour still applies -- that
 * fallback path is a deliberately-kept safety net, not removed. Because the
 * real block element is never moved/mutated in the DOM until the server
 * confirms success, a rejected drag still "reverts" for free in that case:
 * the block simply stays where it always was, and Notification.exception()
 * surfaces the server's error. The unrelated client-side snap-to-5-minutes on
 * the RAW drop position (before any nudge is computed) remains a separate UX
 * convenience only, as before.
 *
 * Day/page pagination (Phase 3.5): the grid renders one calendar day's slots
 * at a time via a day <select> in the toolbar, using the shared, pure
 * amd/src/day_utils.js helpers (also used by the read-only Display-mode
 * module) to group state.allSlots by day and pick a sensible default. Only
 * the vertical timeline/body rendering is day-scoped (state.slots is the
 * current day's subset of state.allSlots); room headers and the unscheduled
 * panel are unaffected by day selection, since neither is itself tied to a
 * particular day. Drag-and-drop math is untouched by this: it still operates
 * on absolute unix timestamps derived from the currently-rendered day's own
 * time axis, exactly as before.
 *
 * Room/span-block colour contrast (Revision round 1, 2026-07-03): wherever a
 * room's or span block's chosen colour is used as a background, the shared
 * amd/src/colour_utils.js helper picks black or white text automatically so
 * there is never a black-on-dark or white-on-light legibility failure. See
 * amd/src/scheduler_display.js for the identical treatment in read-only mode.
 *
 * @module     mod_confscheduler/scheduler_grid
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @type {Number} Minimum column width in pixels; keep in sync with styles.css
 * .mod_confscheduler-room-header/-room-column. Columns stretch wider than this to
 * fill available space (user feedback, 2026-07-05), so this alone is NOT the
 * actual rendered column width -- see buildGridInto()'s docblock, and the
 * per-call getBoundingClientRect()-based measurements wherever a real pixel
 * position needs converting to/from a room index.
 */
const COLUMN_WIDTH = 200;

/**
 * @type {Number} Default row height (vertical pixels per hour) before the real,
 * organiser-configured value has loaded from the server, and the schema/JS fallback if a
 * confscheduler instance somehow has none -- keep in sync with classes/api.php's
 * DEFAULT_PX_PER_HOUR and install.xml's schema default.
 */
const DEFAULT_PX_PER_HOUR = 144;

/** @type {Number} Client-side mirror of classes/api.php's MIN_PX_PER_HOUR, for input validation. */
const MIN_PX_PER_HOUR = 60;

/** @type {Number} Client-side mirror of classes/api.php's MAX_PX_PER_HOUR, for input validation. */
const MAX_PX_PER_HOUR = 480;

/** @type {Number} Client-side snap granularity, in minutes, applied to drop positions for UX only. */
const SNAP_MINUTES = 5;

/** @type {Number} Default duration, in minutes, given to a newly-scheduled block dragged from the unscheduled panel. */
const DEFAULT_DURATION_MINUTES = 30;

/**
 * Clamps a value between a minimum and maximum (inclusive).
 *
 * @param {Number} value
 * @param {Number} min
 * @param {Number} max
 * @return {Number}
 */
const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

/**
 * Rounds a unix timestamp to the nearest multiple of the given number of minutes.
 *
 * @param {Number} timestamp Unix timestamp (seconds)
 * @param {Number} minutes Snap granularity in minutes
 * @return {Number} The snapped unix timestamp (seconds)
 */
const snapTime = (timestamp, minutes) => Math.round(timestamp / (minutes * 60)) * (minutes * 60);

/**
 * Formats a unix timestamp as a short local time (e.g. "14:05").
 *
 * @param {Number} timestamp Unix timestamp (seconds)
 * @return {String}
 */
const formatTime = (timestamp) => new Date(timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});

/**
 * Formats a unix timestamp as a datetime-local input value (YYYY-MM-DDTHH:mm) in the
 * browser's local timezone -- the inverse of the `new Date(value).getTime() / 1000`
 * parsing this module already does when reading a datetime-local input back out. Used
 * to pre-fill the span-block modal's start/end fields when editing an existing block.
 *
 * @param {Number} timestamp Unix timestamp (seconds)
 * @return {String}
 */
const toDatetimeLocalValue = (timestamp) => {
    const date = new Date(timestamp * 1000);
    const pad = (value) => String(value).padStart(2, '0');
    const datepart = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const timepart = `${pad(date.getHours())}:${pad(date.getMinutes())}`;
    return `${datepart}T${timepart}`;
};

/**
 * Builds a track pill element (an <a> linking to the linked mod_confprogram instance's
 * accepted-submissions list, filtered to this track, when a programUrl/trackid are
 * available; otherwise a plain, non-interactive <span>) for a slot/unscheduled-panel
 * entry carrying a 'track'/'trackid' pair.
 *
 * @param {String|null} programUrl The linked mod_confprogram activity's base view URL (already has ?id=...), or null
 * @param {Number|null} trackid The confsubmissions_track id, or null
 * @param {String} trackname The track's display name
 * @param {String|null} filterbytrackstr The raw (unsubstituted) 'filterbytrack' lang string, or null
 * @return {HTMLElement}
 */
const buildTrackPill = (programUrl, trackid, trackname, filterbytrackstr) => {
    if (programUrl && trackid) {
        const pill = document.createElement('a');
        pill.className = 'mod_confscheduler-track-pill';
        pill.href = `${programUrl}&trackid=${trackid}`;
        pill.textContent = trackname;
        // Descriptive accessible name beyond the bare track name (WCAG "link purpose"):
        // the visible text alone doesn't convey that activating it navigates to a
        // filtered list on a different activity.
        if (filterbytrackstr) {
            pill.setAttribute('aria-label', filterbytrackstr.replace('{$a}', trackname));
        }
        return pill;
    }

    const pill = document.createElement('span');
    pill.className = 'mod_confscheduler-track-pill';
    pill.textContent = trackname;
    return pill;
};

/**
 * Converts a unix timestamp to a Y pixel offset within the grid body, relative to state.timelineStart.
 * Uses the instance's own configured row height (state.pxperhour), not a fixed constant --
 * see the "hour height" quick control at the top of the grid in edit mode.
 *
 * @param {Object} state The module state object
 * @param {Number} timestamp Unix timestamp (seconds)
 * @return {Number} Pixel offset
 */
const timeToY = (state, timestamp) => (timestamp - state.timelineStart) / 60 * (state.pxperhour / 60);

/**
 * Converts a Y pixel offset within the grid body back to a unix timestamp.
 *
 * @param {Object} state The module state object
 * @param {Number} y Pixel offset
 * @return {Number} Unix timestamp (seconds)
 */
const yToTime = (state, y) => state.timelineStart + Math.round((y / (state.pxperhour / 60))) * 60;

/**
 * Live drag-preview highlight (Revision round 1 feedback: "the new start/end times
 * should be highlighted, and clearly visible while dragging so that when dropped, the
 * block can be exactly where the user wanted it"). A single overlay element, created on
 * demand and reused for the lifetime of one drag, repositioned/resized on every pointer
 * move to show EXACTLY the room(s) and (possibly SnapGap-nudged) time range a drop right
 * now would commit to -- both beginMoveDrag() and beginScheduleDrag() compute this
 * preview using the identical roomids/start/end values they then actually submit on
 * drop, so the preview can never show a different outcome than what actually happens.
 *
 * @param {Object} state The module state object
 * @param {Object} target {roomids, start, end, valid} as computed by the caller
 */
const showDragPreview = (state, target) => {
    if (!state.dragPreviewEl) {
        const el = document.createElement('div');
        el.className = 'mod_confscheduler-drag-preview';
        el.setAttribute('aria-hidden', 'true');
        const label = document.createElement('div');
        label.className = 'mod_confscheduler-drag-preview-label';
        el.appendChild(label);
        state.columnsWrap.appendChild(el);
        state.dragPreviewEl = el;
    }

    const el = state.dragPreviewEl;
    const indices = target.roomids
        .map((id) => state.rooms.findIndex((room) => room.id === id))
        .filter((index) => index >= 0);
    if (!indices.length) {
        el.style.display = 'none';
        return;
    }

    const minIndex = Math.min(...indices);
    const span = Math.max(...indices) - minIndex + 1;

    el.style.display = 'block';
    el.classList.toggle('mod_confscheduler-drag-preview-invalid', !target.valid);
    // Percentages, not pixels -- see renderBlock()'s identical treatment.
    const roomcount = Math.max(state.rooms.length, 1);
    el.style.left = ((minIndex / roomcount) * 100) + '%';
    el.style.width = `calc(${(span / roomcount) * 100}% - 6px)`;
    el.style.top = timeToY(state, target.start) + 'px';
    el.style.height = Math.max(20, timeToY(state, target.end) - timeToY(state, target.start)) + 'px';
    el.querySelector('.mod_confscheduler-drag-preview-label').textContent =
        `${formatTime(target.start)}–${formatTime(target.end)}`;
};

/**
 * Removes the live drag-preview highlight, if one is currently shown. Called once a
 * drag ends (whether committed or cancelled) so it never lingers on the grid.
 *
 * @param {Object} state The module state object
 */
const clearDragPreview = (state) => {
    if (state.dragPreviewEl) {
        state.dragPreviewEl.remove();
        state.dragPreviewEl = null;
    }
};

/**
 * Computes a visible timeline range from a set of slots, padded by 30 minutes at each
 * end and rounded to whole hours, with an 8-hour minimum span. Pure/stateless so it
 * can be called once per day when rendering the "All days" view (user feedback,
 * 2026-07-05), not just for the single currently-selected day.
 *
 * @param {Object[]} slots Slots to derive the range from (each with starttime/endtime)
 * @param {String} fallbackDayKey The day (YYYY-MM-DD) to default to (08:00-18:00 local)
 *     when `slots` is empty -- the day being rendered, so an empty day's axis reflects
 *     ITS OWN date rather than always defaulting to "today" regardless of which day
 *     is shown.
 * @return {{start: Number, end: Number}}
 */
const computeTimelineBounds = (slots, fallbackDayKey) => {
    const times = [];
    slots.forEach((slot) => {
        times.push(slot.starttime);
        times.push(slot.endtime);
    });

    let start;
    let end;
    if (times.length) {
        start = Math.min(...times);
        end = Math.max(...times);
    } else {
        start = DayUtils.dayBounds(fallbackDayKey).start + (8 * 3600);
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
 * Computes the visible timeline range (state.timelineStart/timelineEnd) for the
 * single currently-selected day, from state.slots.
 *
 * @param {Object} state The module state object
 */
const computeTimeline = (state) => {
    const fallbackKey = (state.selectedDay && state.selectedDay !== DayUtils.ALL_DAYS)
        ? state.selectedDay
        : DayUtils.dayKeyForTimestamp(Math.floor(Date.now() / 1000));
    const bounds = computeTimelineBounds(state.slots, fallbackKey);
    state.timelineStart = bounds.start;
    state.timelineEnd = bounds.end;
};

/**
 * Re-fetches the grid payload and re-renders everything (headers, body, unscheduled panel).
 * Used at startup and after any room CRUD/reorder action.
 *
 * @param {Object} state The module state object
 * @return {Promise}
 */
const fetchAndRenderAll = (state) => Repository.getGridData(state.cmid).then((data) => {
    state.rooms = data.rooms;
    state.allSlots = data.slots;
    state.unscheduled = data.unscheduled;
    state.gapminutes = data.gapminutes;
    state.pxperhour = data.pxperhour;
    state.conferencestart = data.conferencestart;
    state.conferenceend = data.conferenceend;
    syncGapMinutesInput(state);
    syncPxPerHourInput(state);
    applyDayFilter(state);
    // Skip in "All days" mode: renderAllDaysBody() (via renderGridBody() below) hides
    // the single-day header/grid entirely and builds its own per-day headers, so
    // rebuilding/re-showing the single-day sortable_list header here would just be
    // immediately undone -- see renderHeaders()'s and renderAllDaysBody()'s own docblocks.
    if (state.selectedDay !== DayUtils.ALL_DAYS) {
        renderHeaders(state);
    }
    renderDaySelector(state);
    renderGridBody(state);
    renderUnscheduledPanel(state);
    return null;
}).catch(Notification.exception);

/**
 * Re-fetches the grid payload and re-renders only the body/unscheduled panel (not the room headers).
 * Used after slot mutations (schedule/reschedule/unschedule), where the room set itself is unchanged,
 * so the core/sortable_list instance bound to the header row does not need rebuilding.
 *
 * @param {Object} state The module state object
 * @return {Promise}
 */
const fetchAndRenderBody = (state) => Repository.getGridData(state.cmid).then((data) => {
    state.rooms = data.rooms;
    state.allSlots = data.slots;
    state.unscheduled = data.unscheduled;
    state.gapminutes = data.gapminutes;
    state.pxperhour = data.pxperhour;
    state.conferencestart = data.conferencestart;
    state.conferenceend = data.conferenceend;
    syncGapMinutesInput(state);
    syncPxPerHourInput(state);
    applyDayFilter(state);
    renderDaySelector(state);
    renderGridBody(state);
    renderUnscheduledPanel(state);
    return null;
}).catch(Notification.exception);

/**
 * Reflects state.gapminutes into the quick SnapGap control's displayed value, without
 * disturbing it if the organiser is actively typing in it (i.e. it currently has focus)
 * -- called after every grid data refetch, since a fetch triggered by an unrelated
 * action (e.g. scheduling a submission) must not clobber an in-progress edit.
 *
 * @param {Object} state The module state object
 */
const syncGapMinutesInput = (state) => {
    const input = state.root.querySelector('.mod_confscheduler-gapminutes');
    if (input && document.activeElement !== input) {
        input.value = state.gapminutes;
    }
};

/**
 * Reflects state.pxperhour into the quick row-height control's displayed value, without
 * disturbing it if the organiser is actively typing in it -- mirrors syncGapMinutesInput().
 *
 * @param {Object} state The module state object
 */
const syncPxPerHourInput = (state) => {
    const input = state.root.querySelector('.mod_confscheduler-pxperhour');
    if (input && document.activeElement !== input) {
        input.value = state.pxperhour;
    }
};

/**
 * Groups state.allSlots by day, picks/keeps a selected day (or "All days", see
 * DayUtils.ALL_DAYS), and sets state.slots to the single selected day's subset (used
 * by the single-day render path only -- the "All days" path reads state.slotsByDay
 * directly, one day at a time, in renderAllDaysBody()).
 *
 * Selectable days now come from the instance's own configured conference date range
 * (user feedback, 2026-07-05), not just days that already have a slot -- see
 * DayUtils.selectableDayKeys() -- so an organiser can page to, or drop a presentation
 * onto, a day within the conference that has nothing scheduled on it yet.
 *
 * @param {Object} state The module state object
 */
const applyDayFilter = (state) => {
    state.slotsByDay = DayUtils.groupSlotsByDay(state.allSlots);
    state.dayKeys = DayUtils.selectableDayKeys(state.conferencestart, state.conferenceend, state.allSlots);
    if (!state.selectedDay || (state.selectedDay !== DayUtils.ALL_DAYS && !state.dayKeys.includes(state.selectedDay))) {
        state.selectedDay = DayUtils.defaultDayKey(state.dayKeys);
    }
    state.slots = (state.selectedDay && state.selectedDay !== DayUtils.ALL_DAYS)
        ? (state.slotsByDay[state.selectedDay] || [])
        : [];
};

/**
 * Renders the day-selector <select> options (including "All days") and wires it to
 * re-filter/re-render the body on change. A no-op (element left hidden) when there is
 * no conference date range configured and nothing scheduled yet (an existing instance
 * saved before conference dates were made required, with nothing on the grid).
 *
 * @param {Object} state The module state object
 */
const renderDaySelector = (state) => {
    const select = state.root.querySelector('.mod_confscheduler-day-select');
    if (!select) {
        return;
    }
    select.innerHTML = '';

    if (!state.dayKeys.length) {
        select.hidden = true;
        return;
    }
    select.hidden = false;

    const allDaysOption = document.createElement('option');
    allDaysOption.value = DayUtils.ALL_DAYS;
    allDaysOption.textContent = state.strings.alldays;
    allDaysOption.selected = state.selectedDay === DayUtils.ALL_DAYS;
    select.appendChild(allDaysOption);

    state.dayKeys.forEach((key) => {
        const option = document.createElement('option');
        option.value = key;
        option.textContent = DayUtils.formatDayLabel(key);
        option.selected = key === state.selectedDay;
        select.appendChild(option);
    });
};

/**
 * Builds one room-header row (the boxes only, not the wrapping container's own
 * class/insertion) -- shared by renderHeaders() (the single persistent, sticky,
 * sortable_list-reorderable row) and renderAllDaysBody() (one non-sticky,
 * non-reorderable row per day; user feedback, 2026-07-05 -- reordering rooms while
 * viewing every day at once is a rare edge case an organiser can do from a
 * single-day view instead).
 *
 * @param {Object} state The module state object
 * @param {Boolean} draggable Whether to include the sortable_list drag handle
 * @return {HTMLElement} The header row element (not yet inserted anywhere)
 */
const buildRoomHeaderRow = (state, draggable) => {
    const headerRow = document.createElement('div');
    headerRow.className = 'mod_confscheduler-room-headers';

    const spacer = document.createElement('div');
    spacer.className = 'mod_confscheduler-time-header-spacer';
    headerRow.appendChild(spacer);

    state.rooms.forEach((room) => {
        const header = document.createElement('div');
        header.className = 'mod_confscheduler-room-header';
        header.dataset.roomid = room.id;
        if (room.colour) {
            header.style.backgroundColor = room.colour;
            const textColour = ColourUtils.contrastTextColour(room.colour);
            if (textColour) {
                header.style.color = textColour;
            }
        }

        if (draggable) {
            const handle = document.createElement('span');
            handle.className = 'mod_confscheduler-room-draghandle';
            handle.setAttribute('data-drag-type', 'move');
            handle.setAttribute('tabindex', '0');
            handle.setAttribute('role', 'button');
            handle.setAttribute('aria-label', state.strings.movecolumn);
            handle.textContent = '⋮⋮';
            header.appendChild(handle);
        }

        const name = document.createElement('span');
        name.className = 'mod_confscheduler-room-name';
        name.textContent = room.name;
        header.appendChild(name);

        const actions = document.createElement('span');
        actions.className = 'mod_confscheduler-room-header-actions';

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'mod_confscheduler-room-header-edit';
        editBtn.setAttribute('aria-label', state.strings.editroom);
        editBtn.innerHTML = '&#9998;';
        actions.appendChild(editBtn);

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'mod_confscheduler-room-header-delete';
        deleteBtn.setAttribute('aria-label', state.strings.deleteroom);
        deleteBtn.innerHTML = '&#128465;';
        actions.appendChild(deleteBtn);

        header.appendChild(actions);
        headerRow.appendChild(header);
    });

    return headerRow;
};

/**
 * Renders the single, persistent, sticky room column header row (drag-to-reorder via
 * core/sortable_list, edit/delete buttons) for single-day mode.
 *
 * @param {Object} state The module state object
 */
const renderHeaders = (state) => {
    const scrollEl = state.root.querySelector('.mod_confscheduler-grid-scroll');
    const existing = state.root.querySelector('.mod_confscheduler-room-headers');
    if (existing) {
        existing.remove();
    }

    const headerRow = buildRoomHeaderRow(state, true);

    const gridEl = state.root.querySelector('.mod_confscheduler-grid');
    scrollEl.insertBefore(headerRow, gridEl);
    gridEl.style.display = '';
    headerRow.style.display = '';

    // core/sortable_list has no public teardown API; re-instantiating on a freshly-built
    // header row each time (rather than trying to reuse one instance across room set
    // changes) avoids stale references to removed DOM nodes.
    state.sortableList = new SortableList('.mod_confscheduler-room-headers', {isHorizontal: true});
    $(headerRow).on(SortableList.EVENTS.DROP, () => {
        const roomidsinorder = Array.from(headerRow.querySelectorAll('.mod_confscheduler-room-header'))
            .map((el) => Number(el.dataset.roomid));
        Repository.reorderRooms(state.cmid, roomidsinorder)
            .then(() => fetchAndRenderAll(state))
            .catch(Notification.exception);
    });
};

/**
 * Appends the greyed-out out-of-conference-hours band(s) for one rendered day-table
 * (user feedback, 2026-07-05) -- a no-op (appends nothing) when either conference
 * date is unset, or the day is fully within the conference range. See
 * DayUtils.outOfHoursBands()'s docblock for the exact rule.
 *
 * @param {Object} state The module state object (reads state.timelineStart/timelineEnd, must
 *     already be set for the day being rendered)
 * @param {HTMLElement} columnsWrap The .mod_confscheduler-columns container to append into
 * @param {String} dayKey The day being rendered (YYYY-MM-DD)
 */
const renderOutOfHoursBands = (state, columnsWrap, dayKey) => {
    const bands = DayUtils.outOfHoursBands(
        dayKey,
        state.timelineStart,
        state.timelineEnd,
        state.conferencestart,
        state.conferenceend
    );
    bands.forEach((band) => {
        const bandEl = document.createElement('div');
        bandEl.className = 'mod_confscheduler-outofhours-band';
        bandEl.style.top = timeToY(state, band.start) + 'px';
        bandEl.style.height = Math.max(0, timeToY(state, band.end) - timeToY(state, band.start)) + 'px';
        columnsWrap.appendChild(bandEl);
    });
};

/**
 * Builds one day's complete grid (time axis + room columns + out-of-hours bands +
 * scheduled blocks) into the given container element, using state.timelineStart/
 * timelineEnd -- the caller is responsible for setting those to the day being built
 * BEFORE calling this (computeTimeline()/computeTimelineBounds()), since renderBlock()/
 * timeToY() read them from state rather than taking them as parameters. Shared by
 * renderBody() (single-day mode, into the one persistent .mod_confscheduler-grid) and
 * renderAllDaysBody() (one fresh grid per day; user feedback, 2026-07-05).
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} gridEl The .mod_confscheduler-grid container to build into (cleared first)
 * @param {Object[]} slots This day's slots to render as blocks
 * @param {String} dayKey The day being rendered (YYYY-MM-DD), for the out-of-hours band calculation
 * @return {HTMLElement} The constructed .mod_confscheduler-columns element
 */
const buildGridInto = (state, gridEl, slots, dayKey) => {
    gridEl.innerHTML = '';

    const totalHeight = timeToY(state, state.timelineEnd);

    const timeAxis = document.createElement('div');
    timeAxis.className = 'mod_confscheduler-time-axis';
    timeAxis.style.height = totalHeight + 'px';
    for (let hour = Math.ceil(state.timelineStart / 3600) * 3600; hour <= state.timelineEnd; hour += 3600) {
        const label = document.createElement('div');
        label.className = 'mod_confscheduler-time-label';
        label.style.top = timeToY(state, hour) + 'px';
        label.textContent = formatTime(hour);
        timeAxis.appendChild(label);
    }
    gridEl.appendChild(timeAxis);

    const columnsWrap = document.createElement('div');
    columnsWrap.className = 'mod_confscheduler-columns';
    // min-width, not width (user feedback, 2026-07-05): lets this stretch to fill
    // any extra row width via its own flex-grow (see styles.css) when there's more
    // room than state.rooms.length * COLUMN_WIDTH would need, while still
    // guaranteeing every room column at least COLUMN_WIDTH px (past which
    // .mod_confscheduler-grid-scroll's overflow-x: auto takes over) -- blocks
    // inside are positioned in percentages (see renderBlock()), so they remain
    // correctly aligned to their room column regardless of its actual rendered
    // width.
    columnsWrap.style.minWidth = (Math.max(state.rooms.length, 1) * COLUMN_WIDTH) + 'px';
    columnsWrap.style.height = totalHeight + 'px';

    state.rooms.forEach((room) => {
        const column = document.createElement('div');
        column.className = 'mod_confscheduler-room-column';
        column.dataset.roomid = room.id;
        if (room.colour) {
            column.style.setProperty('--mod_confscheduler-room-colour', room.colour);
            column.classList.add('has-colour');
        }
        // The hourly gridline background (styles.css) is authored as a repeating gradient
        // sized for a 144px-per-hour tile; scaling it via background-size lets one
        // instance's own configured row height (state.pxperhour) stretch/compress the same
        // gradient rather than needing per-height CSS variants.
        column.style.backgroundSize = `100% ${state.pxperhour}px`;
        columnsWrap.appendChild(column);
    });

    gridEl.appendChild(columnsWrap);

    renderOutOfHoursBands(state, columnsWrap, dayKey);
    slots.forEach((slot) => renderBlock(state, columnsWrap, slot));

    return columnsWrap;
};

/**
 * Renders the grid body for single-day mode: the time axis and, per room, an
 * absolutely-positioned column containing every scheduled block (single-room and
 * column-spanning alike), into the one persistent .mod_confscheduler-grid element.
 *
 * @param {Object} state The module state object
 */
const renderBody = (state) => {
    computeTimeline(state);

    const gridEl = state.root.querySelector('.mod_confscheduler-grid');
    state.columnsWrap = buildGridInto(state, gridEl, state.slots, state.selectedDay);
};

/**
 * Dispatches to renderBody() (single-day mode) or renderAllDaysBody() ("All days"
 * mode) depending on state.selectedDay -- the single call site every other function
 * in this module should use instead of calling renderBody() directly (user feedback,
 * 2026-07-05).
 *
 * @param {Object} state The module state object
 */
const renderGridBody = (state) => {
    if (state.selectedDay === DayUtils.ALL_DAYS) {
        renderAllDaysBody(state);
    } else {
        renderBody(state);
    }
};

/**
 * Renders the "All days" view (user feedback, 2026-07-05): every selectable day as
 * its own complete table (heading + room headers + grid), stacked vertically inside
 * a single new container, instead of the single persistent .mod_confscheduler-grid
 * element (which this hides rather than reuses, so switching back to a single day
 * later is a clean, simple show/hide rather than having to distinguish which
 * .mod_confscheduler-grid is "the" one).
 *
 * Room-header rows here are NOT drag-reorderable (see buildRoomHeaderRow()'s
 * docblock) and blocks are NOT draggable while this view is showing -- see
 * bindEvents()'s startDrag(), which no-ops on mousedown/touchstart whenever
 * state.selectedDay === DayUtils.ALL_DAYS. Every click-based interaction (favourite
 * star, unschedule ×, edit-span-block pencil, track pill link) is unaffected, since
 * those are handled by the existing delegated click listener regardless of which
 * table a block happens to be in.
 *
 * @param {Object} state The module state object
 */
const renderAllDaysBody = (state) => {
    const scrollEl = state.root.querySelector('.mod_confscheduler-grid-scroll');

    const singleDayHeaders = state.root.querySelector('.mod_confscheduler-room-headers');
    const singleDayGrid = state.root.querySelector('.mod_confscheduler-grid');
    if (singleDayHeaders) {
        singleDayHeaders.style.display = 'none';
    }
    if (singleDayGrid) {
        singleDayGrid.style.display = 'none';
    }

    const existingContainer = state.root.querySelector('.mod_confscheduler-alldays-container');
    if (existingContainer) {
        existingContainer.remove();
    }

    const container = document.createElement('div');
    container.className = 'mod_confscheduler-alldays-container';

    state.dayTimelines = {};

    state.dayKeys.forEach((dayKey) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'mod_confscheduler-day-table-wrapper';

        const heading = document.createElement('h4');
        heading.className = 'mod_confscheduler-day-heading';
        heading.textContent = DayUtils.formatDayLabel(dayKey);
        wrapper.appendChild(heading);

        const headerRow = buildRoomHeaderRow(state, false);
        wrapper.appendChild(headerRow);

        const daySlots = state.slotsByDay[dayKey] || [];
        const bounds = computeTimelineBounds(daySlots, dayKey);
        state.timelineStart = bounds.start;
        state.timelineEnd = bounds.end;

        // Deliberately NOT also class "mod_confscheduler-grid" -- state.root.querySelector()
        // calls elsewhere assume that class matches exactly one element (the single-day
        // mode's persistent grid); "mod_confscheduler-day-grid" gets its own near-identical
        // CSS rule instead (see styles.css) to avoid that ambiguity entirely.
        const gridEl = document.createElement('div');
        gridEl.className = 'mod_confscheduler-day-grid';
        gridEl.setAttribute('role', 'table');
        wrapper.appendChild(gridEl);

        const columnsWrap = buildGridInto(state, gridEl, daySlots, dayKey);
        state.dayTimelines[dayKey] = {start: bounds.start, end: bounds.end, columnsWrap};

        container.appendChild(wrapper);
    });

    scrollEl.appendChild(container);
};

/**
 * Renders a single scheduled block (presentation or column-spanning label block) and appends it to
 * the grid's columns container, absolutely positioned by room index (left/width) and time (top/height).
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} columnsWrap The .mod_confscheduler-columns container
 * @param {Object} slot A slot entry as returned by mod_confscheduler_get_grid_data
 */
const renderBlock = (state, columnsWrap, slot) => {
    const indices = slot.roomids
        .map((id) => state.rooms.findIndex((room) => room.id === id))
        .filter((index) => index >= 0);
    if (!indices.length) {
        return;
    }
    const minIndex = Math.min(...indices);
    const maxIndex = Math.max(...indices);
    const span = maxIndex - minIndex + 1;
    const isSpanBlock = slot.submissionid === null;

    const block = document.createElement('div');
    block.className = 'mod_confscheduler-block' + (isSpanBlock ? ' mod_confscheduler-block-span' : '');
    block.dataset.slotid = slot.id;
    block.dataset.roomids = JSON.stringify(slot.roomids);
    block.dataset.starttime = slot.starttime;
    block.dataset.endtime = slot.endtime;
    // Needed by the SnapGap auto-nudge computation (beginMoveDrag()) to know whether this
    // block is a column-spanning block (submissionid null), which is exempt from the gap
    // check against another span block -- see snapgap_utils.js's requiredGapSeconds().
    // dataset values are always strings; '' (not the literal string "null") represents
    // null here, mirroring how scheduler_display.js already stores this same field.
    block.dataset.submissionid = slot.submissionid !== null ? slot.submissionid : '';
    // Percentages of columnsWrap's own width (position: relative, so this is the
    // containing block for these position: absolute children), not pixels -- stays
    // correctly aligned to its room column(s) regardless of how wide columnsWrap
    // actually renders (fixed at COLUMN_WIDTH px per room, or stretched wider to
    // fill available space; see buildGridInto()'s docblock).
    const roomcount = Math.max(state.rooms.length, 1);
    block.style.left = ((minIndex / roomcount) * 100) + '%';
    block.style.width = `calc(${(span / roomcount) * 100}% - 6px)`;
    block.style.top = timeToY(state, slot.starttime) + 'px';
    block.style.height = Math.max(20, timeToY(state, slot.endtime) - timeToY(state, slot.starttime)) + 'px';

    // Used below to tint the resize-handle grip bar to match the block's own
    // auto-contrast text colour, so the grip stays visible against a coloured span
    // block's background too (plain presentation blocks have no background colour,
    // so this stays null for them and the grip keeps its default dark-on-light look).
    let textColour = null;

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'mod_confscheduler-block-remove';
    removeBtn.setAttribute('aria-label', state.strings.unschedule);
    removeBtn.textContent = '×';
    block.appendChild(removeBtn);

    if (isSpanBlock) {
        if (slot.colour) {
            block.style.backgroundColor = slot.colour;
            textColour = ColourUtils.contrastTextColour(slot.colour);
            if (textColour) {
                block.style.color = textColour;
            }
        }

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'mod_confscheduler-block-edit-span';
        editBtn.setAttribute('aria-label', state.strings.editspanblock);
        editBtn.innerHTML = '&#9998;';
        block.appendChild(editBtn);

        const label = document.createElement('div');
        label.className = 'mod_confscheduler-block-label';
        label.textContent = slot.label || '';
        block.appendChild(label);
    } else {
        const favBtn = document.createElement('button');
        favBtn.type = 'button';
        favBtn.className = 'mod_confscheduler-block-fav';
        favBtn.dataset.favourited = slot.favourited ? '1' : '0';
        favBtn.setAttribute('aria-pressed', slot.favourited ? 'true' : 'false');
        favBtn.setAttribute('aria-label', state.strings.favourite);
        favBtn.innerHTML = slot.favourited ? '&#9733;' : '&#9734;';
        block.appendChild(favBtn);

        const title = document.createElement('div');
        title.className = 'mod_confscheduler-block-title';
        title.textContent = slot.title || '';
        block.appendChild(title);

        const speakers = document.createElement('div');
        speakers.className = 'mod_confscheduler-block-speakers';
        speakers.textContent = slot.speakers || '';
        block.appendChild(speakers);

        const footer = document.createElement('div');
        footer.className = 'mod_confscheduler-block-footer';
        if (slot.track) {
            footer.appendChild(buildTrackPill(state.programUrl, slot.trackid, slot.track, state.strings.filterbytrack));
        }
        footer.appendChild(document.createElement('span')).className = 'mod_confscheduler-block-roomtime-spacer';
        block.appendChild(footer);
    }

    const roomNames = slot.roomids
        .map((id) => (state.rooms.find((room) => room.id === id) || {}).name)
        .filter(Boolean)
        .join(', ');
    const roomTime = document.createElement('div');
    roomTime.className = 'mod_confscheduler-block-roomtime';
    roomTime.textContent = `${roomNames}, ${formatTime(slot.starttime)}–${formatTime(slot.endtime)}`;
    block.appendChild(roomTime);

    const resizeHandle = document.createElement('div');
    resizeHandle.className = 'mod_confscheduler-block-resize';
    resizeHandle.setAttribute('aria-hidden', 'true');
    if (textColour) {
        // Tint the grip bar to match the block's own auto-contrast text colour, so it
        // stays visible against a coloured span block's background (see styles.css's
        // default dark-on-light grip, sized for a plain, uncoloured presentation block).
        const isWhite = textColour === '#ffffff';
        resizeHandle.style.setProperty(
            '--mod_confscheduler-resize-grip-colour',
            isWhite ? 'rgba(255, 255, 255, 0.55)' : 'rgba(0, 0, 0, 0.28)'
        );
        resizeHandle.style.setProperty(
            '--mod_confscheduler-resize-grip-colour-hover',
            isWhite ? 'rgba(255, 255, 255, 0.85)' : 'rgba(0, 0, 0, 0.55)'
        );
    }
    block.appendChild(resizeHandle);

    columnsWrap.appendChild(block);
};

/**
 * Whether an unscheduled submission should show while a given single day is
 * selected (user feedback, 2026-07-05: "in edit mode, the unscheduled presentation
 * block should not show presentations if a non-preferred day is selected"). An
 * empty preferreddates list means no preference was ever recorded (see
 * mod_confsubmissions\api::get_date_preferences()'s docblock) and must show on
 * every day, not be hidden everywhere.
 *
 * Known limitation, same class as the rest of this module's day-key handling (see
 * amd/src/day_utils.js's own docblock): item.preferreddates timestamps are "local
 * midnight" as computed server-side by Moodle's configured timezone, but
 * DayUtils.dayKeyForTimestamp() below reads the browser's LOCAL timezone. The two
 * only necessarily agree when a user's browser timezone matches Moodle's
 * configured one -- a mismatch could shift which calendar day a preference is
 * seen to fall on by this filter, near a day boundary.
 *
 * @param {Object} item One entry from state.unscheduled
 * @param {String} dayKey The currently-selected day (YYYY-MM-DD), or ALL_DAYS/null
 * @return {Boolean}
 */
const matchesSelectedDay = (item, dayKey) => {
    if (!dayKey || dayKey === DayUtils.ALL_DAYS || !item.preferreddates || !item.preferreddates.length) {
        return true;
    }
    return item.preferreddates.some((timestamp) => DayUtils.dayKeyForTimestamp(timestamp) === dayKey);
};

/**
 * Renders the "unscheduled" panel: accepted submissions not yet placed in the grid.
 * Dimmed (not hidden -- still useful to see what's outstanding) while "All days" is
 * selected, since dragging a card out of it is disabled in that view (see
 * bindEvents()'s startDrag()). While a single day is selected, a submission with a
 * recorded date preference that does not include that day is left out of the list
 * entirely (see matchesSelectedDay()).
 *
 * @param {Object} state The module state object
 */
const renderUnscheduledPanel = (state) => {
    const panel = state.root.querySelector('.mod_confscheduler-unscheduled-panel');
    if (panel) {
        panel.classList.toggle('mod_confscheduler-unscheduled-panel-inert', state.selectedDay === DayUtils.ALL_DAYS);
    }

    const list = state.root.querySelector('.mod_confscheduler-unscheduled-list');
    if (!list) {
        return;
    }
    list.innerHTML = '';

    state.unscheduled
        .filter((item) => matchesSelectedDay(item, state.selectedDay))
        .forEach((item) => {
            const card = document.createElement('div');
            card.className = 'mod_confscheduler-unscheduled-card';
            card.setAttribute('role', 'listitem');
            card.dataset.submissionid = item.submissionid;
            card.dataset.durationminutes = item.durationminutes;

            const title = document.createElement('div');
            title.className = 'mod_confscheduler-unscheduled-title';
            title.textContent = item.title;
            card.appendChild(title);

            const speakers = document.createElement('div');
            speakers.className = 'mod_confscheduler-unscheduled-speakers';
            speakers.textContent = item.speakers;
            card.appendChild(speakers);

            if (item.track) {
                card.appendChild(
                    buildTrackPill(state.programUrl, item.trackid, item.track, state.strings.filterbytrack)
                );
            }

            list.appendChild(card);
        });
};

/**
 * Begins a free-form drag of an already-scheduled block, to move it to a new time/room.
 * On drop, the new position is computed from where the drag proxy was released, snapped to
 * SNAP_MINUTES, and sent to the server; the real block element is left untouched until then,
 * so a server rejection needs no explicit "revert" beyond removing the proxy.
 *
 * SnapGap auto-nudge (Revision round 1 batch B, 2026-07-03): if the raw dropped position
 * would violate SnapGap or truly overlap another block in the target room(s), the nudged
 * (nearest valid) position from amd/src/snapgap_utils.js is submitted instead of the raw
 * one -- see that module's docblock. If no valid position exists nearby, the raw (invalid)
 * position is submitted as before, so the existing server-rejection+revert path still
 * applies to the genuine "room is packed" edge case.
 *
 * @param {Object} state The module state object
 * @param {Event} event The originating mousedown/touchstart event
 * @param {HTMLElement} blockEl The block being dragged
 */
const beginMoveDrag = (state, event, blockEl) => {
    const prepared = Dragdrop.prepare(event);
    if (!prepared.start) {
        return;
    }

    const rect = blockEl.getBoundingClientRect();
    const proxy = $(blockEl.cloneNode(true));
    proxy.find('.mod_confscheduler-block-resize').remove();
    proxy.addClass('mod_confscheduler-drag-proxy');
    proxy.css({
        position: 'absolute',
        top: (rect.top + window.scrollY) + 'px',
        left: (rect.left + window.scrollX) + 'px',
        width: rect.width + 'px',
        height: rect.height + 'px',
        zIndex: 10000,
        opacity: 0.85,
        pointerEvents: 'none',
    });
    $('body').append(proxy);
    blockEl.classList.add('mod_confscheduler-block-dragging');

    const slotid = Number(blockEl.dataset.slotid);
    const starttime = Number(blockEl.dataset.starttime);
    const endtime = Number(blockEl.dataset.endtime);
    const duration = endtime - starttime;
    const roomids = JSON.parse(blockEl.dataset.roomids);
    const span = roomids.length;
    const submissionid = blockEl.dataset.submissionid === '' ? null : Number(blockEl.dataset.submissionid);

    // Shared by the live preview (onMove) and the actual commit (onDrop), so the preview
    // shown while dragging can never disagree with what a drop right now would do -- see
    // showDragPreview()'s docblock.
    const computeTarget = (offsetLeft, offsetTop) => {
        if (!state.rooms.length) {
            return null;
        }

        const columnsRect = state.columnsWrap.getBoundingClientRect();
        const relX = offsetLeft - (columnsRect.left + window.scrollX);
        const relY = offsetTop - (columnsRect.top + window.scrollY);

        // The actual rendered column width, not the COLUMN_WIDTH constant: columns
        // stretch to fill available space above that minimum (user feedback,
        // 2026-07-05), so dividing the real, current rendered width by room count
        // is the only way this stays correct once they do.
        const columnwidth = columnsRect.width / state.rooms.length;
        const index = clamp(Math.round(relX / columnwidth), 0, Math.max(state.rooms.length - span, 0));
        const rawDesiredStart = snapTime(yToTime(state, relY), SNAP_MINUTES);
        // Bounce the raw drop position back inside the conference dates BEFORE the
        // SnapGap nudge runs, so SnapGap's own search starts from an already-in-range
        // position (a no-op when either conference date is unset -- see
        // conference_bounds_utils.js's docblock).
        const desiredStart = ConferenceBoundsUtils.clampToConferenceBounds(
            rawDesiredStart,
            duration,
            state.conferencestart,
            state.conferenceend
        );
        const targetRoomids = state.rooms.slice(index, index + span).map((room) => room.id);

        const gapseconds = (state.gapminutes || 0) * 60;
        const nudged = SnapGapUtils.findNudgedPosition(
            desiredStart,
            duration,
            targetRoomids,
            submissionid,
            state.allSlots,
            gapseconds,
            slotid
        );
        // Fall back to the raw (possibly invalid) desired position when no valid nearby
        // position exists (a genuinely packed room): the server's authoritative
        // validate_placement() will reject it and the pre-existing error+revert path
        // (Notification.exception(), block never actually moved in the DOM) still applies.
        const start = nudged !== null ? nudged : desiredStart;
        const valid = nudged !== null
            && ConferenceBoundsUtils.isWithinConferenceBounds(start, duration, state.conferencestart, state.conferenceend);
        return {roomids: targetRoomids, start, end: start + duration, valid};
    };

    Dragdrop.start(event, proxy, (pageX, pageY, proxyEl) => {
        const offset = proxyEl.offset();
        const target = computeTarget(offset.left, offset.top);
        if (target) {
            showDragPreview(state, target);
        } else {
            clearDragPreview(state);
        }
    }, (pageX, pageY, proxyEl) => {
        const offset = proxyEl.offset();
        proxyEl.remove();
        blockEl.classList.remove('mod_confscheduler-block-dragging');
        clearDragPreview(state);

        const target = computeTarget(offset.left, offset.top);
        if (!target) {
            return;
        }

        if (target.start === starttime && JSON.stringify(target.roomids) === JSON.stringify(roomids)) {
            return;
        }

        Repository.rescheduleSlot(state.cmid, slotid, target.roomids, target.start, target.end)
            .then(() => fetchAndRenderBody(state))
            .catch(Notification.exception);
    });
};

/**
 * Begins a vertical-only drag of a block's resize handle, to change only its end time.
 *
 * Deliberately NOT covered by the SnapGap auto-nudge redesign (Revision round 1 batch B,
 * 2026-07-03; see beginMoveDrag()/beginScheduleDrag()): the explicit task scope for that
 * change was "a fresh drag-from-unscheduled-panel placement and an existing-block
 * reschedule drag", not resizing. A resize that would violate SnapGap/overlap still hits
 * the pre-existing server-rejection+revert path unchanged.
 *
 * @param {Object} state The module state object
 * @param {Event} event The originating mousedown/touchstart event
 * @param {HTMLElement} blockEl The block whose resize handle is being dragged
 */
const beginResizeDrag = (state, event, blockEl) => {
    const prepared = Dragdrop.prepare(event);
    if (!prepared.start) {
        return;
    }

    const rect = blockEl.getBoundingClientRect();
    const proxy = $('<div class="mod_confscheduler-resize-proxy"></div>');
    proxy.css({
        position: 'absolute',
        top: (rect.bottom + window.scrollY) + 'px',
        left: (rect.left + window.scrollX) + 'px',
        width: rect.width + 'px',
        height: '4px',
        zIndex: 10000,
        pointerEvents: 'none',
    });
    $('body').append(proxy);
    blockEl.classList.add('mod_confscheduler-block-dragging');

    const slotid = Number(blockEl.dataset.slotid);
    const starttime = Number(blockEl.dataset.starttime);
    const currentEnd = Number(blockEl.dataset.endtime);
    const roomids = JSON.parse(blockEl.dataset.roomids);

    Dragdrop.start(event, proxy, () => {}, (pageX, pageY, proxyEl) => {
        const offset = proxyEl.offset();
        proxyEl.remove();
        blockEl.classList.remove('mod_confscheduler-block-dragging');

        const columnsRect = state.columnsWrap.getBoundingClientRect();
        const relY = offset.top - (columnsRect.top + window.scrollY);

        let newEnd = snapTime(yToTime(state, relY), SNAP_MINUTES);
        if (newEnd <= starttime) {
            newEnd = starttime + (SNAP_MINUTES * 60);
        }
        if (newEnd === currentEnd) {
            return;
        }

        Repository.rescheduleSlot(state.cmid, slotid, roomids, starttime, newEnd)
            .then(() => fetchAndRenderBody(state))
            .catch(Notification.exception);
    });
};

/**
 * Begins a free-form drag of an unscheduled-panel card into the grid, to schedule it.
 * The new block is given the submission's own mod_confsubmissions submission type's
 * duration (Revision round 1, 2026-07-04; falls back to DEFAULT_DURATION_MINUTES if
 * unset -- see grid_data.php's identical fallback, which is what actually computes
 * this card's dataset.durationminutes); the block's height (and hence its end time)
 * can then be adjusted with the resize handle once scheduled, which does not change
 * the submission type's own configured duration.
 *
 * SnapGap auto-nudge (Revision round 1 batch B, 2026-07-03): see beginMoveDrag()'s
 * docblock -- the same nudge-or-fall-back-to-raw-position logic applies here.
 *
 * @param {Object} state The module state object
 * @param {Event} event The originating mousedown/touchstart event
 * @param {HTMLElement} cardEl The unscheduled-panel card being dragged
 */
const beginScheduleDrag = (state, event, cardEl) => {
    const prepared = Dragdrop.prepare(event);
    if (!prepared.start) {
        return;
    }

    const rect = cardEl.getBoundingClientRect();
    const proxy = $(cardEl.cloneNode(true));
    proxy.addClass('mod_confscheduler-drag-proxy');
    proxy.css({
        position: 'absolute',
        top: (rect.top + window.scrollY) + 'px',
        left: (rect.left + window.scrollX) + 'px',
        width: rect.width + 'px',
        zIndex: 10000,
        opacity: 0.85,
        pointerEvents: 'none',
    });
    $('body').append(proxy);

    const submissionid = Number(cardEl.dataset.submissionid);
    const durationminutes = Number(cardEl.dataset.durationminutes) || DEFAULT_DURATION_MINUTES;
    const duration = durationminutes * 60;

    // Shared by the live preview (onMove) and the actual commit (onDrop) -- see
    // beginMoveDrag()'s identical pattern and showDragPreview()'s docblock. Returns
    // null when the candidate position is outside the grid entirely (dropping there
    // is a no-op, matching the pre-existing "leave the card in the unscheduled panel"
    // behaviour -- the preview simply stays hidden in that case too).
    const computeTarget = (offsetLeft, offsetTop) => {
        if (!state.rooms.length) {
            return null;
        }

        const columnsRect = state.columnsWrap.getBoundingClientRect();
        const relX = offsetLeft - (columnsRect.left + window.scrollX);
        const relY = offsetTop - (columnsRect.top + window.scrollY);

        if (relX < 0 || relX >= columnsRect.width || relY < 0) {
            return null;
        }

        // The actual rendered column width, not the COLUMN_WIDTH constant -- see
        // beginMoveDrag()'s identical treatment.
        const columnwidth = columnsRect.width / state.rooms.length;
        const index = clamp(Math.floor(relX / columnwidth), 0, state.rooms.length - 1);
        const rawDesiredStart = snapTime(yToTime(state, relY), SNAP_MINUTES);
        // See beginMoveDrag()'s identical treatment: bounce the raw drop position back
        // inside the conference dates before the SnapGap nudge runs.
        const desiredStart = ConferenceBoundsUtils.clampToConferenceBounds(
            rawDesiredStart,
            duration,
            state.conferencestart,
            state.conferenceend
        );
        const roomids = [state.rooms[index].id];

        const gapseconds = (state.gapminutes || 0) * 60;
        const nudged = SnapGapUtils.findNudgedPosition(
            desiredStart,
            duration,
            roomids,
            submissionid,
            state.allSlots,
            gapseconds,
            null
        );
        // Fall back to the raw (possibly invalid) desired position when no valid nearby
        // position exists: the server's authoritative validate_placement() will reject
        // it and the pre-existing error-notification path still applies (the card simply
        // stays in the unscheduled panel, as it already does today on any rejection).
        const start = nudged !== null ? nudged : desiredStart;
        const valid = nudged !== null
            && ConferenceBoundsUtils.isWithinConferenceBounds(start, duration, state.conferencestart, state.conferenceend);
        return {roomids, start, end: start + duration, valid};
    };

    Dragdrop.start(event, proxy, (pageX, pageY, proxyEl) => {
        const offset = proxyEl.offset();
        const target = computeTarget(offset.left, offset.top);
        if (target) {
            showDragPreview(state, target);
        } else {
            clearDragPreview(state);
        }
    }, (pageX, pageY, proxyEl) => {
        const offset = proxyEl.offset();
        proxyEl.remove();
        clearDragPreview(state);

        const target = computeTarget(offset.left, offset.top);
        if (!target) {
            return;
        }

        Repository.scheduleSubmission(state.cmid, submissionid, target.roomids, target.start, target.end)
            .then(() => fetchAndRenderAll(state))
            .catch(Notification.exception);
    });
};

/**
 * Opens the add/edit room modal.
 *
 * @param {Object} state The module state object
 * @param {Number|null} roomid The room id to edit, or null to add a new room
 * @return {Promise}
 */
const openRoomModal = async(state, roomid) => {
    const room = roomid ? state.rooms.find((candidate) => candidate.id === roomid) : null;

    const body = await Templates.render('mod_confscheduler/room_form', {
        roomid: roomid || '',
        name: room ? room.name : '',
        colour: room ? room.colour : null,
    });

    const modal = await ModalSaveCancel.create({
        title: roomid ? state.strings.editroom : state.strings.addroom,
        body,
        show: true,
        removeOnClose: true,
    });

    modal.getRoot().on(ModalEvents.save, (event) => {
        event.preventDefault();

        const form = modal.getRoot()[0].querySelector('.mod_confscheduler-room-form');
        const name = form.querySelector('[name=name]').value.trim();
        const nocolour = form.querySelector('[name=nocolour]').checked;
        const colour = nocolour ? null : form.querySelector('[name=colour]').value;

        if (name === '') {
            return;
        }

        const promise = roomid
            ? Repository.updateRoom(state.cmid, roomid, name, colour)
            : Repository.addRoom(state.cmid, name, colour);

        promise.then(() => {
            modal.destroy();
            return fetchAndRenderAll(state);
        }).catch(Notification.exception);
    });
};

/**
 * Opens the "add span block"/"edit span block" modal (column-spanning
 * Lunch/Plenary-style block): the same modal is used for both actions, pre-filled with
 * the existing block's label/colour/times/room-range when $slot is given (Revision
 * round 1, 2026-07-03 -- span blocks previously supported only add/delete).
 *
 * @param {Object} state The module state object
 * @param {Object|null} slot The existing slot entry (from state.slots) to edit, or null to add a new block
 * @return {Promise}
 */
const openSpanBlockModal = async(state, slot = null) => {
    const isEdit = Boolean(slot);

    let startroomid = null;
    let endroomid = null;
    if (isEdit) {
        const indices = slot.roomids
            .map((id) => state.rooms.findIndex((room) => room.id === id))
            .filter((index) => index >= 0);
        if (indices.length) {
            startroomid = state.rooms[Math.min(...indices)].id;
            endroomid = state.rooms[Math.max(...indices)].id;
        }
    }

    const body = await Templates.render('mod_confscheduler/spanblock_form', {
        slotid: isEdit ? slot.id : '',
        label: isEdit ? (slot.label || '') : '',
        colour: isEdit ? slot.colour : null,
        starttime: isEdit ? toDatetimeLocalValue(slot.starttime) : '',
        endtime: isEdit ? toDatetimeLocalValue(slot.endtime) : '',
        rooms: state.rooms.map((room) => ({
            id: room.id,
            name: room.name,
            startselected: room.id === startroomid,
            endselected: room.id === endroomid,
        })),
    });

    const modal = await ModalSaveCancel.create({
        title: isEdit ? state.strings.editspanblock : state.strings.addspanblock,
        body,
        show: true,
        removeOnClose: true,
    });

    modal.getRoot().on(ModalEvents.save, (event) => {
        event.preventDefault();

        const form = modal.getRoot()[0].querySelector('.mod_confscheduler-spanblock-form');
        const slotid = Number(form.querySelector('[name=slotid]').value) || null;
        const label = form.querySelector('[name=label]').value.trim();
        const startroom = Number(form.querySelector('[name=startroom]').value);
        const endroom = Number(form.querySelector('[name=endroom]').value);
        const starttimeValue = form.querySelector('[name=starttime]').value;
        const endtimeValue = form.querySelector('[name=endtime]').value;
        const nocolour = form.querySelector('[name=nocolour]').checked;
        const colour = nocolour ? null : form.querySelector('[name=colour]').value;

        if (label === '' || !starttimeValue || !endtimeValue) {
            return;
        }

        const startIndex = state.rooms.findIndex((room) => room.id === startroom);
        const endIndex = state.rooms.findIndex((room) => room.id === endroom);
        if (startIndex === -1 || endIndex === -1) {
            return;
        }
        const lo = Math.min(startIndex, endIndex);
        const hi = Math.max(startIndex, endIndex);
        const roomids = state.rooms.slice(lo, hi + 1).map((room) => room.id);

        const starttime = Math.floor(new Date(starttimeValue).getTime() / 1000);
        const endtime = Math.floor(new Date(endtimeValue).getTime() / 1000);

        const promise = slotid
            ? Repository.updateSpanBlock(state.cmid, slotid, label, roomids, starttime, endtime, colour)
            : Repository.addSpanBlock(state.cmid, label, roomids, starttime, endtime, colour);

        promise.then(() => {
            modal.destroy();
            return fetchAndRenderBody(state);
        }).catch(Notification.exception);
    });
};

/**
 * Shows the result summary after an autoscheduler run: how many were
 * scheduled/skipped, and (when any were skipped) why each one was.
 *
 * @param {Object} state The module state object
 * @param {Object} result The mod_confscheduler_run_autoscheduler result
 * @return {Promise}
 */
const showAutoschedulerSummary = (state, result) => getString(
    'autoschedulersummary',
    'mod_confscheduler',
    {scheduled: result.scheduled, skipped: result.skipped}
).then((summary) => {
    let message = summary;
    if (result.skippedreasons && result.skippedreasons.length) {
        const lines = result.skippedreasons.map((entry) => `${entry.title}: ${entry.reason}`);
        message += ' ' + lines.join(' ');
    }
    Notification.alert(state.strings.autoschedulerrun, message);
    return null;
}).catch(Notification.exception);

/**
 * Opens the "run autoscheduler" modal: a time window and a "clear first"
 * checkbox. Each placed submission gets its own mod_confsubmissions submission
 * type's duration (Revision round 1, 2026-07-04) -- there is no longer a
 * uniform-duration input here, see Repository.runAutoscheduler()'s docblock.
 *
 * @param {Object} state The module state object
 * @return {Promise}
 */
const openAutoschedulerModal = async(state) => {
    const body = await Templates.render('mod_confscheduler/autoscheduler_form', {});

    const modal = await ModalSaveCancel.create({
        title: state.strings.autoschedulerrun,
        body,
        show: true,
        removeOnClose: true,
    });

    // Default the window to the instance's own configured conference dates (user
    // feedback, 2026-07-05), saving the organiser from re-typing the same range every
    // run; still freely editable before saving. A no-op (inputs stay blank, as
    // before) when either bound is unset -- see conference_bounds_utils.js's
    // docblock for why that's still possible for an instance saved before conference
    // dates were made a required field.
    const root = modal.getRoot()[0];
    if (state.conferencestart) {
        root.querySelector('[name=windowstart]').value = toDatetimeLocalValue(state.conferencestart);
    }
    if (state.conferenceend) {
        root.querySelector('[name=windowend]').value = toDatetimeLocalValue(state.conferenceend);
    }

    modal.getRoot().on(ModalEvents.save, (event) => {
        event.preventDefault();

        const form = modal.getRoot()[0].querySelector('.mod_confscheduler-autoscheduler-form');
        const windowstartValue = form.querySelector('[name=windowstart]').value;
        const windowendValue = form.querySelector('[name=windowend]').value;
        const clearfirst = form.querySelector('[name=clearfirst]').checked;

        if (!windowstartValue || !windowendValue) {
            return;
        }

        const windowstart = Math.floor(new Date(windowstartValue).getTime() / 1000);
        const windowend = Math.floor(new Date(windowendValue).getTime() / 1000);
        if (windowend <= windowstart) {
            return;
        }

        Repository.runAutoscheduler(state.cmid, windowstart, windowend, clearfirst).then((result) => {
            modal.destroy();
            showAutoschedulerSummary(state, result);
            return fetchAndRenderAll(state);
        }).catch(Notification.exception);
    });
};

/**
 * Confirms and deletes a room.
 *
 * @param {Object} state The module state object
 * @param {Number} roomid The confscheduler_room id to delete
 */
const onDeleteRoomClick = (state, roomid) => {
    Notification.confirm(
        state.strings.deleteroom,
        state.strings.confirmdeleteroom,
        state.strings.deleteroom,
        state.strings.cancel,
        () => {
            Repository.deleteRoom(state.cmid, roomid)
                .then(() => fetchAndRenderAll(state))
                .catch(Notification.exception);
        }
    );
};

/**
 * Toggles the favourite star on a scheduled presentation block, updating the star instantly
 * once the server confirms the new state (no page reload, no full grid re-render).
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} favBtn The favourite-toggle button that was clicked
 */
const onFavouriteClick = (state, favBtn) => {
    const block = favBtn.closest('.mod_confscheduler-block');
    const slotid = Number(block.dataset.slotid);
    const target = favBtn.dataset.favourited !== '1';

    favBtn.disabled = true;
    // Wrapped in Promise.resolve(): Ajax.call() returns a jQuery Deferred-backed
    // thenable, which implements then()/catch() but (unlike a native Promise) has
    // no finally() -- Promise.resolve() adopts it into a real native Promise so
    // finally() below works reliably.
    Promise.resolve(Repository.toggleFavourite(state.cmid, slotid, target)).then((result) => {
        favBtn.dataset.favourited = result.favourited ? '1' : '0';
        favBtn.setAttribute('aria-pressed', result.favourited ? 'true' : 'false');
        favBtn.innerHTML = result.favourited ? '&#9733;' : '&#9734;';
        return result;
    }).catch(Notification.exception).finally(() => {
        favBtn.disabled = false;
    });
};

/**
 * Persists a change to the quick SnapGap minimum-gap control at the top of the grid
 * (Revision round 1 follow-up, 2026-07-04 -- this replaces a field that previously
 * lived in the activity's own settings form). A negative or non-numeric value is reset
 * to the last known-good value rather than submitted, since the input has no
 * moodleform-style client validation of its own to fall back on.
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} input The .mod_confscheduler-gapminutes number input
 */
const onGapMinutesChange = (state, input) => {
    const value = parseInt(input.value, 10);
    if (isNaN(value) || value < 0) {
        input.value = state.gapminutes;
        return;
    }

    const previous = state.gapminutes;
    state.gapminutes = value;
    input.disabled = true;
    Promise.resolve(Repository.setGapMinutes(state.cmid, value)).catch((error) => {
        state.gapminutes = previous;
        input.value = previous;
        Notification.exception(error);
    }).finally(() => {
        input.disabled = false;
    });
};

/**
 * Persists a change to the quick row-height control at the top of the grid (user
 * feedback, 2026-07-05). An out-of-range or non-numeric value is reset to the last
 * known-good value rather than submitted -- mirrors onGapMinutesChange(). Unlike
 * GapSnap, a row-height change must also immediately re-render the body (every
 * block's top/height, and the hourly gridline spacing, are computed from it), so a
 * successful save re-renders using the *server-confirmed* value rather than assuming
 * the submitted value alone.
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} input The .mod_confscheduler-pxperhour number input
 */
const onPxPerHourChange = (state, input) => {
    const value = parseInt(input.value, 10);
    if (isNaN(value) || value < MIN_PX_PER_HOUR || value > MAX_PX_PER_HOUR) {
        input.value = state.pxperhour;
        return;
    }

    const previous = state.pxperhour;
    state.pxperhour = value;
    input.disabled = true;
    renderGridBody(state);
    Promise.resolve(Repository.setPxPerHour(state.cmid, value)).catch((error) => {
        state.pxperhour = previous;
        input.value = previous;
        renderGridBody(state);
        Notification.exception(error);
    }).finally(() => {
        input.disabled = false;
    });
};

/**
 * Unschedules a block.
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} block The block to unschedule
 */
const onUnscheduleClick = (state, block) => {
    const slotid = Number(block.dataset.slotid);
    Repository.unscheduleSlot(state.cmid, slotid)
        .then(() => fetchAndRenderAll(state))
        .catch(Notification.exception);
};

/**
 * Toggles fullscreen mode on the whole grid component via the Fullscreen API.
 *
 * @param {Object} state The module state object
 */
const toggleFullscreen = (state) => {
    if (!document.fullscreenElement) {
        state.root.requestFullscreen().catch(() => {
            // Fullscreen can be refused by the browser/user; nothing to recover, just stay windowed.
        });
    } else {
        document.exitFullscreen().catch(() => {});
    }
};

/**
 * Binds all delegated event listeners for the grid component. Called once at init.
 *
 * @param {Object} state The module state object
 */
const bindEvents = (state) => {
    state.root.addEventListener('click', (event) => {
        const removeBtn = event.target.closest('.mod_confscheduler-block-remove');
        if (removeBtn) {
            onUnscheduleClick(state, removeBtn.closest('.mod_confscheduler-block'));
            return;
        }

        const favBtn = event.target.closest('.mod_confscheduler-block-fav');
        if (favBtn) {
            onFavouriteClick(state, favBtn);
            return;
        }

        const editSpanBtn = event.target.closest('.mod_confscheduler-block-edit-span');
        if (editSpanBtn) {
            const slotid = Number(editSpanBtn.closest('.mod_confscheduler-block').dataset.slotid);
            const slot = state.slots.find((candidate) => candidate.id === slotid);
            if (slot) {
                openSpanBlockModal(state, slot);
            }
            return;
        }

        const addRoomBtn = event.target.closest('.mod_confscheduler-add-room');
        if (addRoomBtn) {
            openRoomModal(state, null);
            return;
        }

        const addSpanBtn = event.target.closest('.mod_confscheduler-add-spanblock');
        if (addSpanBtn) {
            openSpanBlockModal(state, null);
            return;
        }

        const autoschedulerBtn = event.target.closest('.mod_confscheduler-run-autoscheduler');
        if (autoschedulerBtn) {
            openAutoschedulerModal(state);
            return;
        }

        const fsBtn = event.target.closest('.mod_confscheduler-fullscreen-toggle');
        if (fsBtn) {
            toggleFullscreen(state);
            return;
        }

        const editRoomBtn = event.target.closest('.mod_confscheduler-room-header-edit');
        if (editRoomBtn) {
            const header = editRoomBtn.closest('.mod_confscheduler-room-header');
            openRoomModal(state, Number(header.dataset.roomid));
            return;
        }

        const deleteRoomBtn = event.target.closest('.mod_confscheduler-room-header-delete');
        if (deleteRoomBtn) {
            const header = deleteRoomBtn.closest('.mod_confscheduler-room-header');
            onDeleteRoomClick(state, Number(header.dataset.roomid));
        }
    });

    const startDrag = (event) => {
        // "All days" view (user feedback, 2026-07-05) is a viewing mode: no
        // scheduling/rescheduling/resizing drag across its multiple simultaneous
        // per-day tables. Every click-based interaction (favourite star, unschedule
        // ×, edit-span-block pencil, track pill link) is unaffected -- switch to a
        // single day to drag-schedule.
        if (state.selectedDay === DayUtils.ALL_DAYS) {
            return;
        }

        // Track pills are real <a> links (Revision round 1): let a click on one navigate
        // normally rather than being captured as a drag/schedule-drag start.
        if (event.target.closest('.mod_confscheduler-track-pill')) {
            return;
        }

        const resizeHandle = event.target.closest('.mod_confscheduler-block-resize');
        if (resizeHandle) {
            beginResizeDrag(state, event, resizeHandle.closest('.mod_confscheduler-block'));
            return;
        }

        const block = event.target.closest('.mod_confscheduler-block');
        if (block && !event.target.closest('button')) {
            beginMoveDrag(state, event, block);
            return;
        }

        const card = event.target.closest('.mod_confscheduler-unscheduled-card');
        if (card && !event.target.closest('input')) {
            beginScheduleDrag(state, event, card);
        }
    };
    state.root.addEventListener('mousedown', startDrag);
    state.root.addEventListener('touchstart', startDrag, {passive: false});

    state.root.addEventListener('change', (event) => {
        const daySelect = event.target.closest('.mod_confscheduler-day-select');
        if (daySelect) {
            state.selectedDay = daySelect.value;
            applyDayFilter(state);
            renderGridBody(state);
            // Dims/undims the unscheduled panel depending on the newly-selected day
            // (inert while "All days" is showing, see renderUnscheduledPanel()'s
            // docblock) -- its list content is unaffected by which day is selected,
            // only this class toggle needs to happen here.
            renderUnscheduledPanel(state);
            return;
        }

        const gapInput = event.target.closest('.mod_confscheduler-gapminutes');
        if (gapInput) {
            onGapMinutesChange(state, gapInput);
            return;
        }

        const pxPerHourInput = event.target.closest('.mod_confscheduler-pxperhour');
        if (pxPerHourInput) {
            onPxPerHourChange(state, pxPerHourInput);
        }
    });

    document.addEventListener('fullscreenchange', () => {
        const isFullscreen = document.fullscreenElement === state.root;
        state.root.classList.toggle('mod_confscheduler-fullscreen', isFullscreen);
        const fsBtn = state.root.querySelector('.mod_confscheduler-fullscreen-toggle');
        if (fsBtn) {
            fsBtn.setAttribute('aria-pressed', isFullscreen ? 'true' : 'false');
        }
    });
};

/**
 * Initialises the schedule grid for a confscheduler instance. Called from view.php.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} confschedulerid The confscheduler instance id
 * @param {String|null} programurl The linked mod_confprogram activity's base view URL (already has ?id=...),
 *        used to build track-pill click-through links (Revision round 1), or null if it could not be resolved
 * @return {Promise}
 */
export const init = async(cmid, confschedulerid, programurl = null) => {
    const root = document.getElementById('mod_confscheduler-grid-root');
    if (!root) {
        return;
    }

    const [
        unschedule, favourite, editroom, deleteroom, confirmdeleteroom,
        cancel, movecolumn, addroom, addspanblock, editspanblock, autoschedulerrun,
        filterbytrack, alldays,
    ] = await getStrings([
        {key: 'unschedule', component: 'mod_confscheduler'},
        {key: 'favourite', component: 'mod_confscheduler'},
        {key: 'editroom', component: 'mod_confscheduler'},
        {key: 'deleteroom', component: 'mod_confscheduler'},
        {key: 'confirmdeleteroom', component: 'mod_confscheduler'},
        {key: 'cancel', component: 'core'},
        {key: 'movecolumn', component: 'mod_confscheduler'},
        {key: 'addroom', component: 'mod_confscheduler'},
        {key: 'addspanblock', component: 'mod_confscheduler'},
        {key: 'editspanblock', component: 'mod_confscheduler'},
        {key: 'autoschedulerrun', component: 'mod_confscheduler'},
        {key: 'filterbytrack', component: 'mod_confscheduler'},
        {key: 'alldays', component: 'mod_confscheduler'},
    ]);

    const state = {
        cmid,
        confschedulerid,
        programUrl: programurl,
        root,
        rooms: [],
        allSlots: [],
        slots: [],
        slotsByDay: {},
        dayTimelines: {},
        dayKeys: [],
        selectedDay: null,
        unscheduled: [],
        gapminutes: 0,
        pxperhour: DEFAULT_PX_PER_HOUR,
        conferencestart: null,
        conferenceend: null,
        timelineStart: 0,
        timelineEnd: 0,
        columnsWrap: null,
        sortableList: null,
        dragPreviewEl: null,
        strings: {
            unschedule, favourite, editroom, deleteroom, confirmdeleteroom,
            cancel, movecolumn, addroom, addspanblock, editspanblock, autoschedulerrun,
            filterbytrack, alldays,
        },
    };

    bindEvents(state);
    await fetchAndRenderAll(state);
};
