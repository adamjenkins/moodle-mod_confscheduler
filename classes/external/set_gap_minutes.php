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
 * AJAX-only external function that sets a confscheduler instance's SnapGap
 * minimum gap (confscheduler.gapminutes), called from the quick control at
 * the top of the schedule grid in edit mode.
 *
 * Revision round 1 follow-up (user feedback, 2026-07-04): "the SnapGap
 * (incorrectly called 'GapSnap') setting should appear at the top of the
 * schedule when edit mode is on, rather than in the module settings." This
 * endpoint, plus the grid toolbar control it backs, replaces the field that
 * previously lived in mod_form.php -- see that file's own docblock for why
 * organiser-facing per-instance configuration that only makes sense once the
 * instance already exists (like room/track/submission-type management
 * elsewhere in this project) lives on its own screen/control rather than in
 * the settings form.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_gap_minutes extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'        => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'gapminutes'  => new external_value(PARAM_INT, 'The new SnapGap minimum gap, in minutes'),
        ]);
    }

    /**
     * Sets the instance's SnapGap minimum gap.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int $gapminutes The new SnapGap minimum gap, in minutes
     * @return array{success: bool}
     */
    public static function execute(int $cmid, int $gapminutes): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'       => $cmid,
            'gapminutes' => $gapminutes,
        ]);

        [, , $confscheduler] = self::require_manage($params['cmid']);

        api::set_gap_minutes((int) $confscheduler->id, $params['gapminutes']);

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
