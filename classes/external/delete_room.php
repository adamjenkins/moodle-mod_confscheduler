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
 * AJAX-only external function that deletes a room. Refused (see
 * \mod_confscheduler\api::delete_room()'s docblock) if the room still has any
 * scheduled slot referencing it.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_room extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'roomid' => new external_value(PARAM_INT, 'The confscheduler_room id to delete'),
        ]);
    }

    /**
     * Deletes a room.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int $roomid The confscheduler_room id to delete
     * @return array{success: bool}
     */
    public static function execute(int $cmid, int $roomid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'roomid' => $roomid]);

        [, , $confscheduler] = self::require_manage($params['cmid']);
        self::require_room_in_instance((int) $confscheduler->id, $params['roomid']);

        api::delete_room($params['roomid']);

        return ['success' => true];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the delete succeeded'),
        ]);
    }
}
