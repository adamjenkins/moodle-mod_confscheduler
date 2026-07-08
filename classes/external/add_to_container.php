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
use core_external\external_single_structure;
use core_external\external_value;
use mod_confscheduler\api;

/**
 * AJAX-only external function that nests an accepted-but-unscheduled
 * presentation inside a container span block (e.g. a poster session or
 * keynote panel) -- the "+" button flow in the schedule grid's edit mode.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_to_container extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'            => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'containerslotid' => new external_value(PARAM_INT, 'The confscheduler_slot id of the container'),
            'submissionid'    => new external_value(PARAM_INT, 'The confsubmissions_submission id to add'),
        ]);
    }

    /**
     * Nests a presentation inside a container.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int $containerslotid The confscheduler_slot id of the container
     * @param int $submissionid The confsubmissions_submission id to add
     * @return array{slotid: int}
     */
    public static function execute(int $cmid, int $containerslotid, int $submissionid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'            => $cmid,
            'containerslotid' => $containerslotid,
            'submissionid'    => $submissionid,
        ]);

        [, , $confscheduler] = self::require_manage($params['cmid']);
        self::require_slot_in_instance((int) $confscheduler->id, $params['containerslotid']);

        $slotid = api::add_presentation_to_container(
            (int) $confscheduler->id,
            $params['containerslotid'],
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
