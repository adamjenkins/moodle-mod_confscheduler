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

import Ajax from 'core/ajax';
import Modal from 'core/modal';
import Notification from 'core/notification';
import {getString} from 'core/str';
import * as Repository from 'mod_confscheduler/repository';
import * as DayUtils from 'mod_confscheduler/day_utils';

/**
 * Read-only Display-mode schedule grid (Phase 3.5), for users holding only
 * mod/confscheduler:viewschedule (not :manageschedule) -- see view.php.
 *
 * Deliberately a SEPARATE module from amd/src/scheduler_grid.js rather than a
 * shared renderer with an "edit mode" flag: scheduler_grid.js's block
 * rendering is tightly interleaved with its core/dragdrop state (drag
 * proxies, resize handles, dataset read back mid-drag) in a way that a
 * read-only caller has no use for, and threading a canEdit flag through every
 * one of those code paths would have made the already-shipped,
 * security-reviewed drag-and-drop grid harder to read for no real benefit to
 * either mode. The genuinely shared logic -- day-boundary computation -- IS
 * factored out, into amd/src/day_utils.js, and used by both modules. See
 * README's "Architecture notes" for the full rationale.
 *
 * Fetches the same mod_confscheduler_get_grid_data payload the edit grid
 * uses (it is deliberately gated on the weaker :viewschedule capability for
 * exactly this reason) and renders it read-only: no drag handles, no resize
 * handle, no unschedule/remove control, no room CRUD, no unscheduled panel.
 * Scheduled presentation blocks link through to their mod_confprogram detail
 * (see openProgramDetail()); the favourite star remains interactive (still
 * gated by mod/confscheduler:favourite via the existing toggle_favourite AJAX
 * endpoint, same as the edit grid).
 *
 * Adds two Display-mode-only features on top of the shared grid rendering:
 * a "my timetable" toggle (client-side only, over the 'favourited' field the
 * grid payload already carries -- no new server endpoint) that persists its
 * on/off state in sessionStorage per confscheduler instance, and print
 * controls (colour/black-and-white, paper size, orientation) implemented
 * entirely via CSS (@media print / a dynamically-written @page rule); see
 * styles.css.
 *
 * @module     mod_confscheduler/scheduler_display
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {Number} Fixed column width in pixels; keep in sync with scheduler_grid.js/styles.css. */
const COLUMN_WIDTH = 200;

/** @type {Number} Vertical px/minute of scheduled time; keep in sync with scheduler_grid.js/styles.css. */
const PX_PER_MINUTE = 2.4;

/**
 * Formats a unix timestamp as a short local time (e.g. "14:05").
 *
 * @param {Number} timestamp Unix timestamp (seconds)
 * @return {String}
 */
const formatTime = (timestamp) => new Date(timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});

/**
 * Converts a unix timestamp to a Y pixel offset within the grid body, relative to a day's start.
 *
 * @param {Number} daystart Unix timestamp (seconds) of the visible day's lower bound
 * @param {Number} timestamp Unix timestamp (seconds)
 * @return {Number} Pixel offset
 */
const timeToY = (daystart, timestamp) => Math.max(0, (timestamp - daystart) / 60 * PX_PER_MINUTE);

/**
 * Computes the visible vertical time range for the currently selected day: the
 * earliest/latest of that day's slot start/end times, padded by 30 minutes at
 * each end and rounded to whole hours, with an 8-hour minimum span. Mirrors
 * scheduler_grid.js's computeTimeline(), scoped to one day's slots instead of
 * the whole instance's.
 *
 * @param {Object[]} dayslots Slots belonging to the currently selected day
 * @return {{start: Number, end: Number}}
 */
