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

/**
 * External functions and service definitions for mod_confscheduler.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_confscheduler_get_grid_data' => [
        'classname'    => 'mod_confscheduler\external\get_grid_data',
        'description'  => 'Returns the grid payload for a confscheduler instance: rooms, scheduled slots, '
            . 'and accepted-but-unscheduled submissions.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:viewschedule',
    ],
    'mod_confscheduler_schedule_submission' => [
        'classname'    => 'mod_confscheduler\external\schedule_submission',
        'description'  => 'Schedules an accepted submission into the grid.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_reschedule_slot' => [
        'classname'    => 'mod_confscheduler\external\reschedule_slot',
        'description'  => 'Moves/resizes an existing scheduled slot.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_unschedule_slot' => [
        'classname'    => 'mod_confscheduler\external\unschedule_slot',
        'description'  => 'Unschedules a slot, returning any presentation to the unscheduled panel.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_add_span_block' => [
        'classname'    => 'mod_confscheduler\external\add_span_block',
        'description'  => 'Creates a column-spanning block (e.g. Lunch/Plenary) with no presentation.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_add_to_container' => [
        'classname'    => 'mod_confscheduler\external\add_to_container',
        'description'  => 'Nests an accepted-but-unscheduled presentation inside a container span block.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_update_span_block' => [
        'classname'    => 'mod_confscheduler\external\update_span_block',
        'description'  => 'Edits an existing column-spanning block (label, colour, time range, room-range) in place.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_add_room' => [
        'classname'    => 'mod_confscheduler\external\add_room',
        'description'  => 'Adds a room (column) to a confscheduler instance.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_update_room' => [
        'classname'    => 'mod_confscheduler\external\update_room',
        'description'  => 'Renames and/or re-colours a room.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_delete_room' => [
        'classname'    => 'mod_confscheduler\external\delete_room',
        'description'  => 'Deletes a room, refused if it still has any scheduled slot.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_reorder_rooms' => [
        'classname'    => 'mod_confscheduler\external\reorder_rooms',
        'description'  => 'Rewrites the left-to-right column order of a confscheduler instance\'s rooms.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_toggle_favourite' => [
        'classname'    => 'mod_confscheduler\external\toggle_favourite',
        'description'  => 'Sets or unsets the current user\'s favourite of a scheduled presentation.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:favourite',
    ],
    'mod_confscheduler_run_autoscheduler' => [
        'classname'    => 'mod_confscheduler\external\run_autoscheduler',
        'description'  => 'Runs the autoscheduler over a time window, placing as many accepted-but-unscheduled '
            . 'submissions as it can.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_set_gap_minutes' => [
        'classname'    => 'mod_confscheduler\external\set_gap_minutes',
        'description'  => 'Sets a confscheduler instance\'s SnapGap minimum gap, in minutes.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_set_pxperhour' => [
        'classname'    => 'mod_confscheduler\external\set_pxperhour',
        'description'  => 'Sets a confscheduler instance\'s row height, in pixels per hour.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_set_day_bounds' => [
        'classname'    => 'mod_confscheduler\external\set_day_bounds',
        'description'  => 'Sets a confscheduler instance\'s daily display-window bounds, in minutes since midnight.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
    'mod_confscheduler_set_last_viewed_day' => [
        'classname'    => 'mod_confscheduler\external\set_last_viewed_day',
        'description'  => 'Records the current user\'s last-viewed Display-mode day, if the instance has '
            . 'rememberlastday enabled.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:viewschedule',
    ],
    'mod_confscheduler_send_pending_notifications' => [
        'classname'    => 'mod_confscheduler\external\send_pending_notifications',
        'description'  => 'Sends the schedule-change notification for every presentation slot with a '
            . 'scheduling change pending since it was last notified.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/confscheduler:manageschedule',
    ],
];
