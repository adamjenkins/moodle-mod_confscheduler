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
     * Creates a confscheduler instance and returns every fixture piece the
     * room/slot CRUD and chain-of-custody tests below need: the confscheduler
     * and confprogram instance records, and the confsubmissions instance record.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} [$confscheduler, $confprogram, $confsubmissions]
     */
    protected function create_full_fixture(): array {
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
        $confscheduler = $DB->get_record('confscheduler', ['id' => $confschedulerrecord->id], '*', MUST_EXIST);

        return [$confscheduler, $confprogram, $confsubmissions];
    }

    /**
     * Creates a submission belonging to a confsubmissions instance and records an
     * 'accept' decision for it within a confprogram instance.
     *
     * @param \stdClass $confsubmissions The confsubmissions instance record
     * @param \stdClass $confprogram The confprogram instance record
     * @param string $title Submission title
     * @return int The confsubmissions_submission id
     */
    protected function create_accepted_submission(
        \stdClass $confsubmissions,
        \stdClass $confprogram,
        string $title = 'Test Talk'
    ): int {
        global $DB;

        $submitter = $this->getDataGenerator()->create_user();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $submitter->id,
            'title'           => $title,
            'abstract'        => 'An abstract.',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);

        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        return $submissionid;
    }

    /**
     * add_room() appends a new room at (max existing sortorder + 1) when no
     * explicit sortorder is given.
     */
    public function test_add_room_appends_sortorder_when_null(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_full_fixture();

        $first = api::add_room((int) $confscheduler->id, 'Main Hall');
        $second = api::add_room((int) $confscheduler->id, 'Room B');

        global $DB;
        $this->assertSame(0, (int) $DB->get_field('confscheduler_room', 'sortorder', ['id' => $first]));
        $this->assertSame(1, (int) $DB->get_field('confscheduler_room', 'sortorder', ['id' => $second]));
    }

    /**
     * add_room() rejects a colour that is not null and not a valid 6-digit hex colour.
     */
    public function test_add_room_rejects_invalid_colour(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_full_fixture();

        $this->expectException(\invalid_parameter_exception::class);
        api::add_room((int) $confscheduler->id, 'Main Hall', null, 'not-a-colour');
    }

    /**
     * add_room() accepts a null colour and a valid 6-digit hex colour.
     */
    public function test_add_room_accepts_null_or_valid_colour(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_full_fixture();

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall', null, '#3366CC');

        global $DB;
        $this->assertSame('#3366CC', $DB->get_field('confscheduler_room', 'colour', ['id' => $roomid]));
    }

    /**
     * update_room() updates the name/colour and rejects an invalid colour.
     */
    public function test_update_room_updates_and_validates_colour(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        api::update_room($roomid, 'Renamed Hall', '#00ff00');

        global $DB;
        $room = $DB->get_record('confscheduler_room', ['id' => $roomid]);
        $this->assertSame('Renamed Hall', $room->name);
        $this->assertSame('#00ff00', $room->colour);

        $this->expectException(\invalid_parameter_exception::class);
        api::update_room($roomid, 'Renamed Hall', 'nope');
    }

    /**
     * delete_room() refuses to delete a room that still has a scheduled slot
     * referencing it, and succeeds once the room is empty.
     */
    public function test_delete_room_refuses_when_slots_exist(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        $submissionid = $this->create_accepted_submission($confsubmissions, $confprogram);

        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        $this->expectException(\moodle_exception::class);
        api::delete_room($roomid);
    }

    /**
     * delete_room() succeeds for a room with no scheduled slots.
     */
    public function test_delete_room_succeeds_when_empty(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        api::delete_room($roomid);

        global $DB;
        $this->assertFalse($DB->record_exists('confscheduler_room', ['id' => $roomid]));
    }

    /**
     * reorder_rooms() rewrites sortorder to match the given order, and rejects
     * outright (writing nothing) if any given id does not belong to the instance.
     */
    public function test_reorder_rooms_rewrites_sortorder_and_validates_ownership(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_full_fixture();
        $room1 = api::add_room((int) $confscheduler->id, 'A');
        $room2 = api::add_room((int) $confscheduler->id, 'B');

        api::reorder_rooms((int) $confscheduler->id, [$room2, $room1]);

        global $DB;
        $this->assertSame(0, (int) $DB->get_field('confscheduler_room', 'sortorder', ['id' => $room2]));
        $this->assertSame(1, (int) $DB->get_field('confscheduler_room', 'sortorder', ['id' => $room1]));

        [$otherconfscheduler] = $this->create_full_fixture();
        $foreignroom = api::add_room((int) $otherconfscheduler->id, 'Foreign');

        $this->expectException(\invalid_parameter_exception::class);
        api::reorder_rooms((int) $confscheduler->id, [$room1, $foreignroom]);
    }

    /**
     * add_slot() rejects a submissionid belonging to a confsubmissions instance
     * other than the one this confscheduler's linked confprogram vets (chain
     * of custody check (b)/(c) from the security requirement).
     */
    public function test_add_slot_rejects_submission_from_wrong_confsubmissions_instance(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        // A submission accepted by THIS confprogram instance, but belonging to a
        // DIFFERENT confsubmissions instance than the one this confprogram vets.
        $othercourse = $this->getDataGenerator()->create_course();
        $otherconfsubmissions = $this->getDataGenerator()->create_module('confsubmissions', ['course' => $othercourse->id]);
        global $DB;
        $submitter = $this->getDataGenerator()->create_user();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $otherconfsubmissions->id,
            'userid'          => $submitter->id,
            'title'           => 'Foreign Talk',
            'abstract'        => 'Should never be schedulable here.',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        $this->expectException(\moodle_exception::class);
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );
    }

    /**
     * add_slot() rejects a submission that has never been decided at all.
     */
    public function test_add_slot_rejects_submission_without_decision(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        global $DB;
        $submitter = $this->getDataGenerator()->create_user();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $submitter->id,
            'title'           => 'Undecided Talk',
            'abstract'        => 'Not decided yet.',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);

        $this->expectException(\moodle_exception::class);
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );
    }

    /**
     * add_slot() rejects a submission whose most recent (global) decision is
     * 'accept' but belongs to a DIFFERENT confprogram instance than the one this
     * confscheduler is linked to, even though the submission itself belongs to
     * the right confsubmissions instance.
     */
    public function test_add_slot_rejects_submission_accepted_by_different_confprogram_instance(): void {
        $this->resetAfterTest();
        global $DB;

        [$confscheduler, , $confsubmissions] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        // A second confprogram instance vetting the SAME confsubmissions instance.
        $confsubmissionscm = get_coursemodule_from_instance('confsubmissions', $confsubmissions->id);
        $othercourse = $DB->get_field('confsubmissions', 'course', ['id' => $confsubmissions->id]);
        $otherconfprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $othercourse,
            'confsubmissionscmid' => $confsubmissionscm->id,
        ]);

        $submitter = $this->getDataGenerator()->create_user();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $submitter->id,
            'title'           => 'Talk Accepted Elsewhere',
            'abstract'        => 'Accepted by the wrong confprogram instance.',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $otherconfprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        $this->expectException(\moodle_exception::class);
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );
    }

    /**
     * add_slot() succeeds for a submission that genuinely belongs to (and was
     * accepted by) the linked confprogram/confsubmissions instance chain.
     */
    public function test_add_slot_accepts_valid_chain_of_custody(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        $submissionid = $this->create_accepted_submission($confsubmissions, $confprogram);

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        $this->assertGreaterThan(0, $slotid);
    }

    /**
     * add_slot() rejects a true time overlap in the same room, regardless of
     * GapSnap (gapminutes = 0 here).
     */
    public function test_add_slot_rejects_time_overlap_same_room(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        $first = $this->create_accepted_submission($confsubmissions, $confprogram, 'First Talk');
        $second = $this->create_accepted_submission($confsubmissions, $confprogram, 'Second Talk');

        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $first
        );

        $this->expectException(\moodle_exception::class);
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:15:00'),
            strtotime('2026-09-01 10:45:00'),
            $second
        );
    }

    /**
     * With gapminutes = 0, a slot starting exactly when another ends in the same
     * room (flush adjacency, zero gap) is allowed.
     */
    public function test_add_slot_allows_flush_adjacency_when_gapminutes_zero(): void {
        global $DB;
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $DB->set_field('confscheduler', 'gapminutes', 0, ['id' => $confscheduler->id]);
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        $first = $this->create_accepted_submission($confsubmissions, $confprogram, 'First Talk');
        $second = $this->create_accepted_submission($confsubmissions, $confprogram, 'Second Talk');

        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $first
        );

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:30:00'),
            strtotime('2026-09-01 11:00:00'),
            $second
        );

        $this->assertGreaterThan(0, $slotid);
    }

    /**
     * GapSnap boundary: with gapminutes = 10, a gap of EXACTLY 10 minutes between
     * two presentations in the same room is allowed (inclusive boundary), but a
     * gap of 9 minutes is rejected.
     */
    public function test_add_slot_gapsnap_boundary_exact_gap_allowed_one_minute_short_rejected(): void {
        global $DB;
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $DB->set_field('confscheduler', 'gapminutes', 10, ['id' => $confscheduler->id]);
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        $first = $this->create_accepted_submission($confsubmissions, $confprogram, 'First Talk');
        $second = $this->create_accepted_submission($confsubmissions, $confprogram, 'Second Talk');
        $third = $this->create_accepted_submission($confsubmissions, $confprogram, 'Third Talk');

        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $first
        );

        // Exactly a 10-minute gap (10:30 -> 10:40): allowed.
        $slotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:40:00'),
            strtotime('2026-09-01 11:10:00'),
            $second
        );
        $this->assertGreaterThan(0, $slotid);

        // A 9-minute gap before the FIRST slot (09:51 -> 10:00, i.e. one minute short
        // of the required 10): rejected. Placed before the first slot (rather than
        // between the first and second) so it cannot also collide with the second slot.
        $this->expectException(\moodle_exception::class);
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 09:21:00'),
            strtotime('2026-09-01 09:51:00'),
            $third
        );
    }

    /**
     * Two column-spanning blocks (both submissionid null) may be flush against
     * each other even when gapminutes > 0.
     */
    public function test_add_slot_allows_two_spanblocks_flush_with_gapminutes(): void {
        global $DB;
        $this->resetAfterTest();

        [$confscheduler] = $this->create_full_fixture();
        $DB->set_field('confscheduler', 'gapminutes', 15, ['id' => $confscheduler->id]);
        $room1 = api::add_room((int) $confscheduler->id, 'A');
        $room2 = api::add_room((int) $confscheduler->id, 'B');

        api::add_slot(
            (int) $confscheduler->id,
            [$room1, $room2],
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 13:00:00'),
            null,
            'Lunch'
        );

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            [$room1, $room2],
            strtotime('2026-09-01 13:00:00'),
            strtotime('2026-09-01 14:00:00'),
            null,
            'Plenary'
        );

        $this->assertGreaterThan(0, $slotid);
    }

    /**
     * update_slot() reschedules a slot and excludes the slot's own current
     * confscheduler_slotroom rows from the overlap/GapSnap check against itself.
     */
    public function test_update_slot_reschedules_and_excludes_self(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        $submissionid = $this->create_accepted_submission($confsubmissions, $confprogram);

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        // Moving the slot by 15 minutes would overlap ITSELF if the exclusion were
        // broken; it must succeed cleanly instead.
        api::update_slot(
            $slotid,
            [$roomid],
            strtotime('2026-09-01 10:15:00'),
            strtotime('2026-09-01 10:45:00')
        );

        global $DB;
        $slot = $DB->get_record('confscheduler_slot', ['id' => $slotid]);
        $this->assertEquals(strtotime('2026-09-01 10:15:00'), (int) $slot->starttime);
        $this->assertEquals(strtotime('2026-09-01 10:45:00'), (int) $slot->endtime);
    }

    /**
     * update_slot() still rejects a genuine overlap against a DIFFERENT slot.
     */
    public function test_update_slot_rejects_overlap_with_other_slot(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        $first = $this->create_accepted_submission($confsubmissions, $confprogram, 'First Talk');
        $second = $this->create_accepted_submission($confsubmissions, $confprogram, 'Second Talk');

        $firstslotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $first
        );
        $secondslotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 11:00:00'),
            strtotime('2026-09-01 11:30:00'),
            $second
        );

        $this->expectException(\moodle_exception::class);
        api::update_slot($secondslotid, [$roomid], strtotime('2026-09-01 10:15:00'), strtotime('2026-09-01 10:45:00'));
        // Avoid an unused-variable warning; $firstslotid establishes the conflicting fixture.
        $this->assertGreaterThan(0, $firstslotid);
    }

    /**
     * delete_slot() removes both the slot and its confscheduler_slotroom rows.
     */
    public function test_delete_slot_removes_slot_and_slotroom_rows(): void {
        global $DB;
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        $submissionid = $this->create_accepted_submission($confsubmissions, $confprogram);

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        api::delete_slot($slotid);

        $this->assertFalse($DB->record_exists('confscheduler_slot', ['id' => $slotid]));
        $this->assertFalse($DB->record_exists('confscheduler_slotroom', ['slotid' => $slotid]));
    }

    /**
     * assert_submission_belongs_to_instance() is a public pass-through to the
     * same chain-of-custody check add_slot() performs, for callers (the
     * favourite-toggle external function) that need to assert it without
     * scheduling anything.
     */
    public function test_assert_submission_belongs_to_instance(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $submissionid = $this->create_accepted_submission($confsubmissions, $confprogram);

        // Does not throw.
        api::assert_submission_belongs_to_instance((int) $confscheduler->id, $submissionid);

        $unaccepted = $this->create_accepted_submission($confsubmissions, $confprogram);
        global $DB;
        $DB->delete_records('confprogram_decision', ['submissionid' => $unaccepted]);

        $this->expectException(\moodle_exception::class);
        api::assert_submission_belongs_to_instance((int) $confscheduler->id, $unaccepted);
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
