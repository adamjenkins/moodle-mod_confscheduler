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
 * Tests for the update_span_block AJAX external function.
 *
 * Mirrors add_span_block_test.php's fixture; the IDOR-scoping tests here are the
 * counterpart of reschedule_slot_test.php's for a slot-id-accepting write endpoint.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(update_span_block::class)]
final class update_span_block_test extends advanced_testcase {
    /**
     * Creates a course containing a linked confscheduler instance with two rooms and
     * one existing span block.
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass, 3: int, 4: int, 5: int}
     *     [$course, $cmid, $confscheduler, $room1, $room2, $slotid]
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

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            [$room1],
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 13:00:00'),
            null,
            'Lunch Break',
            '#3366cc'
        );

        return [$course, (int) $cm->id, $confscheduler, $room1, $room2, $slotid];
    }

    /**
     * An editing teacher can edit an existing span block's label/colour/time/room-range.
     */
    public function test_updates_span_block(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1, $room2, $slotid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = update_span_block::execute(
            $cmid,
            $slotid,
            'Plenary',
            '#ff0000',
            [$room1, $room2],
            strtotime('2026-09-01 14:00:00'),
            strtotime('2026-09-01 15:00:00')
        );

        $this->assertTrue($result['success']);

        global $DB;
        $slot = $DB->get_record('confscheduler_slot', ['id' => $slotid]);
        $this->assertSame('Plenary', $slot->label);
        $this->assertSame('#ff0000', $slot->colour);
        $this->assertSame(2, $DB->count_records('confscheduler_slotroom', ['slotid' => $slotid]));
    }

    /**
     * A blank/whitespace-only label is rejected.
     */
    public function test_rejects_blank_label(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1, , $slotid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        update_span_block::execute(
            $cmid,
            $slotid,
            '   ',
            null,
            [$room1],
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 13:00:00')
        );
    }

    /**
     * A slot id belonging to a DIFFERENT confscheduler instance is rejected (IDOR
     * prevention), with the same "invalid slot" message as a slot that simply does
     * not exist -- see scheduler_context_trait::require_slot_in_instance()'s docblock.
     */
    public function test_rejects_slot_from_different_instance(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1] = $this->create_fixture();
        [, , , , , $foreignslotid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        update_span_block::execute(
            $cmid,
            $foreignslotid,
            'Hijacked',
            null,
            [$room1],
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 13:00:00')
        );
    }

    /**
     * A non-existent slot id is rejected with the same message as a foreign one.
     */
    public function test_rejects_nonexistent_slot(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        update_span_block::execute(
            $cmid,
            999999,
            'Ghost',
            null,
            [$room1],
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 13:00:00')
        );
    }

    /**
     * A plain student (no manageschedule) cannot call this endpoint.
     */
    public function test_requires_manageschedule_capability(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $room1, , $slotid] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        update_span_block::execute(
            $cmid,
            $slotid,
            'Hijacked',
            null,
            [$room1],
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 13:00:00')
        );
    }
}
