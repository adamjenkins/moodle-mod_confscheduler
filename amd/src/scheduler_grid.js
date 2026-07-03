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
 * README architecture decision. GapSnap is enforced authoritatively
 * server-side (see \mod_confscheduler\api::validate_placement()); the
 * client-side snap-to-5-minutes on drop is a UX convenience only. Because the
 * real block element is never moved/mutated in the DOM until the server
 * confirms success, a rejected drag "reverts" for free: the block simply
 * stays where it always was, and Notification.exception() surfaces the
 * server's error.
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

/** @type {Number} Fixed column width in pixels; keep in sync with styles.css .mod_confscheduler-room-header/-room-column. */
const COLUMN_WIDTH = 200;

/** @type {Number} Vertical px/minute of scheduled time; keep in sync with styles.css's hour gridline spacing. */
const PX_PER_MINUTE = 2.4;

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
 *
 * @param {Object} state The module state object
 * @param {Number} timestamp Unix timestamp (seconds)
 * @return {Number} Pixel offset
 */
const timeToY = (state, timestamp) => (timestamp - state.timelineStart) / 60 * PX_PER_MINUTE;

/**
 * Converts a Y pixel offset within the grid body back to a unix timestamp.
 *
 * @param {Object} state The module state object
 * @param {Number} y Pixel offset
 * @return {Number} Unix timestamp (seconds)
 */
const yToTime = (state, y) => state.timelineStart + Math.round((y / PX_PER_MINUTE)) * 60;

/**
 * Computes the visible timeline range (state.timelineStart/timelineEnd) from the currently loaded slots,
 * padded by 30 minutes at each end and rounded to whole hours, with an 8-hour minimum span.
 *
 * @param {Object} state The module state object
 */
const computeTimeline = (state) => {
    const times = [];
    state.slots.forEach((slot) => {
        times.push(slot.starttime);
        times.push(slot.endtime);
    });

    let start;
    let end;
    if (times.length) {
        start = Math.min(...times);
        end = Math.max(...times);
    } else {
        const today = new Date();
        today.setHours(8, 0, 0, 0);
        start = Math.floor(today.getTime() / 1000);
        end = start + (10 * 3600);
    }

    start = (Math.floor(start / 3600) * 3600) - 1800;
    end = (Math.ceil(end / 3600) * 3600) + 1800;
    if (end - start < 8 * 3600) {
        end = start + (8 * 3600);
    }

    state.timelineStart = start;
    state.timelineEnd = end;
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
    applyDayFilter(state);
    renderHeaders(state);
    renderDaySelector(state);
    renderBody(state);
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
    applyDayFilter(state);
    renderDaySelector(state);
    renderBody(state);
    renderUnscheduledPanel(state);
    return null;
}).catch(Notification.exception);

/**
 * Groups state.allSlots by day, picks/keeps a selected day, and sets state.slots to that
 * day's subset -- the only array computeTimeline()/renderBody() read from. Room headers
 * and the unscheduled panel are rendered from state.rooms/state.unscheduled directly, so
 * they are unaffected by which day is selected.
 *
 * @param {Object} state The module state object
 */
const applyDayFilter = (state) => {
    const groups = DayUtils.groupSlotsByDay(state.allSlots);
    state.dayKeys = DayUtils.sortedDayKeys(groups);
    if (!state.selectedDay || !state.dayKeys.includes(state.selectedDay)) {
        state.selectedDay = DayUtils.defaultDayKey(state.dayKeys);
    }
    state.slots = state.selectedDay ? (groups[state.selectedDay] || []) : [];
};

/**
 * Renders the day-selector <select> options and wires it to re-filter/re-render the body
 * on change. A no-op (element left hidden) when there is nothing scheduled yet.
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

    state.dayKeys.forEach((key) => {
        const option = document.createElement('option');
        option.value = key;
        option.textContent = DayUtils.formatDayLabel(key);
        option.selected = key === state.selectedDay;
        select.appendChild(option);
    });
};

/**
 * Renders the room column headers, including the drag handle (core/sortable_list) and edit/delete buttons.
 *
 * @param {Object} state The module state object
 */
