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
 * AJAX-only external function backing the edit-mode "Send notifications"
 * button (user request, 2026-07-05): manually sends the schedule-change
 * notification for every presentation slot with a scheduling change pending
 * since it was last notified, and marks each as notified. A slot with no
 * pending change (nothing scheduled/moved since the last send) is left
 * untouched -- see \mod_confscheduler\api::send_pending_notifications().
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_pending_notifications extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The confscheduler course-module id'),
        ]);
    }

    /**
     * Sends the pending schedule-change notifications.
     *
     * @param int $cmid The confscheduler course-module id
     * @return array{sent: int}
     */
    public static function execute(int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        [, , $confscheduler] = self::require_manage($params['cmid']);

        return ['sent' => api::send_pending_notifications((int) $confscheduler->id)];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sent' => new external_value(PARAM_INT, 'How many presentation slots were notified'),
        ]);
    }
}
