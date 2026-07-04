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
 * AJAX-only external function that runs the autoscheduler over a time
 * window, placing as many accepted-but-unscheduled submissions as it can.
 *
 * The confschedulerid passed to \mod_confscheduler\api::run_autoscheduler()
 * is derived here from the validated cmid, never taken from client input.
 * The window is client-supplied; run_autoscheduler() itself rejects (rather
 * than silently swapping or clamping) an invalid $windowend <= $windowstart.
 * No $seed is ever passed through from this endpoint, so every real
 * (non-test) invocation of the autoscheduler is unseeded/random, per the
 * task spec's explicit "re-runs vary" requirement.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_autoscheduler extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'        => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'windowstart' => new external_value(PARAM_INT, 'Unix timestamp; start of the window to schedule into'),
            'windowend'   => new external_value(PARAM_INT, 'Unix timestamp; end of the window to schedule into'),
            'clearfirst' => new external_value(PARAM_BOOL, 'Whether to first clear existing slots that overlap the window'),
        ]);
    }

    /**
     * Runs the autoscheduler.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int $windowstart Unix timestamp
     * @param int $windowend Unix timestamp
     * @param bool $clearfirst Whether to first clear existing slots that overlap the window
     * @return array{scheduled: int, skipped: int, skippedreasons: array}
     */
    public static function execute(
        int $cmid,
        int $windowstart,
        int $windowend,
        bool $clearfirst
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'        => $cmid,
            'windowstart' => $windowstart,
            'windowend'   => $windowend,
            'clearfirst'  => $clearfirst,
        ]);

        [, , $confscheduler] = self::require_manage($params['cmid']);

        return api::run_autoscheduler(
            (int) $confscheduler->id,
            $params['windowstart'],
            $params['windowend'],
            $params['clearfirst']
        );
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'scheduled'      => new external_value(PARAM_INT, 'How many submissions were placed'),
            'skipped'        => new external_value(PARAM_INT, 'How many submissions could not be placed'),
            'skippedreasons' => new external_multiple_structure(
                new external_single_structure([
                    'submissionid' => new external_value(PARAM_INT, 'The confsubmissions_submission id'),
                    'title'        => new external_value(PARAM_RAW, 'The submission title (already format_string()-ed)'),
                    'reason'       => new external_value(PARAM_RAW, 'A human-readable reason it could not be placed'),
                ]),
                'Why each skipped submission could not be placed'
            ),
        ]);
    }
}
