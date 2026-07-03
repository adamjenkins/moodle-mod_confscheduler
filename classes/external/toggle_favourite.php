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
 * AJAX-only external function backing the favourite-star toggle on scheduled
 * blocks in the grid.
 *
 * The favourite data itself is owned and stored entirely by mod_confprogram
 * (this plugin calls its api::add_favourite()/remove_favourite() directly,
 * per this project's direct-API-coupling architecture -- see
 * \mod_confprogram\api::add_favourite()'s docblock). Takes a slotid (not a
 * bare submissionid) so the target is scoped to THIS confscheduler instance
 * first (require_slot_in_instance()), and only then re-validates that the
 * slot's submission genuinely belongs to (and was accepted by) the
 * confprogram/confsubmissions chain this confscheduler is linked to
 * (api::assert_submission_belongs_to_instance()) before calling into
 * mod_confprogram. Requires mod/confscheduler:favourite in addition to both
 * of those scoping checks.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class toggle_favourite extends external_api {
    use scheduler_context_trait;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'       => new external_value(PARAM_INT, 'The confscheduler course-module id'),
            'slotid'     => new external_value(PARAM_INT, 'The confscheduler_slot id of the presentation block'),
            'favourited' => new external_value(PARAM_BOOL, 'The desired favourited state'),
        ]);
    }

    /**
     * Sets (or unsets) the current user's favourite of a scheduled
     * presentation.
     *
     * @param int $cmid The confscheduler course-module id
     * @param int $slotid The confscheduler_slot id of the presentation block
     * @param bool $favourited The desired favourited state
     * @return array{favourited: bool}
     */
    public static function execute(int $cmid, int $slotid, bool $favourited): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'       => $cmid,
            'slotid'     => $slotid,
            'favourited' => $favourited,
        ]);

        [, , $confscheduler] = self::require_favourite($params['cmid']);
        $slot = self::require_slot_in_instance((int) $confscheduler->id, $params['slotid']);

        if ($slot->submissionid === null) {
            // A column-spanning block (Lunch/Plenary) has no presentation to favourite.
            throw new \invalid_parameter_exception(get_string('error:invalidslot', 'mod_confscheduler'));
        }

        $submissionid = (int) $slot->submissionid;
        api::assert_submission_belongs_to_instance((int) $confscheduler->id, $submissionid);

        $confprogramcm = get_coursemodule_from_id(
            'confprogram',
            $confscheduler->confprogramcmid,
            0,
            false,
            MUST_EXIST
        );

        if ($params['favourited']) {
            \mod_confprogram\api::add_favourite((int) $confprogramcm->instance, $submissionid, (int) $USER->id);
        } else {
            \mod_confprogram\api::remove_favourite((int) $confprogramcm->instance, $submissionid, (int) $USER->id);
        }

        return ['favourited' => \mod_confprogram\api::is_favourited((int) $USER->id, $submissionid)];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'favourited' => new external_value(PARAM_BOOL, 'The favourited state for the current user after this call'),
        ]);
    }
}
