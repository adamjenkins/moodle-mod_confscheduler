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
 * Tests for the add_room/update_room/delete_room/reorder_rooms AJAX external
 * functions.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(add_room::class)]
#[CoversClass(update_room::class)]
#[CoversClass(delete_room::class)]
#[CoversClass(reorder_rooms::class)]
final class room_crud_test extends advanced_testcase {
    /**
     * Creates a course containing a linked confscheduler instance.
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass} [$course, $cmid, $confscheduler]
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

        return [$course, (int) $cm->id, $confscheduler];
    }

    /**
     * add_room() creates a room; a bad colour is rejected with the same
     * exception class the api layer raises.
     */
    public function test_add_room(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = add_room::execute($cmid, 'Main Hall', '#3366cc');
        $this->assertGreaterThan(0, $result['roomid']);

        $this->expectException(\invalid_parameter_exception::class);
        add_room::execute($cmid, 'Bad Room', 'not-a-colour');
    }

    /**
     * update_room() rejects a room id belonging to a different confscheduler
     * instance, even for a caller with manageschedule in their own course.
     */
    public function test_update_room_rejects_foreign_room(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        [, , $otherconfscheduler] = $this->create_fixture();
        $foreignroomid = api::add_room((int) $otherconfscheduler->id, 'Foreign');

        $this->expectException(\invalid_parameter_exception::class);
        update_room::execute($cmid, $foreignroomid, 'Renamed', null);
    }

    /**
     * delete_room() rejects a room id belonging to a different confscheduler
     * instance.
     */
    public function test_delete_room_rejects_foreign_room(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        [, , $otherconfscheduler] = $this->create_fixture();
        $foreignroomid = api::add_room((int) $otherconfscheduler->id, 'Foreign');

        $this->expectException(\invalid_parameter_exception::class);
        delete_room::execute($cmid, $foreignroomid);

        global $DB;
        $this->assertTrue($DB->record_exists('confscheduler_room', ['id' => $foreignroomid]));
    }

    /**
     * reorder_rooms() reorders the instance's own rooms.
     */
    public function test_reorder_rooms(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $room1 = api::add_room((int) $confscheduler->id, 'A');
        $room2 = api::add_room((int) $confscheduler->id, 'B');

        $result = reorder_rooms::execute($cmid, [$room2, $room1]);
        $this->assertTrue($result['success']);

        global $DB;
        $this->assertSame(0, (int) $DB->get_field('confscheduler_room', 'sortorder', ['id' => $room2]));
        $this->assertSame(1, (int) $DB->get_field('confscheduler_room', 'sortorder', ['id' => $room1]));
    }

    /**
     * add_room() cannot be called by a plain student (no manageschedule).
     */
    public function test_add_room_requires_manageschedule_capability(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        add_room::execute($cmid, 'Another Room', null);
    }

    /**
     * delete_room() cannot be called by a plain student (no manageschedule).
     */
    public function test_delete_room_requires_manageschedule_capability(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        delete_room::execute($cmid, $roomid);
    }
}
