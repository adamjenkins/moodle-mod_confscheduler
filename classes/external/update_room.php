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
 * AJAX-only external function that renames and/or re-colours a room
 * (inline-editable column header).
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_room extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'roomid'   => new external_value(PARAM_INT, 'The confscheduler_room id to update'),
            'name'     => new external_value(PARAM_TEXT, 'Room name'),
            'colour'   => new external_value(PARAM_TEXT, 'Hex colour (e.g. #3366cc), or null', VALUE_DEFAULT, null),
            'capacity' => new external_value(PARAM_INT, 'Maximum attendee capacity, or null for unlimited', VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Updates a room.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int $roomid The confscheduler_room id to update
     * @param string $name Room name
     * @param string|null $colour Hex colour, or null
     * @param int|null $capacity Maximum attendee capacity, or null for unlimited
     * @return array{success: bool}
     */
    public static function execute(int $cmid, int $roomid, string $name, ?string $colour = null, ?int $capacity = null): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'     => $cmid,
            'roomid'   => $roomid,
            'name'     => $name,
            'colour'   => $colour,
            'capacity' => $capacity,
        ]);

        if (trim($params['name']) === '') {
            throw new \invalid_parameter_exception(get_string('error:roomnamerequired', 'mod_confscheduler'));
        }

        [, , $confscheduler] = self::require_manage($params['cmid']);
        self::require_room_in_instance((int) $confscheduler->id, $params['roomid']);

        api::update_room($params['roomid'], $params['name'], $params['colour'], $params['capacity']);

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
