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
use mod_confsubmissions\api as submissions_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the get_grid_data AJAX external function.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_grid_data::class)]
final class get_grid_data_test extends advanced_testcase {
    /**
     * Creates a full fixture: course, confsubmissions/confprogram/confscheduler
     * instances (linked), one room, and one accepted-but-unscheduled submission.
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass, 3: \stdClass, 4: int}
     *     [$course, $cmid, $confscheduler, $confprogram, $submissionid]
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

        return [$course, (int) $cm->id, $confscheduler, $confprogram, $submissionid];
    }

    /**
     * An editing teacher (manageschedule) sees rooms, scheduled slots (decorated
     * with title/speakers/track), and unscheduled accepted submissions.
     */
    public function test_returns_full_payload(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler, , $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall', null, '#3366cc');

        $result = get_grid_data::execute($cmid);

        $this->assertCount(1, $result['rooms']);
        $this->assertSame('Main Hall', $result['rooms'][0]['name']);
        $this->assertSame('#3366cc', $result['rooms'][0]['colour']);
        $this->assertSame(144, $result['pxperhour']);
        $this->assertCount(1, $result['unscheduled']);
        $this->assertSame($submissionid, $result['unscheduled'][0]['submissionid']);
        $this->assertCount(0, $result['slots']);

        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        $result = get_grid_data::execute($cmid);
        $this->assertCount(0, $result['unscheduled']);
        $this->assertCount(1, $result['slots']);
        $this->assertSame('A Test Talk', $result['slots'][0]['title']);
        $this->assertSame([$roomid], $result['slots'][0]['roomids']);
    }

    /**
     * A span block's colour is surfaced in the payload (Revision round 1,
     * 2026-07-03), but a presentation slot's 'colour' is always null even though
     * the underlying column exists on every confscheduler_slot row.
     */
    public function test_payload_includes_span_block_colour(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler, , $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 09:30:00'),
            null,
            'Plenary',
            '#3366cc'
        );
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        $result = get_grid_data::execute($cmid);
        $bylabel = [];
        foreach ($result['slots'] as $slot) {
            $bylabel[$slot['label'] ?? 'presentation'] = $slot;
        }

