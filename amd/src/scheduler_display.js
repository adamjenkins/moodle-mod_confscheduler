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
import * as ColourUtils from 'mod_confscheduler/colour_utils';

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
 * on/off state in sessionStorage per confscheduler instance, and a colour/
 * black-and-white toggle (applies live on screen, not just when printing --
 * user feedback, 2026-07-06; see styles.css's .mod_confscheduler-bw rules).
 * Paper size and orientation are deliberately NOT controlled by this plugin
 * at all (same feedback): they're left entirely to the browser's own print
 * dialog, which already offers better scaling (including A2) than this
 * plugin's former forced @page rule ever did.
 *
 * Room/span-block colour contrast (Revision round 1, 2026-07-03): wherever a
 * room's or span block's chosen colour is used as a background, the shared
 * amd/src/colour_utils.js helper picks black or white text automatically. See
 * amd/src/scheduler_grid.js for the identical treatment in edit mode.
 *
 * @module     mod_confscheduler/scheduler_display
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @type {Number} Minimum column width in pixels; keep in sync with
 * scheduler_grid.js/styles.css. Columns stretch wider than this to fill available
 * space (user feedback, 2026-07-05); see renderBlock()'s percentage-based
 * positioning, which does not depend on this constant matching the actual
 * rendered width.
 */
const COLUMN_WIDTH = 200;

/**
 * @type {Number} Default row height (vertical pixels per hour) before the real,
 * organiser-configured value has loaded from the server -- keep in sync with
 * scheduler_grid.js/classes/api.php's DEFAULT_PX_PER_HOUR.
 */
const DEFAULT_PX_PER_HOUR = 144;

/**
 * Formats a unix timestamp as a short local time (e.g. "14:05").
 *
 * @param {Number} timestamp Unix timestamp (seconds)
 * @return {String}
 */
const formatTime = (timestamp) => new Date(timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});

/**
 * Converts a unix timestamp to a Y pixel offset within the grid body, relative to a day's
 * start. Uses the instance's own configured row height (state.pxperhour), synced from the
 * same mod_confscheduler_get_grid_data payload the edit grid uses, so Display mode renders
 * at the identical density an organiser configured -- see scheduler_grid.js's identical
 * treatment.
 *
 * @param {Object} state The module state object
 * @param {Number} daystart Unix timestamp (seconds) of the visible day's lower bound
 * @param {Number} timestamp Unix timestamp (seconds)
 * @return {Number} Pixel offset
 */
const timeToY = (state, daystart, timestamp) => Math.max(0, (timestamp - daystart) / 60 * (state.pxperhour / 60));

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
 * Builds a track pill element (an <a> linking to the linked mod_confprogram instance's
 * accepted-submissions list, filtered to this track, when a programUrl/trackid are
 * available; otherwise a plain, non-interactive <span>). Mirrors
 * scheduler_grid.js's identical helper (kept duplicated rather than factored into a
 * shared module: it is a two-line, DOM-producing helper, not the kind of pure
 * data-only logic amd/src/day_utils.js/colour_utils.js share).
 *
 * @param {String|null} programUrl The linked mod_confprogram activity's base view URL (already has ?id=...), or null
 * @param {Number|null} trackid The confsubmissions_track id, or null
 * @param {String} trackname The track's display name
 * @param {String|null} trackcolour The track's configured hex colour, or null/empty for the default
 * @param {String|null} filterbytrackstr The raw (unsubstituted) 'filterbytrack' lang string, or null
 * @return {HTMLElement}
 */
const buildTrackPill = (programUrl, trackid, trackname, trackcolour, filterbytrackstr) => {
    const applyColour = (pill) => {
        if (trackcolour) {
            pill.style.backgroundColor = trackcolour;
            const textColour = ColourUtils.contrastTextColour(trackcolour);
            if (textColour) {
                pill.style.color = textColour;
            }
        }
    };

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
        applyColour(pill);
        return pill;
    }

    const pill = document.createElement('span');
    pill.className = 'mod_confscheduler-track-pill';
    pill.textContent = trackname;
    applyColour(pill);
    return pill;
};

