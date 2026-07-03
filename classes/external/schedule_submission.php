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
 * AJAX-only external function that schedules an accepted submission into the
 * grid (drag from the "unscheduled" panel into a cell).
 *
 * Room ownership and the submission chain-of-custody (does this submissionid
 * really belong to, and was it accepted by, the confprogram/confsubmissions
 * instance chain this confscheduler is linked to) are both enforced inside
 * \mod_confscheduler\api::add_slot() itself, since the confschedulerid passed
 * to it is derived here from the validated cmid, not taken from client input.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule_submission extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'         => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'submissionid' => new external_value(PARAM_INT, 'The confsubmissions_submission id to schedule'),
            'roomids'      => new external_multiple_structure(
                new external_value(PARAM_INT, 'Room id'),
                'Room id(s) this slot occupies'
            ),
            'starttime' => new external_value(PARAM_INT, 'Unix timestamp'),
            'endtime'   => new external_value(PARAM_INT, 'Unix timestamp'),
        ]);
    }

    /**
     * Schedules a submission into the grid.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int $submissionid The confsubmissions_submission id to schedule
     * @param int[] $roomids Room id(s) this slot occupies
     * @param int $starttime Unix timestamp
     * @param int $endtime Unix timestamp
     * @return array{slotid: int}
     */
    public static function execute(int $cmid, int $submissionid, array $roomids, int $starttime, int $endtime): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'         => $cmid,
            'submissionid' => $submissionid,
            'roomids'      => $roomids,
            'starttime'    => $starttime,
            'endtime'      => $endtime,
        ]);

        [, , $confscheduler] = self::require_manage($params['cmid']);

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            $params['roomids'],
            $params['starttime'],
            $params['endtime'],
            $params['submissionid']
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