        $this->assertSame('#3366cc', $bylabel['Plenary']['colour']);
        $this->assertNull($bylabel['presentation']['colour']);
    }

    /**
     * The grid payload exposes iscontainer/parentslotid/roomnameoverride for a
     * container and its child, with the child's roomids/roomnameoverride
     * resolved from the parent (user request, 2026-07-08 -- poster/keynote
     * container blocks).
     */
    public function test_grid_data_exposes_container_fields(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler, , $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $room1 = api::add_room((int) $confscheduler->id, 'Main Hall');
        $room2 = api::add_room((int) $confscheduler->id, 'Room B');

        $containerid = api::add_slot(
            (int) $confscheduler->id,
            [$room1, $room2],
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 11:00:00'),
            null,
            'Poster Session',
            null,
            true,
            'Exhibit Hall'
        );
        $childid = api::add_presentation_to_container((int) $confscheduler->id, $containerid, $submissionid);

        $result = get_grid_data::execute($cmid);
        $byid = [];
        foreach ($result['slots'] as $entry) {
            $byid[$entry['id']] = $entry;
        }

        $this->assertTrue($byid[$containerid]['iscontainer']);
        $this->assertNull($byid[$containerid]['parentslotid']);
        $this->assertSame('Exhibit Hall', $byid[$containerid]['roomnameoverride']);

        $this->assertFalse($byid[$childid]['iscontainer']);
        $this->assertSame($containerid, $byid[$childid]['parentslotid']);
        $this->assertSame('Exhibit Hall', $byid[$childid]['roomnameoverride']);
        $this->assertSame([$room1, $room2], $byid[$childid]['roomids']);
    }

    /**
     * A scheduled presentation's 'nonpreferredday' flag (user feedback,
     * 2026-07-05, consumed by the edit-mode grid to highlight the block) is true
     * only when the submission has a non-empty preferred-dates list AND the slot's
     * own day is not one of them; false when there's no recorded preference at all,
     * and false again once the slot is moved onto a preferred day.
     */
    public function test_slot_flags_nonpreferredday_correctly(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler, , $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 09:30:00'),
            $submissionid
        );

        // No preference recorded at all: never flagged.
        $result = get_grid_data::execute($cmid);
        $this->assertFalse($result['slots'][0]['nonpreferredday']);

        // Preference recorded, but for a DIFFERENT day than the one it's scheduled
        // on: flagged.
        $preferredday = usergetmidnight(strtotime('2026-09-02 00:00:00'));
        \mod_confsubmissions\api::sync_date_preferences($submissionid, [$preferredday]);

        $result = get_grid_data::execute($cmid);
        $this->assertTrue($result['slots'][0]['nonpreferredday']);

        // Preference recorded, and it DOES include the day it's scheduled on: not
        // flagged.
        $scheduledday = usergetmidnight(strtotime('2026-09-01 00:00:00'));
        \mod_confsubmissions\api::sync_date_preferences($submissionid, [$scheduledday]);

        $result = get_grid_data::execute($cmid);
        $this->assertFalse($result['slots'][0]['nonpreferredday']);
    }

    /**
     * A span block (no submissionid) is never flagged 'nonpreferredday' -- there is
     * no submitter/preference concept for it at all.
     */
    public function test_span_block_never_flagged_nonpreferredday(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 09:30:00'),
            null,
            'Lunch'
        );

        $result = get_grid_data::execute($cmid);
        $this->assertFalse($result['slots'][0]['nonpreferredday']);
    }

    /**
     * A user with viewschedule but not manageschedule (a plain student) can still
     * read the grid data, since this endpoint also backs the future read-only
     * Display mode.
     */
    public function test_viewschedule_capability_is_sufficient(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $result = get_grid_data::execute($cmid);
        $this->assertIsArray($result['rooms']);
    }

    /**
     * A user with neither capability (a bare enrolment with a capability-less
     * role) cannot call this endpoint.
     */
    public function test_requires_viewschedule_capability(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $bareroleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $bareroleid);
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        get_grid_data::execute($cmid);
    }

    /**
     * A scheduled slot's track colour is surfaced in the payload, for the
     * client to theme the track pill with (user request, 2026-07-06).
     */
    public function test_slot_includes_trackcolour(): void {
        global $DB;
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler, , $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $confsubmissionsid = $DB->get_field('confsubmissions_submission', 'confsubmissions', ['id' => $submissionid]);
        $trackid = submissions_api::add_track((int) $confsubmissionsid, 'Data Science', '#3366cc');
        $DB->set_field('confsubmissions_submission', 'trackid', $trackid, ['id' => $submissionid]);

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall', null, null);
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        $result = get_grid_data::execute($cmid);

        $this->assertSame('#3366cc', $result['slots'][0]['trackcolour']);

        // Also run the result through clean_returnvalue(), the same schema-stripping step
        // Moodle's real AJAX transport applies -- execute() alone would have kept passing
        // even when trackcolour was undeclared in execute_returns() and silently stripped
        // in transit (the exact bug this field's addition already hit once; moodle-reviewer
        // finding, 2026-07-06).
        $clean = \core_external\external_api::clean_returnvalue(get_grid_data::execute_returns(), $result);
        $this->assertSame('#3366cc', $clean['slots'][0]['trackcolour']);
    }

    /**
     * A track with no configured colour surfaces trackcolour as null, not an
     * empty string or the string 'null'.
     */
    public function test_slot_trackcolour_null_when_track_has_no_colour(): void {
        global $DB;
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler, , $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $confsubmissionsid = $DB->get_field('confsubmissions_submission', 'confsubmissions', ['id' => $submissionid]);
        $trackid = submissions_api::add_track((int) $confsubmissionsid, 'Uncoloured Track');
        $DB->set_field('confsubmissions_submission', 'trackid', $trackid, ['id' => $submissionid]);

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall', null, null);
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        $result = get_grid_data::execute($cmid);

        $this->assertNull($result['slots'][0]['trackcolour']);
    }

    /**
     * A fresh instance has null daystart/dayend (fully automatic, unchanged from
     * before this feature existed) until an organiser configures them. Also runs
     * the result through clean_returnvalue() (the same schema-stripping step
     * Moodle's real AJAX transport applies), per the exact lesson this file's own
     * trackcolour tests already learned the hard way this same round.
     */
    public function test_payload_includes_null_day_bounds_by_default(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = get_grid_data::execute($cmid);

        $this->assertNull($result['daystart']);
        $this->assertNull($result['dayend']);

        $clean = \core_external\external_api::clean_returnvalue(get_grid_data::execute_returns(), $result);
        $this->assertArrayHasKey('daystart', $clean);
        $this->assertArrayHasKey('dayend', $clean);
    }

    /**
     * Once configured, both bounds are surfaced in the payload and survive
     * clean_returnvalue().
     */
    public function test_payload_includes_configured_day_bounds(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        api::set_day_bounds((int) $confscheduler->id, 480, 1080);

        $result = get_grid_data::execute($cmid);

        $this->assertSame(480, $result['daystart']);
        $this->assertSame(1080, $result['dayend']);

        $clean = \core_external\external_api::clean_returnvalue(get_grid_data::execute_returns(), $result);
        $this->assertSame(480, $clean['daystart']);
        $this->assertSame(1080, $clean['dayend']);
    }

    /**
     * A scheduled slot's 'withdrawn' flag tracks mod_confsubmissions's own
     * confsubmissions_submission.status directly: false while the submission is
     * still 'accepted', true once the submitter withdraws it (user request,
     * 2026-07-07, so the read-only Display-mode grid can grey out/strike through an
     * already-scheduled block instead of clicking through to a normal submission
     * detail modal for a cancelled talk). Also run through clean_returnvalue(), per
     * the exact lesson this file's own trackcolour/daybounds tests already learned
     * the hard way: an undeclared field in execute_returns() is silently stripped
     * in transit even though execute() alone would keep passing.
     */
    public function test_slot_flags_withdrawn_submission(): void {
        global $DB;
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler, , $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        $result = get_grid_data::execute($cmid);
        $this->assertFalse($result['slots'][0]['withdrawn']);

        $DB->set_field('confsubmissions_submission', 'status', 'withdrawn', ['id' => $submissionid]);

        $result = get_grid_data::execute($cmid);
        $this->assertTrue($result['slots'][0]['withdrawn']);

        $clean = \core_external\external_api::clean_returnvalue(get_grid_data::execute_returns(), $result);
        $this->assertTrue($clean['slots'][0]['withdrawn']);
    }
}