/**
 * Renders a single read-only scheduled block (presentation or column-spanning label
 * block) and appends it to the grid's columns container.
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} columnsWrap The .mod_confscheduler-columns container
 * @param {Object} slot A slot entry as returned by mod_confscheduler_get_grid_data
 * @param {Object[]} slots All of this day's slots (used to find a container's children)
 */
const renderBlock = (state, columnsWrap, slot, slots) => {
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
        + (isSpanBlock ? ' mod_confscheduler-block-span' : '')
        + (slot.withdrawn ? ' mod_confscheduler-block-withdrawn' : '');
    block.dataset.slotid = slot.id;
    block.dataset.submissionid = slot.submissionid !== null ? slot.submissionid : '';
    block.dataset.favourited = slot.favourited ? '1' : '0';
    block.dataset.withdrawn = slot.withdrawn ? '1' : '0';
    // Percentages of columnsWrap's own width, not pixels -- see
    // scheduler_grid.js's identical treatment (renderBlock()'s docblock there)
    // for why: stays correctly aligned to its room column(s) regardless of how
    // wide columnsWrap actually renders once columns stretch to fill available
    // space (user feedback, 2026-07-05).
    const roomcount = Math.max(state.rooms.length, 1);
    block.style.left = ((minIndex / roomcount) * 100) + '%';
    block.style.width = `calc(${(span / roomcount) * 100}% - 6px)`;
    const topY = timeToY(state, state.dayStart, slot.starttime);
    const bottomY = timeToY(state, state.dayStart, slot.endtime);
    block.style.top = topY + 'px';
    block.style.height = Math.max(20, bottomY - topY) + 'px';

    if (isSpanBlock) {
        if (slot.colour) {
            block.style.backgroundColor = slot.colour;
            const textColour = ColourUtils.contrastTextColour(slot.colour);
            if (textColour) {
                block.style.color = textColour;
            }
        }

        const label = document.createElement('div');
        label.className = 'mod_confscheduler-block-label';
        label.textContent = slot.label || '';
        block.appendChild(label);

        if (slot.iscontainer) {
            const childHolder = document.createElement('div');
            childHolder.className = 'mod_confscheduler-container-children';
            const children = slots.filter((candidate) => candidate.parentslotid === slot.id);
            childHolder.style.gridTemplateColumns = `repeat(${Math.max(children.length, 1)}, 1fr)`;
            children.forEach((child) => renderContainerChild(state, childHolder, child));
            block.appendChild(childHolder);
        }
    } else {
        // A withdrawn presentation is not favouritable (there is nothing left to attend),
        // so neither the interactive star nor the readonly one renders for it -- the block
        // only ever shows its withdrawn styling/click notice below, regardless of any
        // favourited state a user may have set before it was withdrawn.
        if (state.canfavourite && !slot.withdrawn) {
            const favBtn = document.createElement('button');
            favBtn.type = 'button';
            favBtn.className = 'mod_confscheduler-block-fav';
            favBtn.dataset.favourited = slot.favourited ? '1' : '0';
            favBtn.setAttribute('aria-pressed', slot.favourited ? 'true' : 'false');
            favBtn.setAttribute('aria-label', state.strings.favourite);
            favBtn.innerHTML = slot.favourited ? '&#9733;' : '&#9734;';
            block.appendChild(favBtn);
        } else if (slot.favourited && !slot.withdrawn) {
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
        link.className = 'mod_confscheduler-block-link stretched-link';
        link.href = state.programUrl;

        const title = document.createElement('div');
        title.className = 'mod_confscheduler-block-title';
        title.textContent = slot.title || '';
        link.appendChild(title);

        const speakers = document.createElement('div');
        speakers.className = 'mod_confscheduler-block-speakers';
        speakers.textContent = slot.speakers || '';
        link.appendChild(speakers);

        block.appendChild(link);

        // The track pill is its own <a> (Revision round 1, click-through to the linked
        // mod_confprogram list filtered to this track) and is deliberately kept OUTSIDE
        // .mod_confscheduler-block-link above: nesting an <a> inside another <a> is
        // invalid HTML and would make the pill's own click target unreliable.
        const footer = document.createElement('div');
        footer.className = 'mod_confscheduler-block-footer';
        if (slot.track) {
            footer.appendChild(
                buildTrackPill(state.programUrl, slot.trackid, slot.track, slot.trackcolour, state.strings.filterbytrack)
            );
        }
        block.appendChild(footer);
    }

    const roomNames = slot.roomnameoverride || slot.roomids
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
 * Renders one compact, read-only child block inside a container's
 * child-holder. Deliberately shows only a favourite star (if
 * state.canfavourite), title, and speakers -- no room or time, permanently,
 * same rationale as scheduler_grid.js's identical-purpose renderContainerChild()
 * (user request, 2026-07-08): the container's own roomtime line already shows
 * that information once for the whole group. Wrapped in the same
 * .mod_confscheduler-block-link click-through-to-detail-modal anchor a normal
 * presentation block already uses in this file, so a container child is
 * clickable exactly like a normally-scheduled presentation (the detail modal
 * it opens is unaffected by this and still shows the child's real room/time).
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} holder The .mod_confscheduler-container-children element to append into
 * @param {Object} slot The child slot entry (from state.slots)
 */
const renderContainerChild = (state, holder, slot) => {
    const child = document.createElement('div');
    child.className = 'mod_confscheduler-block mod_confscheduler-block-child'
        + (slot.withdrawn ? ' mod_confscheduler-block-withdrawn' : '');
    child.dataset.slotid = slot.id;
    child.dataset.submissionid = slot.submissionid !== null ? slot.submissionid : '';
    child.dataset.favourited = slot.favourited ? '1' : '0';
    child.dataset.withdrawn = slot.withdrawn ? '1' : '0';
    child.dataset.align = slot.childtextalign;
    child.dataset.valign = slot.childtextvalign;

    if (state.canfavourite && !slot.withdrawn) {
        const favBtn = document.createElement('button');
        favBtn.type = 'button';
        favBtn.className = 'mod_confscheduler-block-fav';
        favBtn.dataset.favourited = slot.favourited ? '1' : '0';
        favBtn.setAttribute('aria-pressed', slot.favourited ? 'true' : 'false');
        favBtn.setAttribute('aria-label', state.strings.favourite);
        favBtn.innerHTML = slot.favourited ? '&#9733;' : '&#9734;';
        child.appendChild(favBtn);
    } else if (slot.favourited && !slot.withdrawn) {
        const favIcon = document.createElement('span');
        favIcon.className = 'mod_confscheduler-block-fav mod_confscheduler-block-fav-readonly';
        favIcon.innerHTML = '&#9733;';
        const favIconLabel = document.createElement('span');
        favIconLabel.className = 'sr-only';
        favIconLabel.textContent = state.strings.favourite;
        favIcon.appendChild(favIconLabel);
        child.appendChild(favIcon);
    }

    const link = document.createElement('a');
    link.className = 'mod_confscheduler-block-link stretched-link';
    link.href = state.programUrl;

    const title = document.createElement('div');
    title.className = 'mod_confscheduler-block-title';
    title.textContent = slot.title || '';
    link.appendChild(title);

    const speakers = document.createElement('div');
    speakers.className = 'mod_confscheduler-block-speakers';
    speakers.textContent = slot.speakers || '';
    link.appendChild(speakers);

    child.appendChild(link);

    if (slot.track) {
        const footer = document.createElement('div');
        footer.className = 'mod_confscheduler-block-footer';
        footer.appendChild(
            buildTrackPill(state.programUrl, slot.trackid, slot.track, slot.trackcolour, state.strings.filterbytrack)
        );
        child.appendChild(footer);
    }

    holder.appendChild(child);
};

/**
 * Builds one room-header row (no drag handle, no edit/delete actions -- this is
 * read-only Display mode). Shared by renderHeaders() (the single persistent row) and
 * renderAllDaysBody() (one row per day; user feedback, 2026-07-05).
 *
 * @param {Object} state The module state object
 * @return {HTMLElement} The header row element (not yet inserted anywhere)
 */
const buildRoomHeaderRow = (state) => {
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

        const name = document.createElement('span');
        name.className = 'mod_confscheduler-room-name';
        name.textContent = room.name;
        header.appendChild(name);

        headerRow.appendChild(header);
    });

    return headerRow;
};

