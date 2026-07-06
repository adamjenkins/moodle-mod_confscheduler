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

/**
 * Thin wrappers around this plugin's AJAX external functions, used by
 * amd/src/scheduler_grid.js. Kept in its own module so the grid module itself
 * only has to deal with plain promises returning plain data, not
 * Ajax.call()'s array-of-requests calling convention.
 *
 * @module     mod_confscheduler/repository
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Fetches the full grid payload (rooms, scheduled slots, unscheduled submissions).
 *
 * @param {Number} cmid The confscheduler course-module id
 * @return {Promise}
 */
export const getGridData = (cmid) => Ajax.call([{
    methodname: 'mod_confscheduler_get_grid_data',
    args: {cmid},
}])[0];

/**
 * Schedules an accepted submission into the grid.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} submissionid The confsubmissions_submission id to schedule
 * @param {Number[]} roomids Room id(s) this slot occupies
 * @param {Number} starttime Unix timestamp
 * @param {Number} endtime Unix timestamp
 * @return {Promise}
 */
export const scheduleSubmission = (cmid, submissionid, roomids, starttime, endtime) => Ajax.call([{
    methodname: 'mod_confscheduler_schedule_submission',
    args: {cmid, submissionid, roomids, starttime, endtime},
}])[0];

/**
 * Moves/resizes an existing scheduled slot.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} slotid The confscheduler_slot id to reschedule
 * @param {Number[]} roomids The new room id(s) this slot should occupy
 * @param {Number} starttime Unix timestamp
 * @param {Number} endtime Unix timestamp
 * @return {Promise}
 */
export const rescheduleSlot = (cmid, slotid, roomids, starttime, endtime) => Ajax.call([{
    methodname: 'mod_confscheduler_reschedule_slot',
    args: {cmid, slotid, roomids, starttime, endtime},
}])[0];

/**
 * Unschedules a slot.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} slotid The confscheduler_slot id to unschedule
 * @return {Promise}
 */
export const unscheduleSlot = (cmid, slotid) => Ajax.call([{
    methodname: 'mod_confscheduler_unschedule_slot',
    args: {cmid, slotid},
}])[0];

/**
 * Creates a column-spanning block (e.g. Lunch/Plenary) with no presentation.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {String} label The span-block label
 * @param {Number[]} roomids Room id(s) this block spans
 * @param {Number} starttime Unix timestamp
 * @param {Number} endtime Unix timestamp
 * @param {String|null} colour Hex colour (e.g. #3366cc) to theme this block, or null
 * @return {Promise}
 */
export const addSpanBlock = (cmid, label, roomids, starttime, endtime, colour) => Ajax.call([{
    methodname: 'mod_confscheduler_add_span_block',
    args: {cmid, label, roomids, starttime, endtime, colour},
}])[0];

/**
 * Edits an existing column-spanning block in place (label, colour, time range, room-range).
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} slotid The confscheduler_slot id to update (must be a span block)
 * @param {String} label The span-block label
 * @param {Number[]} roomids Room id(s) this block spans
 * @param {Number} starttime Unix timestamp
 * @param {Number} endtime Unix timestamp
 * @param {String|null} colour Hex colour (e.g. #3366cc) to theme this block, or null
 * @return {Promise}
 */
export const updateSpanBlock = (cmid, slotid, label, roomids, starttime, endtime, colour) => Ajax.call([{
    methodname: 'mod_confscheduler_update_span_block',
    args: {cmid, slotid, label, roomids, starttime, endtime, colour},
}])[0];

/**
 * Adds a room (column).
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {String} name Room name
 * @param {String|null} colour Hex colour, or null
 * @param {Number|null} capacity Maximum attendee capacity, or null for unlimited
 * @return {Promise}
 */
export const addRoom = (cmid, name, colour, capacity = null) => Ajax.call([{
    methodname: 'mod_confscheduler_add_room',
    args: {cmid, name, colour, capacity},
}])[0];

