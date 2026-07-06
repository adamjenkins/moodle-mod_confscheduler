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
 * AJAX-only external function that sets a confscheduler instance's daily
 * display-window bounds (minutes since midnight), called from the quick
 * control at the top of the schedule grid in edit mode (user feedback,
 * 2026-07-06). Follows the exact same pattern as set_gap_minutes.php/
 * set_pxperhour.php.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_day_bounds extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'daystart' => new external_value(
                PARAM_INT,
                'Display-window start, minutes since midnight',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'dayend'   => new external_value(
                PARAM_INT,
                'Display-window end, minutes since midnight',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
        ]);
    }

    /**
     * Sets the instance's display-window bounds.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int|null $daystart Display-window start, minutes since midnight, or null for "automatic"
     * @param int|null $dayend Display-window end, minutes since midnight, or null for "automatic"
     * @return array{success: bool}
     */
    public static function execute(int $cmid, ?int $daystart, ?int $dayend): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'     => $cmid,
            'daystart' => $daystart,
            'dayend'   => $dayend,
        ]);

        [, , $confscheduler] = self::require_manage($params['cmid']);

        api::set_day_bounds((int) $confscheduler->id, $params['daystart'], $params['dayend']);

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
