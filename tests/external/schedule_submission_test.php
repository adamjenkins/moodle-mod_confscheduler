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
 * Tests for the schedule_submission AJAX external function.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(schedule_submission::class)]
final class schedule_submission_test extends advanced_testcase {
    /**
     * Creates a full fixture and one accepted submission.
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass, 3: int} [$course, $cmid, $confscheduler, $submissionid]
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

        return [$course, (int) $cm->id, $confscheduler, $submissionid];
    }

    /**
     * An editing teacher can schedule an accepted submission into a room they own.
     */
    public function test_schedules_submission(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler, $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        $result = schedule_submission::execute(
            $cmid,
            $submissionid,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00')
        );

        $this->assertGreaterThan(0, $result['slotid']);
    }

    /**
     * A room id belonging to a DIFFERENT confscheduler instance is rejected
     * (enforced inside api::add_slot()'s placement validation): a
     * manageschedule holder in one course cannot use a room id from another
     * course's schedule.
     */
    public function test_rejects_room_from_another_instance(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        [, , $otherconfscheduler] = $this->create_fixture();
        $foreignroomid = api::add_room((int) $otherconfscheduler->id, 'Foreign Room');

        $this->expectException(\invalid_parameter_exception::class);
        schedule_submission::execute(
            $cmid,
            $submissionid,
            [$foreignroomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00')
        );
    }

    /**
     * A submissionid belonging to a different course's confsubmissions instance
     * is rejected by the chain-of-custody check, even though the caller has
     * manageschedule in their own course.
     */
    public function test_rejects_submission_from_another_course(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        [, , , $foreignsubmissionid] = $this->create_fixture();

        $this->expectException(\moodle_exception::class);
        schedule_submission::execute(
            $cmid,
            $foreignsubmissionid,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00')
        );
    }

    /**
     * A plain student (no manageschedule) cannot call this endpoint.
     */
    public function test_requires_manageschedule_capability(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler, $submissionid] = $this->create_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        schedule_submission::execute(
            $cmid,
            $submissionid,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00')
        );
    }
}
