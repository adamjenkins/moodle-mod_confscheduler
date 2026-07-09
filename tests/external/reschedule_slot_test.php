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
 * Tests for the reschedule_slot and unschedule_slot AJAX external functions.
 *
 * Both are tested together here since they share the same instance-scoping
 * (IDOR) concern: a slot id belonging to a DIFFERENT confscheduler instance
 * must be rejected, even by a user who genuinely holds manageschedule in
 * their OWN course. \mod_confscheduler\api::update_slot()/delete_slot() only
 * know how to derive a slot's own instance from the slot row itself; they do
 * not check it matches the instance the caller claims to be acting on, so
 * that check must happen (and is tested here) in the external function layer.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(reschedule_slot::class)]
#[CoversClass(unschedule_slot::class)]
final class reschedule_slot_test extends advanced_testcase {
    /**
     * Creates a full fixture, one room, and one scheduled slot.
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass, 3: int, 4: int} [$course, $cmid, $confscheduler, $roomid, $slotid]
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

        return [$course, (int) $cm->id, $confscheduler, $roomid, $slotid];
    }

    /**
     * An editing teacher can reschedule a slot belonging to their own instance.
     */
    public function test_reschedules_own_slot(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $roomid, $slotid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = \core_external\external_api::clean_returnvalue(
            reschedule_slot::execute_returns(),
            reschedule_slot::execute(
                $cmid,
                $slotid,
                [$roomid],
                strtotime('2026-09-01 11:00:00'),
                strtotime('2026-09-01 11:30:00')
            )
        );

        $this->assertTrue($result['success']);

        global $DB;
        $slot = $DB->get_record('confscheduler_slot', ['id' => $slotid]);
        $this->assertEquals(strtotime('2026-09-01 11:00:00'), (int) $slot->starttime);
    }

    /**
     * A slot id belonging to a different confscheduler instance is rejected,
     * even though the caller has manageschedule in their own course, and the
     * foreign slot is left untouched.
     */
    public function test_rejects_slot_from_another_instance(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        [, , , , $foreignslotid] = $this->create_fixture();

        global $DB;
        $before = $DB->get_record('confscheduler_slot', ['id' => $foreignslotid]);

        $this->expectException(\invalid_parameter_exception::class);
        try {
            reschedule_slot::execute(
                $cmid,
                $foreignslotid,
                [$before->id],
                strtotime('2026-09-01 12:00:00'),
                strtotime('2026-09-01 12:30:00')
            );
        } finally {
            $after = $DB->get_record('confscheduler_slot', ['id' => $foreignslotid]);
            $this->assertEquals($before->starttime, $after->starttime);
        }
    }

    /**
     * An editing teacher can unschedule a slot belonging to their own instance,
     * returning its submission to the unscheduled panel.
     */
    public function test_unschedules_own_slot(): void {
        $this->resetAfterTest();

        [$course, $cmid, , , $slotid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = \core_external\external_api::clean_returnvalue(
            unschedule_slot::execute_returns(),
            unschedule_slot::execute($cmid, $slotid)
        );

        $this->assertTrue($result['success']);
        global $DB;
        $this->assertFalse($DB->record_exists('confscheduler_slot', ['id' => $slotid]));
    }

    /**
     * A slot id belonging to a different confscheduler instance cannot be
     * unscheduled via another instance's cmid.
     */
    public function test_unschedule_rejects_slot_from_another_instance(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        [, , , , $foreignslotid] = $this->create_fixture();

        global $DB;
        $this->expectException(\invalid_parameter_exception::class);
        try {
            unschedule_slot::execute($cmid, $foreignslotid);
        } finally {
            $this->assertTrue($DB->record_exists('confscheduler_slot', ['id' => $foreignslotid]));
        }
    }
}
