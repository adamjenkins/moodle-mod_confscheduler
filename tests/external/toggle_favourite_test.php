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

declare(strict_types=1);

namespace mod_confscheduler\external;

use advanced_testcase;
use mod_confscheduler\api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the toggle_favourite AJAX external function.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(toggle_favourite::class)]
final class toggle_favourite_test extends advanced_testcase {
    /**
     * Creates a full fixture, one room, and one scheduled presentation slot.
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass, 3: int, 4: int} [$course, $cmid, $confprogram, $slotid, $submissionid]
     */
    protected function create_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $confsubmissions = $this->getDataGenerator()->create_module('confsubmissions', ['course' => $course->id]);
        $confsubmissionscm = get_coursemodule_from_instance('confsubmissions', $confsubmissions->id);

        $confprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $course->id,
            'confsubmissionscmid' => $confsubmissionscm->id,
        ]);
        $confprogramcm = get_coursemodule_from_instance('confprogram', $confprogram->id);

        $confschedulerrecord = $this->getDataGenerator()->create_module('confscheduler', [
            'course'          => $course->id,
            'confprogramcmid' => $confprogramcm->id,
        ]);
        $cm = get_coursemodule_from_instance('confscheduler', $confschedulerrecord->id);
        $confscheduler = $DB->get_record('confscheduler', ['id' => $confschedulerrecord->id], '*', MUST_EXIST);

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        $submitter = $this->getDataGenerator()->create_user();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $submitter->id,
            'title'           => 'A Test Talk',
            'abstract'        => 'An abstract.',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        return [$course, (int) $cm->id, $confprogram, $slotid, $submissionid];
    }

    /**
     * A student can favourite, then unfavourite, a scheduled presentation slot.
     */
    public function test_favourite_and_unfavourite_round_trip(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $slotid, $submissionid] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $result = \core_external\external_api::clean_returnvalue(
            toggle_favourite::execute_returns(),
            toggle_favourite::execute($cmid, $slotid, true)
        );
        $this->assertTrue($result['favourited']);
        $this->assertTrue(\mod_confprogram\api::is_favourited((int) $student->id, $submissionid));

        $result = \core_external\external_api::clean_returnvalue(
            toggle_favourite::execute_returns(),
            toggle_favourite::execute($cmid, $slotid, false)
        );
        $this->assertFalse($result['favourited']);
        $this->assertFalse(\mod_confprogram\api::is_favourited((int) $student->id, $submissionid));
    }

    /**
     * A slot id belonging to a DIFFERENT confscheduler instance is rejected,
     * even though the caller genuinely holds the favourite capability in their
     * own course, and nothing is favourited as a result.
     */
    public function test_rejects_slot_from_another_instance(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        [, , , $foreignslotid, $foreignsubmissionid] = $this->create_fixture();

        $this->expectException(\invalid_parameter_exception::class);
        try {
            toggle_favourite::execute($cmid, $foreignslotid, true);
        } finally {
            $this->assertFalse(\mod_confprogram\api::is_favourited((int) $student->id, $foreignsubmissionid));
        }
    }

    /**
     * A column-spanning block (no submission) cannot be favourited.
     */
    public function test_rejects_span_block(): void {
        $this->resetAfterTest();

        [$course, $cmid, , , ] = $this->create_fixture();
        global $DB;
        $confscheduler = $DB->get_record('confscheduler', ['course' => $course->id], '*', MUST_EXIST);
        $roomid = api::add_room((int) $confscheduler->id, 'Room B');
        $spanslotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 13:00:00'),
            null,
            'Lunch'
        );

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\invalid_parameter_exception::class);
        toggle_favourite::execute($cmid, $spanslotid, true);
    }

    /**
     * A user without mod/confscheduler:favourite (a bare enrolment with a
     * capability-less role) cannot call this endpoint.
     */
    public function test_requires_favourite_capability(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $slotid] = $this->create_fixture();
        $bareroleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $bareroleid);
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        toggle_favourite::execute($cmid, $slotid, true);
    }
}