/**
 * Updates a room's name, colour, and/or capacity.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} roomid The confscheduler_room id to update
 * @param {String} name Room name
 * @param {String|null} colour Hex colour, or null
 * @param {Number|null} capacity Maximum attendee capacity, or null for unlimited
 * @return {Promise}
 */
export const updateRoom = (cmid, roomid, name, colour, capacity = null) => Ajax.call([{
    methodname: 'mod_confscheduler_update_room',
    args: {cmid, roomid, name, colour, capacity},
}])[0];

/**
 * Deletes a room.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} roomid The confscheduler_room id to delete
 * @return {Promise}
 */
export const deleteRoom = (cmid, roomid) => Ajax.call([{
    methodname: 'mod_confscheduler_delete_room',
    args: {cmid, roomid},
}])[0];

/**
 * Rewrites the left-to-right column order of the instance's rooms.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number[]} roomidsinorder Room ids in the desired left-to-right order
 * @return {Promise}
 */
export const reorderRooms = (cmid, roomidsinorder) => Ajax.call([{
    methodname: 'mod_confscheduler_reorder_rooms',
    args: {cmid, roomidsinorder},
}])[0];

/**
 * Sets or unsets the current user's favourite of a scheduled presentation.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} slotid The confscheduler_slot id of the presentation block
 * @param {Boolean} favourited The desired favourited state
 * @return {Promise}
 */
export const toggleFavourite = (cmid, slotid, favourited) => Ajax.call([{
    methodname: 'mod_confscheduler_toggle_favourite',
    args: {cmid, slotid, favourited},
}])[0];

/**
 * Runs the autoscheduler over a time window. Each placed submission is given its own
 * mod_confsubmissions submission type's duration (Revision round 1, 2026-07-04) -- there
 * is no longer a single uniform duration passed in here.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} windowstart Unix timestamp; start of the window to schedule into
 * @param {Number} windowend Unix timestamp; end of the window to schedule into
 * @param {Boolean} clearfirst Whether to first clear existing slots that overlap the window
 * @param {Boolean} ignorepreferreddates When false (the default -- user feedback, 2026-07-05), a
 *        submission with recorded date preferences and no available candidate on any of them is
 *        skipped rather than placed on a non-preferred day; true restores the previous
 *        soft-preference fallback behaviour
 * @return {Promise}
 */
export const runAutoscheduler = (cmid, windowstart, windowend, clearfirst, ignorepreferreddates = false) => Ajax.call([{
    methodname: 'mod_confscheduler_run_autoscheduler',
    args: {cmid, windowstart, windowend, clearfirst, ignorepreferreddates},
}])[0];

/**
 * Sets a confscheduler instance's SnapGap minimum gap, in minutes. Backs the quick
 * control at the top of the schedule grid in edit mode (Revision round 1 follow-up,
 * 2026-07-04), which replaced a field that previously lived in the activity's own
 * settings form.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} gapminutes The new SnapGap minimum gap, in minutes
 * @return {Promise}
 */
export const setGapMinutes = (cmid, gapminutes) => Ajax.call([{
    methodname: 'mod_confscheduler_set_gap_minutes',
    args: {cmid, gapminutes},
}])[0];

/**
 * Sets a confscheduler instance's row height, in pixels per hour. Backs the quick
 * control at the top of the schedule grid in edit mode (user feedback, 2026-07-05).
 *
 * @param {Number} cmid The confscheduler course-module id
 * @param {Number} pxperhour The new row height, in pixels per hour
 * @return {Promise}
 */
export const setPxPerHour = (cmid, pxperhour) => Ajax.call([{
    methodname: 'mod_confscheduler_set_pxperhour',
    args: {cmid, pxperhour},
}])[0];

/**
 * Sends the schedule-change notification for every presentation slot with a
 * scheduling change pending since it was last notified (user request,
 * 2026-07-05). Backs the edit-mode "Send notifications" button.
 *
 * @param {Number} cmid The confscheduler course-module id
 * @return {Promise} Resolves to {sent: Number}
 */
export const sendPendingNotifications = (cmid) => Ajax.call([{
    methodname: 'mod_confscheduler_send_pending_notifications',
    args: {cmid},
}])[0];
