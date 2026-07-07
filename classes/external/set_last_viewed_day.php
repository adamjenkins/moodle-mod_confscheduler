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
 * AJAX-only external function that records the current user's last-viewed day
 * for a confscheduler instance (user request, 2026-07-07), called from
 * amd/src/scheduler_display.js every time the Display-mode day selector
 * changes. Gated by mod/confscheduler:viewschedule (not :manageschedule),
 * same as get_grid_data.php: this is per-viewer state, not an organiser
 * action.
 *
 * A no-op (but still returns success) when the instance's rememberlastday
 * switch is off, rather than an error -- the JS caller does not need to know
 * or check that switch itself; it can simply always report the day it
 * rendered, and this endpoint decides whether that is worth persisting.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_last_viewed_day extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'day'  => new external_value(PARAM_RAW_TRIMMED, 'A day key (Y-m-d) or "all"'),
        ]);
    }

    /**
     * Records the current user's last-viewed day, if the instance has
     * rememberlastday enabled.
     *
     * @param int $cmid The confscheduler course-module id
     * @param string $day A day key ('Y-m-d') or 'all'
     * @return array{success: bool}
     */
    public static function execute(int $cmid, string $day): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'day'  => $day,
        ]);

        [, , $confscheduler] = self::require_view($params['cmid']);

        if ($confscheduler->rememberlastday) {
            api::set_last_viewed_day((int) $confscheduler->id, (int) $USER->id, $params['day']);
        }

        return ['success' => true];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the call completed'),
        ]);
    }
}
