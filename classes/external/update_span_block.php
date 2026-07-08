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
use mod_confscheduler\api;

/**
 * AJAX-only external function that edits an existing column-spanning block
 * (label, colour, time range, room-range) in place.
 *
 * Span blocks previously supported only add/delete via add_span_block.php;
 * this is the edit-in-place path added in Revision round 1 (2026-07-03). The
 * given slotid is scoped to this confscheduler instance
 * (require_slot_in_instance()) before being handed to
 * \mod_confscheduler\api::update_span_block(), the same IDOR-prevention
 * pattern every other write endpoint in this plugin uses (see
 * scheduler_context_trait's docblock); api::update_span_block() itself then
 * refuses to operate on anything but a span block (submissionid IS NULL),
 * which is a data-integrity check, not a capability/instance-scoping one, so
 * it belongs in the api layer rather than here.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_span_block extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'slotid' => new external_value(PARAM_INT, 'The confscheduler_slot id to update (must be a span block)'),
            'label'  => new external_value(PARAM_TEXT, 'The span-block label, e.g. "Lunch Break"'),
            'colour' => new external_value(
                PARAM_TEXT,
                'Hex colour (e.g. #3366cc) to theme this block, or null',
                VALUE_DEFAULT,
                null
            ),
            'roomids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Room id'),
                'Room id(s) this block spans'
            ),
            'starttime'   => new external_value(PARAM_INT, 'Unix timestamp'),
            'endtime'     => new external_value(PARAM_INT, 'Unix timestamp'),
            'iscontainer' => new external_value(
                PARAM_BOOL,
                'Whether this block is a container that may hold nested presentations',
                VALUE_DEFAULT,
                false
            ),
            'roomnameoverride' => new external_value(
                PARAM_TEXT,
                'Text to display instead of the joined room name(s), or null',
                VALUE_DEFAULT,
                null
            ),
            'childtextalign' => new external_value(
                PARAM_ALPHA,
                'Horizontal text alignment for nested-presentation tiles when this is a container (left/center/right)',
                VALUE_DEFAULT,
                'left'
            ),
            'childtextvalign' => new external_value(
                PARAM_ALPHA,
                'Vertical text alignment for nested-presentation tiles when this is a container (top/middle/bottom)',
                VALUE_DEFAULT,
                'top'
            ),
        ]);
    }

    /**
     * Updates a span block.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int $slotid The confscheduler_slot id to update
     * @param string $label The span-block label
     * @param string|null $colour Hex colour (e.g. #3366cc), or null
     * @param int[] $roomids Room id(s) this block spans
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @param bool $iscontainer Whether this block is (or should become/remain) a container
     * @param string|null $roomnameoverride Text to display instead of the joined room name(s), or null
     * @param string $childtextalign Horizontal text alignment for nested-presentation tiles (left/center/right)
     * @param string $childtextvalign Vertical text alignment for nested-presentation tiles (top/middle/bottom)
     * @return array{success: bool}
     */
    public static function execute(
        int $cmid,
        int $slotid,
        string $label,
        ?string $colour,
        array $roomids,
        int $starttime,
        int $endtime,
        bool $iscontainer = false,
        ?string $roomnameoverride = null,
        string $childtextalign = 'left',
        string $childtextvalign = 'top'
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'             => $cmid,
            'slotid'           => $slotid,
            'label'            => $label,
            'colour'           => $colour,
            'roomids'          => $roomids,
            'starttime'        => $starttime,
            'endtime'          => $endtime,
            'iscontainer'      => $iscontainer,
            'roomnameoverride' => $roomnameoverride,
            'childtextalign'   => $childtextalign,
            'childtextvalign'  => $childtextvalign,
        ]);

        if (trim($params['label']) === '') {
            throw new \invalid_parameter_exception(get_string('error:labelrequired', 'mod_confscheduler'));
        }

        [, , $confscheduler] = self::require_manage($params['cmid']);
        self::require_slot_in_instance((int) $confscheduler->id, $params['slotid']);

        api::update_span_block(
            $params['slotid'],
            $params['label'],
            $params['colour'],
            $params['roomids'],
            $params['starttime'],
            $params['endtime'],
            $params['iscontainer'],
            $params['roomnameoverride'],
            $params['childtextalign'],
            $params['childtextvalign']
        );

        return ['success' => true];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the update succeeded'),
        ]);
    }
}