const renderHeaders = (state) => {
    const scrollEl = state.root.querySelector('.mod_confscheduler-grid-scroll');
    const existing = state.root.querySelector('.mod_confscheduler-room-headers');
    if (existing) {
        existing.remove();
    }

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

        const handle = document.createElement('span');
        handle.className = 'mod_confscheduler-room-draghandle';
        handle.setAttribute('data-drag-type', 'move');
        handle.setAttribute('tabindex', '0');
        handle.setAttribute('role', 'button');
        handle.setAttribute('aria-label', state.strings.movecolumn);
        handle.textContent = '⋮⋮';
        header.appendChild(handle);

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

    const gridEl = state.root.querySelector('.mod_confscheduler-grid');
    scrollEl.insertBefore(headerRow, gridEl);

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
 * Renders the grid body: the time axis and, per room, an absolutely-positioned column containing
 * every scheduled block (single-room and column-spanning alike).
 *
 * @param {Object} state The module state object
 */
const renderBody = (state) => {
    computeTimeline(state);

    const gridEl = state.root.querySelector('.mod_confscheduler-grid');
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
    columnsWrap.style.width = (Math.max(state.rooms.length, 1) * COLUMN_WIDTH) + 'px';
    columnsWrap.style.height = totalHeight + 'px';

    state.rooms.forEach((room) => {
        const column = document.createElement('div');
        column.className = 'mod_confscheduler-room-column';
        column.dataset.roomid = room.id;
        if (room.colour) {
            column.style.setProperty('--mod_confscheduler-room-colour', room.colour);
            column.classList.add('has-colour');
        }
        columnsWrap.appendChild(column);
    });

    gridEl.appendChild(columnsWrap);
    state.columnsWrap = columnsWrap;

    state.slots.forEach((slot) => renderBlock(state, columnsWrap, slot));
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
    block.style.left = (minIndex * COLUMN_WIDTH) + 'px';
    block.style.width = (span * COLUMN_WIDTH - 6) + 'px';
    block.style.top = timeToY(state, slot.starttime) + 'px';
    block.style.height = Math.max(20, timeToY(state, slot.endtime) - timeToY(state, slot.starttime)) + 'px';

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'mod_confscheduler-block-remove';
    removeBtn.setAttribute('aria-label', state.strings.unschedule);
    removeBtn.textContent = '×';
    block.appendChild(removeBtn);

    if (isSpanBlock) {
        if (slot.colour) {
            block.style.backgroundColor = slot.colour;
            const textColour = ColourUtils.contrastTextColour(slot.colour);
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
    block.appendChild(resizeHandle);

    columnsWrap.appendChild(block);
};

/**
 * Renders the "unscheduled" panel: accepted submissions not yet placed in the grid.
 *
 * @param {Object} state The module state object
 */
const renderUnscheduledPanel = (state) => {
    const list = state.root.querySelector('.mod_confscheduler-unscheduled-list');
    if (!list) {
        return;
    }
    list.innerHTML = '';

    state.unscheduled.forEach((item) => {
        const card = document.createElement('div');
        card.className = 'mod_confscheduler-unscheduled-card';
        card.setAttribute('role', 'listitem');
        card.dataset.submissionid = item.submissionid;

        const title = document.createElement('div');
        title.className = 'mod_confscheduler-unscheduled-title';
        title.textContent = item.title;
        card.appendChild(title);

        const speakers = document.createElement('div');
        speakers.className = 'mod_confscheduler-unscheduled-speakers';
        speakers.textContent = item.speakers;
        card.appendChild(speakers);

        if (item.track) {
            card.appendChild(buildTrackPill(state.programUrl, item.trackid, item.track, state.strings.filterbytrack));
        }

        const sessiontag = document.createElement('input');
        sessiontag.type = 'text';
        sessiontag.className = 'form-control form-control-sm mod_confscheduler-unscheduled-sessiontag';
        sessiontag.maxLength = 255;
        sessiontag.value = item.sessiontag || '';
        sessiontag.placeholder = state.strings.sessiontagplaceholder;
        sessiontag.setAttribute('aria-label', state.strings.sessiontag);
        sessiontag.dataset.submissionid = item.submissionid;
        card.appendChild(sessiontag);

        list.appendChild(card);
    });
};

/**
 * Saves a submission's session-tag label after the organiser edits the
 * inline input in the unscheduled panel. No grid re-render is needed: the
 * session tag never changes which submissions are scheduled/unscheduled, it
 * only affects a later autoscheduler run.
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} inputEl The session-tag input that changed
 */
const onSessionTagChange = (state, inputEl) => {
    const submissionid = Number(inputEl.dataset.submissionid);
    const label = inputEl.value.trim();

    inputEl.disabled = true;
    Promise.resolve(Repository.setSessionTag(state.cmid, submissionid, label)).then((result) => {
        inputEl.value = result.label;
        return result;
    }).catch(Notification.exception).finally(() => {
        inputEl.disabled = false;
    });
};

/**
 * Begins a free-form drag of an already-scheduled block, to move it to a new time/room.
 * On drop, the new position is computed from where the drag proxy was released, snapped to
 * SNAP_MINUTES, and sent to the server; the real block element is left untouched until then,
 * so a server rejection needs no explicit "revert" beyond removing the proxy.
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

    Dragdrop.start(event, proxy, () => {}, (pageX, pageY, proxyEl) => {
        const offset = proxyEl.offset();
        proxyEl.remove();
        blockEl.classList.remove('mod_confscheduler-block-dragging');

        if (!state.rooms.length) {
            return;
        }

        const columnsRect = state.columnsWrap.getBoundingClientRect();
        const relX = offset.left - (columnsRect.left + window.scrollX);
        const relY = offset.top - (columnsRect.top + window.scrollY);

        const index = clamp(Math.round(relX / COLUMN_WIDTH), 0, Math.max(state.rooms.length - span, 0));
        const newStart = snapTime(yToTime(state, relY), SNAP_MINUTES);
        const newEnd = newStart + duration;
        const newRoomids = state.rooms.slice(index, index + span).map((room) => room.id);

        if (newStart === starttime && JSON.stringify(newRoomids) === JSON.stringify(roomids)) {
            return;
        }

        Repository.rescheduleSlot(state.cmid, slotid, newRoomids, newStart, newEnd)
            .then(() => fetchAndRenderBody(state))
            .catch(Notification.exception);
    });
};

/**
 * Begins a vertical-only drag of a block's resize handle, to change only its end time.
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
 * The new block is given a fixed DEFAULT_DURATION_MINUTES duration; the block's height
 * (and hence its end time) can then be adjusted with the resize handle once scheduled.
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
    const duration = DEFAULT_DURATION_MINUTES * 60;

    Dragdrop.start(event, proxy, () => {}, (pageX, pageY, proxyEl) => {
        const offset = proxyEl.offset();
        proxyEl.remove();

        if (!state.rooms.length) {
            return;
        }

        const columnsRect = state.columnsWrap.getBoundingClientRect();
        const relX = offset.left - (columnsRect.left + window.scrollX);
        const relY = offset.top - (columnsRect.top + window.scrollY);

        if (relX < 0 || relX >= state.rooms.length * COLUMN_WIDTH || relY < 0) {
            // Dropped outside the grid: leave the card in the unscheduled panel, no-op.
            return;
        }

        const index = clamp(Math.floor(relX / COLUMN_WIDTH), 0, state.rooms.length - 1);
        const start = snapTime(yToTime(state, relY), SNAP_MINUTES);
        const roomids = [state.rooms[index].id];

        Repository.scheduleSubmission(state.cmid, submissionid, roomids, start, start + duration)
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
 * Opens the "run autoscheduler" modal: a time window, a default duration
 * applied to every submission it places, and a "clear first" checkbox.
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

    modal.getRoot().on(ModalEvents.save, (event) => {
        event.preventDefault();

        const form = modal.getRoot()[0].querySelector('.mod_confscheduler-autoscheduler-form');
        const windowstartValue = form.querySelector('[name=windowstart]').value;
        const windowendValue = form.querySelector('[name=windowend]').value;
        const duration = Number(form.querySelector('[name=defaultdurationminutes]').value);
        const clearfirst = form.querySelector('[name=clearfirst]').checked;

        if (!windowstartValue || !windowendValue || !duration || duration <= 0) {
            return;
        }

        const windowstart = Math.floor(new Date(windowstartValue).getTime() / 1000);
        const windowend = Math.floor(new Date(windowendValue).getTime() / 1000);
        if (windowend <= windowstart) {
            return;
        }

        Repository.runAutoscheduler(state.cmid, windowstart, windowend, duration, clearfirst).then((result) => {
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
        const sessiontagInput = event.target.closest('.mod_confscheduler-unscheduled-sessiontag');
        if (sessiontagInput) {
            onSessionTagChange(state, sessiontagInput);
            return;
        }

        const daySelect = event.target.closest('.mod_confscheduler-day-select');
        if (daySelect) {
            state.selectedDay = daySelect.value;
            applyDayFilter(state);
            renderBody(state);
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
        sessiontag, sessiontagplaceholder, filterbytrack,
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
        {key: 'sessiontag', component: 'mod_confscheduler'},
        {key: 'sessiontagplaceholder', component: 'mod_confscheduler'},
        {key: 'filterbytrack', component: 'mod_confscheduler'},
    ]);

    const state = {
        cmid,
        confschedulerid,
        programUrl: programurl,
        root,
        rooms: [],
        allSlots: [],
        slots: [],
        dayKeys: [],
        selectedDay: null,
        unscheduled: [],
        gapminutes: 0,
        timelineStart: 0,
        timelineEnd: 0,
        columnsWrap: null,
        sortableList: null,
        strings: {
            unschedule, favourite, editroom, deleteroom, confirmdeleteroom,
            cancel, movecolumn, addroom, addspanblock, editspanblock, autoschedulerrun,
            sessiontag, sessiontagplaceholder, filterbytrack,
        },
    };

    bindEvents(state);
    await fetchAndRenderAll(state);
};