/**
 * Renders the single, persistent room column header row for single-day mode.
 *
 * @param {Object} state The module state object
 */
const renderHeaders = (state) => {
    const scrollEl = state.root.querySelector('.mod_confscheduler-grid-scroll');
    const existing = state.root.querySelector('.mod_confscheduler-room-headers');
    if (existing) {
        existing.remove();
    }

    const headerRow = buildRoomHeaderRow(state);

    const gridEl = state.root.querySelector('.mod_confscheduler-grid');
    scrollEl.insertBefore(headerRow, gridEl);
    gridEl.style.display = '';
    headerRow.style.display = '';
};

/**
 * Renders the grid body for the currently selected day: the time axis and, per room, an
 * absolutely-positioned column containing that day's blocks.
 *
 * @param {Object} state The module state object
 */
/**
 * Builds one day's complete grid (time axis + room columns + out-of-hours bands +
 * scheduled blocks) into the given container element, temporarily setting
 * state.dayStart to this day's range start -- renderBlock()/timeToY() read it from
 * state rather than taking it as a parameter. Shared by renderBody() (single-day
 * mode, into the one persistent .mod_confscheduler-grid) and renderAllDaysBody() (one
 * fresh grid per day; user feedback, 2026-07-05).
 *
 * @param {Object} state The module state object
 * @param {HTMLElement} gridEl The .mod_confscheduler-grid container to build into (cleared first)
 * @param {Object[]} slots This day's slots to render as blocks
 * @param {String} dayKey The day being rendered (YYYY-MM-DD)
 */
