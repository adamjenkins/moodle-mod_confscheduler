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
 * AJAX-only external function that creates a column-spanning block (e.g.
 * "Lunch" or "Plenary") with no presentation: a labelled slot spanning one or
 * more adjacent room columns, created via a simple form/modal rather than
 * drag-and-drop.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_span_block extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'  => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'label' => new external_value(PARAM_TEXT, 'The span-block label, e.g. "Lunch Break"'),
            'roomids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Room id'),
                'Room id(s) this block spans'
            ),
            'starttime' => new external_value(PARAM_INT, 'Unix timestamp'),
            'endtime'   => new external_value(PARAM_INT, 'Unix timestamp'),
        ]);
    }

    /**
     * Creates a span block.
     *
     * @param int $cmid The confscheduler course-module id
     * @param string $label The span-block label
     * @param int[] $roomids Room id(s) this block spans
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @return array{slotid: int}
     */
    public static function execute(int $cmid, string $label, array $roomids, int $starttime, int $endtime): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'      => $cmid,
            'label'     => $label,
            'roomids'   => $roomids,
            'starttime' => $starttime,
            'endtime'   => $endtime,
        ]);

        if (trim($params['label']) === '') {
            throw new \invalid_parameter_exception(get_string('error:labelrequired', 'mod_confscheduler'));
        }

        [, , $confscheduler] = self::require_manage($params['cmid']);

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            $params['roomids'],
            $params['starttime'],
            $params['endtime'],
            null,
            $params['label']
        );

        return ['slotid' => $slotid];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'slotid' => new external_value(PARAM_INT, 'The newly created confscheduler_slot id'),
        ]);
    }
}
