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
 * AJAX-only external function that rewrites the left-to-right column order of
 * a confscheduler instance's rooms, called on drop by the column-header
 * core/sortable_list handler.
 *
 * Instance-scoping of every given room id is enforced inside
 * \mod_confscheduler\api::reorder_rooms() itself (the whole call is rejected,
 * nothing written, if any id does not belong to this instance), since the
 * confschedulerid passed to it is derived here from the validated cmid.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reorder_rooms extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'roomidsinorder' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Room id'),
                'Room ids in the desired left-to-right order'
            ),
        ]);
    }

    /**
     * Reorders rooms.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int[] $roomidsinorder Room ids in the desired left-to-right order
     * @return array{success: bool}
     */
    public static function execute(int $cmid, array $roomidsinorder): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'           => $cmid,
            'roomidsinorder' => $roomidsinorder,
        ]);

        [, , $confscheduler] = self::require_manage($params['cmid']);

        api::reorder_rooms((int) $confscheduler->id, $params['roomidsinorder']);

        return ['success' => true];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the reorder succeeded'),
        ]);
    }
}