const buildDayGridInto = (state, gridEl, slots, dayKey) => {
    const eff = DayUtils.boundsForDay(dayKey, state.daystart, state.dayend, state.daybounds);
    const range = DayUtils.computeDayTimelineBounds(slots, dayKey, eff.daystart, eff.dayend);
    state.dayStart = range.start;

    gridEl.innerHTML = '';

    const totalHeight = timeToY(state, range.start, range.end);

    const timeAxis = document.createElement('div');
    timeAxis.className = 'mod_confscheduler-time-axis';
    timeAxis.style.height = totalHeight + 'px';
    for (let hour = Math.ceil(range.start / 3600) * 3600; hour <= range.end; hour += 3600) {
        const label = document.createElement('div');
        label.className = 'mod_confscheduler-time-label';
        label.style.top = timeToY(state, range.start, hour) + 'px';
        label.textContent = formatTime(hour);
        timeAxis.appendChild(label);
    }
    gridEl.appendChild(timeAxis);

    const columnsWrap = document.createElement('div');
    columnsWrap.className = 'mod_confscheduler-columns';
    // min-width, not width -- see scheduler_grid.js's identical treatment
    // (buildGridInto()'s docblock) for why.
    columnsWrap.style.minWidth = (Math.max(state.rooms.length, 1) * COLUMN_WIDTH) + 'px';
    columnsWrap.style.height = totalHeight + 'px';

    // Horizontal gridlines: solid on the hour, dotted on the half-hour -- see
    // scheduler_grid.js's identical treatment (buildGridInto()'s comment) and
    // DayUtils.gridlineTicks()'s own docblock for why.
    DayUtils.gridlineTicks(range.start, range.end).forEach((tick) => {
        const line = document.createElement('div');
        line.className = 'mod_confscheduler-gridline'
            + (tick.ishour ? ' mod_confscheduler-gridline-hour' : ' mod_confscheduler-gridline-halfhour');
        line.style.top = timeToY(state, range.start, tick.time) + 'px';
        columnsWrap.appendChild(line);
    });

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

    const bands = DayUtils.outOfHoursBands(
        dayKey,
        range.start,
        range.end,
        state.conferencestart,
        state.conferenceend,
        eff.daystart,
        eff.dayend
    );
    bands.forEach((band) => {
        const bandEl = document.createElement('div');
        bandEl.className = 'mod_confscheduler-outofhours-band';
        bandEl.style.top = timeToY(state, range.start, band.start) + 'px';
        bandEl.style.height = Math.max(0, timeToY(state, range.start, band.end) - timeToY(state, range.start, band.start)) + 'px';
        columnsWrap.appendChild(bandEl);
    });

    slots.filter((slot) => !slot.parentslotid).forEach((slot) => renderBlock(state, columnsWrap, slot, slots));
};

