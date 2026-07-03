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

/**
 * Shared context/capability/instance-scoping helpers for this plugin's AJAX
 * external functions.
 *
 * Every write endpoint that accepts a room id or slot id MUST scope it to the
 * confscheduler instance derived from the given cmid before reading or
 * mutating it (require_room_in_instance()/require_slot_in_instance()) --
 * without this, a user with mod/confscheduler:manageschedule in ONE course
 * could pass a room/slot id belonging to a DIFFERENT confscheduler instance
 * (ids are not otherwise scoped) and mutate another course's schedule. This
 * mirrors the IDOR bug pattern found and fixed repeatedly in the sibling
 * plugins mod_confsubmissions and mod_confprogram earlier in this project.
 *
 * Intended to be `use`d into a class extending \core_external\external_api.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait scheduler_context_trait {
    /**
     * Validates the module context for a confscheduler cmid and requires
     * mod/confscheduler:viewschedule.
     *
     * @param int $cmid The confscheduler course-module id
     * @return array{0: \stdClass, 1: \context_module, 2: \stdClass} [$cm, $context, $confscheduler]
     */
    protected static function require_view(int $cmid): array {
        return self::require_capability_chain($cmid, 'mod/confscheduler:viewschedule');
    }

    /**
     * Validates the module context for a confscheduler cmid and requires
     * mod/confscheduler:manageschedule.
     *
     * @param int $cmid The confscheduler course-module id
     * @return array{0: \stdClass, 1: \context_module, 2: \stdClass} [$cm, $context, $confscheduler]
     */
    protected static function require_manage(int $cmid): array {
        return self::require_capability_chain($cmid, 'mod/confscheduler:manageschedule');
    }

    /**
     * Validates the module context for a confscheduler cmid and requires
     * mod/confscheduler:favourite.
     *
     * @param int $cmid The confscheduler course-module id
     * @return array{0: \stdClass, 1: \context_module, 2: \stdClass} [$cm, $context, $confscheduler]
     */
    protected static function require_favourite(int $cmid): array {
        return self::require_capability_chain($cmid, 'mod/confscheduler:favourite');
    }

    /**
     * Common cm/context/capability/instance lookup shared by the require_*() helpers above.
     *
     * @param int $cmid The confscheduler course-module id
     * @param string $capability The capability to require
     * @return array{0: \stdClass, 1: \context_module, 2: \stdClass} [$cm, $context, $confscheduler]
     */
    private static function require_capability_chain(int $cmid, string $capability): array {
        global $DB;

        $cm = get_coursemodule_from_id('confscheduler', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability($capability, $context);

        $confscheduler = $DB->get_record('confscheduler', ['id' => $cm->instance], '*', MUST_EXIST);

        return [$cm, $context, $confscheduler];
    }

    /**
     * Scopes a room id to a confscheduler instance. Throws (with the same
     * message regardless of whether the room simply doesn't exist, or exists
     * but belongs to a different instance) if it does not belong.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $roomid The confscheduler_room id
     * @return \stdClass The room record
     * @throws \invalid_parameter_exception if the room does not belong to this instance
     */
    protected static function require_room_in_instance(int $confschedulerid, int $roomid): \stdClass {
        global $DB;

        $room = $DB->get_record('confscheduler_room', ['id' => $roomid, 'confscheduler' => $confschedulerid]);
        if (!$room) {
            throw new \invalid_parameter_exception(get_string('error:invalidroom', 'mod_confscheduler'));
        }

        return $room;
    }

    /**
     * Scopes a slot id to a confscheduler instance. Throws (with the same
     * message regardless of whether the slot simply doesn't exist, or exists
     * but belongs to a different instance) if it does not belong.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $slotid The confscheduler_slot id
     * @return \stdClass The slot record
     * @throws \invalid_parameter_exception if the slot does not belong to this instance
     */
    protected static function require_slot_in_instance(int $confschedulerid, int $slotid): \stdClass {
        global $DB;

        $slot = $DB->get_record('confscheduler_slot', ['id' => $slotid, 'confscheduler' => $confschedulerid]);
        if (!$slot) {
            throw new \invalid_parameter_exception(get_string('error:invalidslot', 'mod_confscheduler'));
        }

        return $slot;
    }
}
