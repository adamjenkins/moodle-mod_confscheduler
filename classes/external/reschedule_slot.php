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
 * AJAX-only external function that reschedules (moves/resizes) an existing
 * slot within the grid.
 *
 * The given slotid is scoped to this confscheduler instance
 * (require_slot_in_instance()) before being handed to
 * \mod_confscheduler\api::update_slot(): api::update_slot() itself only knows
 * how to derive a slot's OWN confscheduler instance from the slot row, it does
 * not check that the slot belongs to the instance the caller claims to be
 * acting on, so without this check a manageschedule holder in one course could
 * pass a slot id belonging to a different confscheduler instance and mutate
 * that other course's schedule.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reschedule_slot extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'slotid' => new external_value(PARAM_INT, 'The confscheduler_slot id to reschedule'),
            'roomids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Room id'),
                'The new room id(s) this slot should occupy'
            ),
            'starttime' => new external_value(PARAM_INT, 'Unix timestamp'),
            'endtime'   => new external_value(PARAM_INT, 'Unix timestamp'),
        ]);
    }

    /**
     * Reschedules a slot.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int $slotid The confscheduler_slot id to reschedule
     * @param int[] $roomids The new room id(s) this slot should occupy
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @return array{success: bool}
     */
    public static function execute(int $cmid, int $slotid, array $roomids, int $starttime, int $endtime): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'      => $cmid,
            'slotid'    => $slotid,
            'roomids'   => $roomids,
            'starttime' => $starttime,
            'endtime'   => $endtime,
        ]);

        [, , $confscheduler] = self::require_manage($params['cmid']);
        self::require_slot_in_instance((int) $confscheduler->id, $params['slotid']);

        api::update_slot($params['slotid'], $params['roomids'], $params['starttime'], $params['endtime']);

        return ['success' => true];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the reschedule succeeded'),
        ]);
    }
}