/**
 * Renders the grid body for single-day mode, into the one persistent
 * .mod_confscheduler-grid element.
 *
 * @param {Object} state The module state object
 */
const renderBody = (state) => {
    const dayslots = state.selectedDay ? (state.slotsByDay[state.selectedDay] || []) : [];
    const gridEl = state.root.querySelector('.mod_confscheduler-grid');
    buildDayGridInto(state, gridEl, dayslots, state.selectedDay);

    state.root.classList.toggle('mod_confscheduler-mytimetable-active', state.myTimetableActive);
};

/**
 * Renders the "All days" view (user feedback, 2026-07-05): every selectable day as
 * its own complete read-only table (heading + room headers + grid), stacked
 * vertically. Simpler than scheduler_grid.js's equivalent since Display mode has no
 * drag-and-drop to guard against across multiple simultaneous tables -- every
 * interaction here is already a plain click (favourite star, the modal link-through).
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

    state.dayKeys.forEach((dayKey) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'mod_confscheduler-day-table-wrapper';

        const heading = document.createElement('h4');
        heading.className = 'mod_confscheduler-day-heading';
        heading.textContent = DayUtils.formatDayLabel(dayKey);
        wrapper.appendChild(heading);

        const headerRow = buildRoomHeaderRow(state);
        wrapper.appendChild(headerRow);

        const gridEl = document.createElement('div');
        gridEl.className = 'mod_confscheduler-day-grid';
        gridEl.setAttribute('role', 'table');
        wrapper.appendChild(gridEl);

        buildDayGridInto(state, gridEl, state.slotsByDay[dayKey] || [], dayKey);

        container.appendChild(wrapper);
    });

    scrollEl.appendChild(container);

    state.root.classList.toggle('mod_confscheduler-mytimetable-active', state.myTimetableActive);
};

/**
 * Dispatches to renderBody() (single-day mode) or renderAllDaysBody() ("All days"
 * mode) depending on state.selectedDay (user feedback, 2026-07-05).
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
 * Re-fetches the grid payload and re-renders everything.
 *
 * @param {Object} state The module state object
 * @return {Promise}
 */
