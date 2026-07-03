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

namespace mod_confscheduler;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \mod_confscheduler\api.
 *
 * get_schedule_for_submission() is the one method in this scaffold that must
 * work correctly as soon as any slot data exists (it is the contract
 * \mod_confprogram\local\schedule_info calls into), so it is tested here
 * directly against the database rather than left as an untested stub.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api::class)]
final class api_test extends advanced_testcase {
    /**
     * Creates a confscheduler instance (with the mod_confsubmissions +
     * mod_confprogram instances it depends on) and returns its id.
     *
     * @return int The confscheduler instance id
     */
    protected function create_confscheduler(): int {
        $course = $this->getDataGenerator()->create_course();

        $confsubmissions = $this->getDataGenerator()->create_module('confsubmissions', [
            'course' => $course->id,
        ]);
        $confsubmissionscm = get_coursemodule_from_instance('confsubmissions', $confsubmissions->id);

        $confprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $course->id,
            'confsubmissionscmid' => $confsubmissionscm->id,
        ]);
        $confprogramcm = get_coursemodule_from_instance('confprogram', $confprogram->id);

        $confscheduler = $this->getDataGenerator()->create_module('confscheduler', [
            'course'          => $course->id,
            'confprogramcmid' => $confprogramcm->id,
        ]);

        return (int) $confscheduler->id;
    }

    /**
     * get_schedule_for_submission() returns null when the submission has no
     * confscheduler_slot row at all.
     */
    public function test_get_schedule_for_submission_returns_null_when_unscheduled(): void {
        $this->resetAfterTest();

        $this->create_confscheduler();

        $this->assertNull(api::get_schedule_for_submission(999999));
    }

    /**
     * get_schedule_for_submission() returns the correct
     * array{starttime, endtime, room} shape for a submission scheduled into a
     * single room, built from a room + slot + slotroom row inserted directly
     * via $DB (i.e. without going through api::add_slot()), matching the
     * exact contract \mod_confprogram\local\schedule_info relies on.
     */
    public function test_get_schedule_for_submission_single_room(): void {
        global $DB;

        $this->resetAfterTest();

        $confschedulerid = $this->create_confscheduler();
        $submissionid = 42;

        $roomid = $DB->insert_record('confscheduler_room', (object) [
            'confscheduler' => $confschedulerid,
            'name'          => 'Main Hall',
            'sortorder'     => 0,
            'colour'        => '#3366cc',
        ]);

        $starttime = strtotime('2026-09-01 10:00:00');
        $endtime = strtotime('2026-09-01 10:30:00');
        $slotid = $DB->insert_record('confscheduler_slot', (object) [
            'confscheduler' => $confschedulerid,
            'submissionid'  => $submissionid,
            'label'         => null,
            'starttime'     => $starttime,
            'endtime'       => $endtime,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        $DB->insert_record('confscheduler_slotroom', (object) [
            'slotid' => $slotid,
            'roomid' => $roomid,
        ]);

        $schedule = api::get_schedule_for_submission($submissionid);

        $this->assertIsArray($schedule);
        $this->assertArrayHasKey('starttime', $schedule);
        $this->assertArrayHasKey('endtime', $schedule);
        $this->assertArrayHasKey('room', $schedule);
        $this->assertSame($starttime, $schedule['starttime']);
        $this->assertSame($endtime, $schedule['endtime']);
        $this->assertSame('Main Hall', $schedule['room']);
    }

    /**
     * get_schedule_for_submission() returns a comma-joined room name string,
     * in sortorder, when a slot spans multiple rooms (a column-spanning block
     * such as a plenary or lunch).
     */
    public function test_get_schedule_for_submission_multi_room_spanning_block(): void {
        global $DB;

        $this->resetAfterTest();

        $confschedulerid = $this->create_confscheduler();
        $submissionid = 43;

        $room1 = $DB->insert_record('confscheduler_room', (object) [
            'confscheduler' => $confschedulerid,
            'name'          => 'Main Hall',
            'sortorder'     => 0,
            'colour'        => null,
        ]);
        $room2 = $DB->insert_record('confscheduler_room', (object) [
            'confscheduler' => $confschedulerid,
            'name'          => 'Room B',
            'sortorder'     => 1,
            'colour'        => null,
        ]);

        $starttime = strtotime('2026-09-01 12:00:00');
        $endtime = strtotime('2026-09-01 13:00:00');
        $slotid = $DB->insert_record('confscheduler_slot', (object) [
            'confscheduler' => $confschedulerid,
            'submissionid'  => $submissionid,
            'label'         => null,
            'starttime'     => $starttime,
            'endtime'       => $endtime,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        // Inserted out of sortorder to prove get_schedule_for_submission() orders by
        // r.sortorder, not by insertion/id order.
        $DB->insert_record('confscheduler_slotroom', (object) ['slotid' => $slotid, 'roomid' => $room2]);
        $DB->insert_record('confscheduler_slotroom', (object) ['slotid' => $slotid, 'roomid' => $room1]);

        $schedule = api::get_schedule_for_submission($submissionid);

        $this->assertIsArray($schedule);
        $this->assertSame($starttime, $schedule['starttime']);
        $this->assertSame($endtime, $schedule['endtime']);
        $this->assertSame('Main Hall, Room B', $schedule['room']);
    }

    /**
     * The full \mod_confprogram\local\schedule_info integration contract works
     * end-to-end once mod_confscheduler (this plugin) is installed: it no longer
     * degrades to null, and correctly delegates to
     * \mod_confscheduler\api::get_schedule_for_submission().
     */
    public function test_confprogram_schedule_info_integration(): void {
        global $DB;

        $this->resetAfterTest();

        $confschedulerid = $this->create_confscheduler();
        $submissionid = 44;

        $roomid = $DB->insert_record('confscheduler_room', (object) [
            'confscheduler' => $confschedulerid,
            'name'          => 'Main Hall',
            'sortorder'     => 0,
            'colour'        => null,
        ]);
        $starttime = strtotime('2026-09-01 14:00:00');
        $endtime = strtotime('2026-09-01 14:45:00');
        $slotid = $DB->insert_record('confscheduler_slot', (object) [
            'confscheduler' => $confschedulerid,
            'submissionid'  => $submissionid,
            'label'         => null,
            'starttime'     => $starttime,
            'endtime'       => $endtime,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);
        $DB->insert_record('confscheduler_slotroom', (object) ['slotid' => $slotid, 'roomid' => $roomid]);

        $this->assertNotNull(\core_component::get_component_directory('mod_confscheduler'));
        $this->assertTrue(class_exists('\mod_confscheduler\api'));

        $schedule = \mod_confprogram\local\schedule_info::get_for_submission($submissionid);

        $this->assertIsArray($schedule);
        $this->assertSame($starttime, $schedule['starttime']);
        $this->assertSame('Main Hall', $schedule['room']);
    }
}
