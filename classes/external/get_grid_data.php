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

namespace mod_confscheduler\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_confscheduler\local\grid_data;

/**
 * AJAX-only external function that returns the full grid payload for a
 * confscheduler instance: rooms in order, scheduled slots (decorated with
 * room(s)/submission title/speakers/track for presentation slots), and the
 * list of accepted-but-unscheduled submissions.
 *
 * Gated by mod/confscheduler:viewschedule (not :manageschedule): this read
 * endpoint is written to also serve a future read-only Display mode
 * (Phase 3.5), so it is deliberately not restricted to editors only. The grid
 * page itself (view.php) decides whether to render edit controls based on
 * :manageschedule.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_grid_data extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The confscheduler course-module id'),
        ]);
    }

    /**
     * Returns the grid payload for a confscheduler instance.
     *
     * @param int $cmid The confscheduler course-module id
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('confscheduler', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/confscheduler:viewschedule', $context);

        $confscheduler = $DB->get_record('confscheduler', ['id' => $cm->instance], '*', MUST_EXIST);

        return grid_data::build($confscheduler, (int) $USER->id);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rooms' => new external_multiple_structure(
                new external_single_structure([
                    'id'        => new external_value(PARAM_INT, 'Room id'),
                    'name'      => new external_value(PARAM_TEXT, 'Room name'),
                    'sortorder' => new external_value(PARAM_INT, 'Column order'),
                    'colour'    => new external_value(PARAM_TEXT, 'Hex colour, or null', VALUE_DEFAULT, null),
                    'capacity'  => new external_value(
                        PARAM_INT,
                        'Maximum attendee capacity, or null for unlimited',
                        VALUE_DEFAULT,
                        null
                    ),
                ])
            ),
            'slots' => new external_multiple_structure(
                new external_single_structure([
                    'id'           => new external_value(PARAM_INT, 'Slot id'),
                    'roomids'      => new external_multiple_structure(new external_value(PARAM_INT, 'Room id')),
                    'starttime'    => new external_value(PARAM_INT, 'Unix timestamp'),
                    'endtime'      => new external_value(PARAM_INT, 'Unix timestamp'),
                    'label'        => new external_value(PARAM_TEXT, 'Span-block label, or null', VALUE_DEFAULT, null),
                    'colour'       => new external_value(PARAM_TEXT, 'Span-block hex colour, or null', VALUE_DEFAULT, null),
                    'submissionid' => new external_value(PARAM_INT, 'Submission id, or null for a span-block', VALUE_DEFAULT, null),
                    'title'        => new external_value(PARAM_TEXT, 'Submission title, or null', VALUE_DEFAULT, null),
                    'speakers'     => new external_value(PARAM_TEXT, 'Comma-joined speaker names, or null', VALUE_DEFAULT, null),
                    'track'        => new external_value(PARAM_TEXT, 'Track name, or null', VALUE_DEFAULT, null),
                    'trackid'      => new external_value(PARAM_INT, 'Track id, or null', VALUE_DEFAULT, null),
                    'trackcolour'  => new external_value(PARAM_TEXT, 'Track hex colour, or null', VALUE_DEFAULT, null),
                    'favourited'   => new external_value(PARAM_BOOL, 'Whether the current user has favourited this presentation'),
                    'nonpreferredday' => new external_value(
                        PARAM_BOOL,
                        'Whether this slot\'s day is not one of the submission\'s recorded preferred days ' .
                            '(always false for a span-block, or when no preference was recorded)'
                    ),
                    'favouritecount' => new external_value(
                        PARAM_INT,
                        'Number of users who have favourited this presentation (always 0 for a span-block)'
                    ),
                    'overbooked' => new external_value(
                        PARAM_BOOL,
                        'Whether favouritecount exceeds this slot\'s (single) room\'s configured capacity ' .
                            '(always false for a span-block, a multi-room slot, or an unlimited-capacity room)'
                    ),
                    'withdrawn' => new external_value(
                        PARAM_BOOL,
                        'Whether the scheduled presentation\'s submission has been withdrawn (always false for a span-block)'
                    ),
                    'iscontainer' => new external_value(
                        PARAM_BOOL,
                        'Whether this is a container span-block (always false for a presentation slot, even one nested ' .
                            'inside a container)'
                    ),
                    'parentslotid' => new external_value(
                        PARAM_INT,
                        'The container slot id this presentation is nested inside, or null if not nested',
                        VALUE_DEFAULT,
                        null
                    ),
                    'roomnameoverride' => new external_value(
                        PARAM_TEXT,
                        'Room name override, resolved from the parent container for a nested presentation, or null',
                        VALUE_DEFAULT,
                        null
                    ),
                ])
            ),
            'unscheduled' => new external_multiple_structure(
                new external_single_structure([
                    'submissionid' => new external_value(PARAM_INT, 'Submission id'),
                    'title'        => new external_value(PARAM_TEXT, 'Submission title'),
                    'speakers'     => new external_value(PARAM_TEXT, 'Comma-joined speaker names'),
                    'track'        => new external_value(PARAM_TEXT, 'Track name, or null', VALUE_DEFAULT, null),
                    'trackid'      => new external_value(PARAM_INT, 'Track id, or null', VALUE_DEFAULT, null),
                    'trackcolour'  => new external_value(PARAM_TEXT, 'Track hex colour, or null', VALUE_DEFAULT, null),
                    'durationminutes' => new external_value(
                        PARAM_INT,
                        'This submission\'s type\'s duration in minutes, or the fallback default if unset'
                    ),
                    'preferreddates' => new external_multiple_structure(
                        new external_value(PARAM_INT, 'A preferred day, unix timestamp of local midnight'),
                        'Preferred conference days; empty means no preference recorded'
                    ),
                ])
            ),
            'gapminutes' => new external_value(PARAM_INT, 'The instance\'s configured SnapGap minimum gap, in minutes'),
            'pxperhour'  => new external_value(PARAM_INT, 'The instance\'s configured row height, in pixels per hour'),
            'conferencestart' => new external_value(
                PARAM_INT,
                'The instance\'s configured conference start, unix timestamp, or null if unset',
                VALUE_DEFAULT,
                null
            ),
            'conferenceend' => new external_value(
                PARAM_INT,
                'The instance\'s configured conference end, unix timestamp, or null if unset',
                VALUE_DEFAULT,
                null
            ),
            'daystart' => new external_value(
                PARAM_INT,
                'The instance\'s configured daily display-window start, minutes since midnight, or null if unset',
                VALUE_DEFAULT,
                null
            ),
            'dayend' => new external_value(
                PARAM_INT,
                'The instance\'s configured daily display-window end, minutes since midnight, or null if unset',
                VALUE_DEFAULT,
                null
            ),
            'daybounds' => new external_multiple_structure(
                new external_single_structure([
                    'day'      => new external_value(PARAM_RAW_TRIMMED, 'The conference day this override applies to (Y-m-d)'),
                    'daystart' => new external_value(PARAM_INT, 'Override start for this day, minutes since midnight'),
                    'dayend'   => new external_value(PARAM_INT, 'Override end for this day, minutes since midnight'),
                ]),
                'Per-day display-window overrides; a day not listed here uses the daystart/dayend default'
            ),
            'pendingnotifications' => new external_value(
                PARAM_INT,
                'How many presentation slots have a scheduling change not yet notified'
            ),
        ]);
    }
}