const computeDayTimeRange = (dayslots) => {
    const times = [];
    dayslots.forEach((slot) => {
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

    return {start, end};
};

/**
 * Reads the persisted "my timetable" toggle state for a confscheduler instance.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @return {Boolean}
 */
const readMyTimetableState = (cmid) => {
    try {
        return window.sessionStorage.getItem(`mod_confscheduler_mytimetable_${cmid}`) === '1';
    } catch (e) {
        // sessionStorage can throw in locked-down browser contexts (e.g. some privacy modes);
        // degrade to "off" rather than breaking the page.
        return false;
    }
};

/**
 * Persists the "my timetable" toggle state for a confscheduler instance.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Boolean} active
 */
const writeMyTimetableState = (cmid, active) => {
    try {
        window.sessionStorage.setItem(`mod_confscheduler_mytimetable_${cmid}`, active ? '1' : '0');
    } catch (e) {
        // See readMyTimetableState(): degrade silently.
    }
};

/**
 * Fetches an accepted submission's detail from mod_confprogram and shows it in a modal,
 * identically to how mod_confprogram's own Display-phase list does it
 * (amd/src/programlist.js's openDetail()) -- this plugin calls mod_confprogram's
 * mod_confprogram_get_submission_detail external function directly (a browser-side AJAX
 * call into another plugin's already-hardened, capability- and phase-embargo-checked
 * endpoint), the same direct-API-coupling pattern this plugin's PHP layer already uses
 * for favourites (see toggle_favourite.php calling \mod_confprogram\api:: directly).
 * mod_confprogram is not modified to support this -- its external function is public
 * integration surface already reachable from any AMD module, not something that needs
 * changing to be called from here.
 *
 * @param {Number} confprogramcmid The mod_confprogram course-module id
 * @param {Number} submissionid The confsubmissions_submission id
 * @return {Promise}
 */
const openProgramDetail = (confprogramcmid, submissionid) => Ajax.call([{
    methodname: 'mod_confprogram_get_submission_detail',
    args: {cmid: confprogramcmid, submissionid},
}])[0].then((result) => Modal.create({
    title: result.title,
    body: result.html,
    show: true,
    removeOnClose: true,
})).catch(Notification.exception);

/**
 * Renders a single read-only scheduled block (presentation or column-spanning label
 * block) and appends it to the grid's columns container.
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
    block.className = 'mod_confscheduler-block mod_confscheduler-block-readonly'
        + (isSpanBlock ? ' mod_confscheduler-block-span' : '');
    block.dataset.slotid = slot.id;
    block.dataset.submissionid = slot.submissionid !== null ? slot.submissionid : '';
    block.dataset.favourited = slot.favourited ? '1' : '0';
    block.style.left = (minIndex * COLUMN_WIDTH) + 'px';
    block.style.width = (span * COLUMN_WIDTH - 6) + 'px';
    block.style.top = timeToY(state.dayStart, slot.starttime) + 'px';
    block.style.height = Math.max(20, timeToY(state.dayStart, slot.endtime) - timeToY(state.dayStart, slot.starttime)) + 'px';

    if (isSpanBlock) {
        const label = document.createElement('div');
        label.className = 'mod_confscheduler-block-label';
        label.textContent = slot.label || '';
        block.appendChild(label);
    } else {
        if (state.canfavourite) {
            const favBtn = document.createElement('button');
            favBtn.type = 'button';
            favBtn.className = 'mod_confscheduler-block-fav';
            favBtn.dataset.favourited = slot.favourited ? '1' : '0';
            favBtn.setAttribute('aria-pressed', slot.favourited ? 'true' : 'false');
            favBtn.setAttribute('aria-label', state.strings.favourite);
            favBtn.innerHTML = slot.favourited ? '&#9733;' : '&#9734;';
            block.appendChild(favBtn);
        } else if (slot.favourited) {
            // Not this user's to toggle (not logged in with mod/confscheduler:favourite,
            // e.g. a guest) but the favourited state itself is still shown, per spec. A
            // visually-hidden text label (not aria-hidden) keeps this state available to
            // screen readers, matching the interactive button's aria-label above.
            const favIcon = document.createElement('span');
            favIcon.className = 'mod_confscheduler-block-fav mod_confscheduler-block-fav-readonly';
            favIcon.innerHTML = '&#9733;';
            const favIconLabel = document.createElement('span');
            favIconLabel.className = 'sr-only';
            favIconLabel.textContent = state.strings.favourite;
            favIcon.appendChild(favIconLabel);
            block.appendChild(favIcon);
        }

        const link = document.createElement('a');
        link.className = 'mod_confscheduler-block-link';
        link.href = state.programUrl;

        const title = document.createElement('div');
        title.className = 'mod_confscheduler-block-title';
        title.textContent = slot.title || '';
        link.appendChild(title);

        const speakers = document.createElement('div');
        speakers.className = 'mod_confscheduler-block-speakers';
        speakers.textContent = slot.speakers || '';
        link.appendChild(speakers);

        const footer = document.createElement('div');
        footer.className = 'mod_confscheduler-block-footer';
        if (slot.track) {
            const pill = document.createElement('span');
            pill.className = 'mod_confscheduler-track-pill';
            pill.textContent = slot.track;
            footer.appendChild(pill);
        }
        link.appendChild(footer);

        block.appendChild(link);
    }

    const roomNames = slot.roomids
        .map((id) => (state.rooms.find((room) => room.id === id) || {}).name)
        .filter(Boolean)
        .join(', ');
    const roomTime = document.createElement('div');
    roomTime.className = 'mod_confscheduler-block-roomtime';
    roomTime.textContent = `${roomNames}, ${formatTime(slot.starttime)}–${formatTime(slot.endtime)}`;
    block.appendChild(roomTime);

    columnsWrap.appendChild(block);
};

/**
 * Renders the room column headers (no drag handle, no edit/delete actions).
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
        }

        const name = document.createElement('span');
        name.className = 'mod_confscheduler-room-name';
        name.textContent = room.name;
        header.appendChild(name);

        headerRow.appendChild(header);
    });

    const gridEl = state.root.querySelector('.mod_confscheduler-grid');
    scrollEl.insertBefore(headerRow, gridEl);
};

/**
 * Renders the grid body for the currently selected day: the time axis and, per room, an
 * absolutely-positioned column containing that day's blocks.
 *
 * @param {Object} state The module state object
 */
const renderBody = (state) => {
    const dayslots = state.selectedDay ? (state.slotsByDay[state.selectedDay] || []) : [];
    const range = computeDayTimeRange(dayslots);
    state.dayStart = range.start;

    const gridEl = state.root.querySelector('.mod_confscheduler-grid');
    gridEl.innerHTML = '';

    const totalHeight = timeToY(range.start, range.end);

    const timeAxis = document.createElement('div');
    timeAxis.className = 'mod_confscheduler-time-axis';
    timeAxis.style.height = totalHeight + 'px';
    for (let hour = Math.ceil(range.start / 3600) * 3600; hour <= range.end; hour += 3600) {
        const label = document.createElement('div');
        label.className = 'mod_confscheduler-time-label';
        label.style.top = timeToY(range.start, hour) + 'px';
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

    dayslots.forEach((slot) => renderBlock(state, columnsWrap, slot));

    state.root.classList.toggle('mod_confscheduler-mytimetable-active', state.myTimetableActive);
};

/**
 * Renders the day-selector <select> options and wires it to re-render the body on change.
 * A no-op (element left empty/hidden) when there is nothing scheduled yet.
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
 * Re-fetches the grid payload and re-renders everything.
 *
 * @param {Object} state The module state object
 * @return {Promise}
 */
const fetchAndRenderAll = (state) => Repository.getGridData(state.cmid).then((data) => {
    state.rooms = data.rooms;
    state.slotsByDay = DayUtils.groupSlotsByDay(data.slots);
    state.dayKeys = DayUtils.sortedDayKeys(state.slotsByDay);
    if (!state.selectedDay || !state.dayKeys.includes(state.selectedDay)) {
        state.selectedDay = DayUtils.defaultDayKey(state.dayKeys);
    }
    renderHeaders(state);
    renderDaySelector(state);
    renderBody(state);
    return null;
}).catch(Notification.exception);

/**
 * Toggles the favourite star on a scheduled presentation block.
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} favBtn The favourite-toggle button that was clicked
 */
const onFavouriteClick = (state, favBtn) => {
    const block = favBtn.closest('.mod_confscheduler-block');
    const slotid = Number(block.dataset.slotid);
    const target = favBtn.dataset.favourited !== '1';

    favBtn.disabled = true;
    Promise.resolve(Repository.toggleFavourite(state.cmid, slotid, target)).then((result) => {
        favBtn.dataset.favourited = result.favourited ? '1' : '0';
        block.dataset.favourited = result.favourited ? '1' : '0';
        favBtn.setAttribute('aria-pressed', result.favourited ? 'true' : 'false');
        favBtn.innerHTML = result.favourited ? '&#9733;' : '&#9734;';
        return result;
    }).catch(Notification.exception).finally(() => {
        favBtn.disabled = false;
    });
};

/**
 * Writes (or replaces) the dynamically-generated @page rule controlling print paper size
 * and orientation. @page is a top-level-only at-rule -- it cannot be scoped under a class
 * selector -- so the standard way to make it respond to a runtime UI choice is to
 * (re)write a dedicated <style> element's content, rather than pre-declaring every
 * combination as static CSS.
 *
 * @param {String} papersize 'A4', 'A3', or 'A2'
 * @param {String} orientation 'portrait' or 'landscape'
 */
const applyPageSize = (papersize, orientation) => {
    let styleEl = document.getElementById('mod_confscheduler-print-page-rule');
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = 'mod_confscheduler-print-page-rule';
        document.head.appendChild(styleEl);
    }
    styleEl.textContent = `@page { size: ${papersize} ${orientation}; }`;
};

/**
 * Binds all delegated event listeners for the Display-mode grid. Called once at init.
 *
 * @param {Object} state The module state object
 */
const bindEvents = (state) => {
    state.root.addEventListener('click', (event) => {
        const favBtn = event.target.closest('.mod_confscheduler-block-fav:not(.mod_confscheduler-block-fav-readonly)');
        if (favBtn) {
            event.preventDefault();
            onFavouriteClick(state, favBtn);
            return;
        }

        const link = event.target.closest('.mod_confscheduler-block-link');
        if (link) {
            event.preventDefault();
            const block = link.closest('.mod_confscheduler-block');
            const submissionid = Number(block.dataset.submissionid);
            if (submissionid) {
                openProgramDetail(state.confprogramcmid, submissionid);
            }
            return;
        }

        const myTimetableBtn = event.target.closest('.mod_confscheduler-mytimetable-toggle');
        if (myTimetableBtn) {
            state.myTimetableActive = !state.myTimetableActive;
            writeMyTimetableState(state.cmid, state.myTimetableActive);
            myTimetableBtn.setAttribute('aria-pressed', state.myTimetableActive ? 'true' : 'false');
            myTimetableBtn.classList.toggle('active', state.myTimetableActive);
            state.root.classList.toggle('mod_confscheduler-mytimetable-active', state.myTimetableActive);
            return;
        }

        const printBtn = event.target.closest('.mod_confscheduler-print-trigger');
        if (printBtn) {
            window.print();
        }
    });

    state.root.addEventListener('change', (event) => {
        const daySelect = event.target.closest('.mod_confscheduler-day-select');
        if (daySelect) {
            state.selectedDay = daySelect.value;
            renderBody(state);
            return;
        }

        const bwRadio = event.target.closest('[name=mod_confscheduler_printcolour]');
        if (bwRadio) {
            state.root.classList.toggle('mod_confscheduler-print-bw', bwRadio.value === 'bw' && bwRadio.checked);
            return;
        }

        const sizeSelect = event.target.closest('.mod_confscheduler-print-papersize');
        const orientationRadio = event.target.closest('[name=mod_confscheduler_printorientation]');
        if (sizeSelect || orientationRadio) {
            const papersize = state.root.querySelector('.mod_confscheduler-print-papersize').value;
            const orientation = state.root.querySelector('[name=mod_confscheduler_printorientation]:checked').value;
            applyPageSize(papersize, orientation);
        }
    });
};

/**
 * Initialises the read-only Display-mode schedule grid. Called from view.php.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} confschedulerid The confscheduler instance id
 * @param {Number} confprogramcmid The linked mod_confprogram course-module id
 * @param {String} programurl The URL of the linked mod_confprogram activity's view page
 * @param {Boolean} canfavourite Whether the current user may toggle favourites
 * @return {Promise}
 */
export const init = async(cmid, confschedulerid, confprogramcmid, programurl, canfavourite) => {
    const root = document.getElementById('mod_confscheduler-display-root');
    if (!root) {
        return;
    }

    const favourite = await getString('favourite', 'mod_confscheduler');

    const state = {
        cmid,
        confschedulerid,
        confprogramcmid,
        programUrl: programurl,
        canfavourite,
        root,
        rooms: [],
        slotsByDay: {},
        dayKeys: [],
        selectedDay: null,
        dayStart: 0,
        myTimetableActive: readMyTimetableState(cmid),
        strings: {favourite},
    };

    const myTimetableBtn = root.querySelector('.mod_confscheduler-mytimetable-toggle');
    if (myTimetableBtn) {
        myTimetableBtn.setAttribute('aria-pressed', state.myTimetableActive ? 'true' : 'false');
        myTimetableBtn.classList.toggle('active', state.myTimetableActive);
    }

    const papersizeEl = root.querySelector('.mod_confscheduler-print-papersize');
    const orientationEl = root.querySelector('[name=mod_confscheduler_printorientation]:checked');
    if (papersizeEl && orientationEl) {
        applyPageSize(papersizeEl.value, orientationEl.value);
    }

    bindEvents(state);
    await fetchAndRenderAll(state);
};
