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
 * AJAX-only external function that sets, updates, or clears a submission's
 * "session" grouping label (see \mod_confscheduler\api::set_session_tag()
 * for the design rationale) within a confscheduler instance.
 *
 * An empty (or whitespace-only) $label clears the tag; this is the same
 * "set/clear" endpoint shape the task spec asked for, rather than two
 * separate endpoints. The confschedulerid is derived here from the
 * validated cmid, never taken from client input; the submissionid's
 * chain-of-custody (does it genuinely belong to, and was it accepted by, the
 * confprogram/confsubmissions instance chain this confscheduler is linked
 * to) is enforced inside api::set_session_tag() itself, for the same reason
 * add_slot() enforces it: submission ids are global, not scoped per course.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_session_tag extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'         => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'submissionid' => new external_value(PARAM_INT, 'The confsubmissions_submission id to tag'),
            'label'        => new external_value(
                PARAM_TEXT,
                'The session label; an empty string clears the tag',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Sets or clears a submission's session tag.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int $submissionid The confsubmissions_submission id to tag
     * @param string $label The session label; an empty string clears the tag
     * @return array{label: string}
     */
    public static function execute(int $cmid, int $submissionid, string $label = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'         => $cmid,
            'submissionid' => $submissionid,
            'label'        => $label,
        ]);

        [, , $confscheduler] = self::require_manage($params['cmid']);

        $label = trim($params['label']);

        api::set_session_tag((int) $confscheduler->id, $params['submissionid'], $label === '' ? null : $label);

        // Returned as an empty string (not null) when cleared: external_value cannot
        // represent null directly, and the caller only needs to know the label that
        // is now in effect, which "" unambiguously conveys here (stored labels are
        // never empty strings -- see set_session_tag()'s docblock).
        return ['label' => $label];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'label' => new external_value(PARAM_TEXT, 'The stored label, or an empty string if the tag was cleared'),
        ]);
    }
}
