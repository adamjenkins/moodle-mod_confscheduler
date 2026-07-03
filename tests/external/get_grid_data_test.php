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
}