const fetchAndRenderAll = (state) => Repository.getGridData(state.cmid).then((data) => {
    state.rooms = data.rooms;
    state.pxperhour = data.pxperhour;
    state.conferencestart = data.conferencestart;
    state.conferenceend = data.conferenceend;
    state.daystart = data.daystart;
    state.dayend = data.dayend;
    state.daybounds = {};
    (data.daybounds || []).forEach((entry) => {
        state.daybounds[entry.day] = {daystart: entry.daystart, dayend: entry.dayend};
    });
    state.slotsByDay = DayUtils.groupSlotsByDay(data.slots);
    state.dayKeys = DayUtils.selectableDayKeys(state.conferencestart, state.conferenceend, data.slots);
    if (!state.selectedDay || (state.selectedDay !== DayUtils.ALL_DAYS && !state.dayKeys.includes(state.selectedDay))) {
        // Only the very first successful load honours initialSelectedDay (the
        // organiser's "Default day view" setting, or -- taking precedence, per
        // rememberlastday -- this user's own remembered day, both resolved in
        // init() below). A LATER re-fetch landing here means the previously
        // selected day stopped existing (e.g. its last slot was unscheduled) --
        // that should fall back to "closest", not silently jump back to the
        // original default/remembered day out from under the viewer.
        if (
            !state.hasLoadedOnce && state.initialSelectedDay !== null &&
            (state.initialSelectedDay === DayUtils.ALL_DAYS || state.dayKeys.includes(state.initialSelectedDay))
        ) {
            state.selectedDay = state.initialSelectedDay;
        } else {
            state.selectedDay = DayUtils.defaultDayKey(state.dayKeys);
        }
        state.hasLoadedOnce = true;
    }
    if (state.selectedDay !== DayUtils.ALL_DAYS) {
        renderHeaders(state);
    }
    renderDaySelector(state);
    renderGridBody(state);
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
            if (block.dataset.withdrawn === '1') {
                // The presentation this block once represented has been withdrawn since it
                // was scheduled -- show a plain notice instead of the usual submission detail
                // modal (which mod_confprogram's own endpoint would otherwise still happily
                // return, since a withdrawal never touches the accept decision it checks).
                Notification.alert(state.strings.presentationwithdrawn, state.strings.blockwithdrawn);
                return;
            }
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
            renderGridBody(state);
            if (state.rememberlastday) {
                // Fire-and-forget, same as writeMyTimetableState() above: this is a
                // best-effort convenience, not something a failure should ever surface
                // to the viewer or block the (already-applied) UI update on.
                Repository.setLastViewedDay(state.cmid, state.selectedDay).catch(Notification.exception);
            }
            return;
        }

        const bwRadio = event.target.closest('[name=mod_confscheduler_colourmode]');
        if (bwRadio) {
            state.root.classList.toggle('mod_confscheduler-bw', bwRadio.value === 'bw' && bwRadio.checked);
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
 * @param {String|null} initialday The day (or 'all') to select on first load (user request,
 *        2026-07-07), already resolved server-side (rememberlastday's remembered day, if any and
 *        enabled, else defaultdateview mapped to 'all'/null) -- null means "closest day", the
 *        previous, only behaviour.
 * @param {Boolean} rememberlastday Whether to report every day-selector change back to the server
 *        via Repository.setLastViewedDay() (the instance's rememberlastday switch)
 * @return {Promise}
 */
export const init = async(cmid, confschedulerid, confprogramcmid, programurl, canfavourite,
        initialday, rememberlastday) => {
    const root = document.getElementById('mod_confscheduler-display-root');
    if (!root) {
        return;
    }

    const [favourite, filterbytrack, alldays, presentationwithdrawn, blockwithdrawn] = await Promise.all([
        getString('favourite', 'mod_confscheduler'),
        getString('filterbytrack', 'mod_confscheduler'),
        getString('alldays', 'mod_confscheduler'),
        getString('presentationwithdrawn', 'mod_confscheduler'),
        getString('blockwithdrawn', 'mod_confscheduler'),
    ]);

    const state = {
        cmid,
        confschedulerid,
        confprogramcmid,
        programUrl: programurl,
        canfavourite,
        root,
        rooms: [],
        pxperhour: DEFAULT_PX_PER_HOUR,
        conferencestart: null,
        conferenceend: null,
        daystart: null,
        dayend: null,
        daybounds: {},
        slotsByDay: {},
        dayKeys: [],
        selectedDay: null,
        initialSelectedDay: initialday,
        hasLoadedOnce: false,
        rememberlastday,
        dayStart: 0,
        myTimetableActive: readMyTimetableState(cmid),
        strings: {favourite, filterbytrack, alldays, presentationwithdrawn, blockwithdrawn},
    };

    const myTimetableBtn = root.querySelector('.mod_confscheduler-mytimetable-toggle');
    if (myTimetableBtn) {
        myTimetableBtn.setAttribute('aria-pressed', state.myTimetableActive ? 'true' : 'false');
        myTimetableBtn.classList.toggle('active', state.myTimetableActive);
    }

    bindEvents(state);
    await fetchAndRenderAll(state);
};
