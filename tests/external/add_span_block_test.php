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
 * Tests for the add_span_block AJAX external function.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(add_span_block::class)]
final class add_span_block_test extends advanced_testcase {
    /**
     * Creates a course containing a linked confscheduler instance with two rooms.
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass, 3: int, 4: int} [$course, $cmid, $confscheduler, $room1, $room2]
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

        $room1 = api::add_room((int) $confscheduler->id, 'Main Hall');
        $room2 = api::add_room((int) $confscheduler->id, 'Room B');

        return [$course, (int) $cm->id, $confscheduler, $room1, $room2];
    }

    /**
     * An editing teacher can create a column-spanning block with no presentation.
     */
    public function test_creates_span_block(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1, $room2] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = \core_external\external_api::clean_returnvalue(
            add_span_block::execute_returns(),
            add_span_block::execute(
                $cmid,
                'Lunch Break',
                [$room1, $room2],
                strtotime('2026-09-01 12:00:00'),
                strtotime('2026-09-01 13:00:00')
            )
        );

        $this->assertGreaterThan(0, $result['slotid']);

        global $DB;
        $slot = $DB->get_record('confscheduler_slot', ['id' => $result['slotid']]);
        $this->assertNull($slot->submissionid);
        $this->assertSame('Lunch Break', $slot->label);
        $this->assertSame(2, $DB->count_records('confscheduler_slotroom', ['slotid' => $result['slotid']]));
    }

    /**
     * A span block can be created with a colour theme (Revision round 1, 2026-07-03).
     */
    public function test_creates_span_block_with_colour(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1, $room2] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = \core_external\external_api::clean_returnvalue(
            add_span_block::execute_returns(),
            add_span_block::execute(
                $cmid,
                'Plenary',
                [$room1, $room2],
                strtotime('2026-09-01 09:00:00'),
                strtotime('2026-09-01 10:00:00'),
                '#3366cc'
            )
        );

        global $DB;
        $this->assertSame('#3366cc', $DB->get_field('confscheduler_slot', 'colour', ['id' => $result['slotid']]));
    }

    /**
     * An invalid colour is rejected.
     */
    public function test_rejects_invalid_colour(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        add_span_block::execute(
            $cmid,
            'Lunch',
            [$room1],
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 13:00:00'),
            'not-a-colour'
        );
    }

    /**
     * A blank/whitespace-only label is rejected.
     */
    public function test_rejects_blank_label(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        add_span_block::execute($cmid, '   ', [$room1], strtotime('2026-09-01 12:00:00'), strtotime('2026-09-01 13:00:00'));
    }

    /**
     * A span block can be created as a container, with a room-name override
     * (user request, 2026-07-08 -- poster/keynote container blocks).
     */
    public function test_creates_container_span_block(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1, $room2] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = \core_external\external_api::clean_returnvalue(
            add_span_block::execute_returns(),
            add_span_block::execute(
                $cmid,
                'Poster Session',
                [$room1, $room2],
                strtotime('2026-09-01 09:00:00'),
                strtotime('2026-09-01 11:00:00'),
                null,
                true,
                'Exhibit Hall'
            )
        );

        global $DB;
        $slot = $DB->get_record('confscheduler_slot', ['id' => $result['slotid']]);
        $this->assertSame(1, (int) $slot->iscontainer);
        $this->assertSame('Exhibit Hall', $slot->roomnameoverride);
    }

    /**
     * A plain student (no manageschedule) cannot call this endpoint.
     */
    public function test_requires_manageschedule_capability(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        add_span_block::execute($cmid, 'Lunch', [$room1], strtotime('2026-09-01 12:00:00'), strtotime('2026-09-01 13:00:00'));
    }
}
