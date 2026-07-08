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

namespace mod_confscheduler\local;

use advanced_testcase;
use mod_confscheduler\api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \mod_confscheduler\local\ics_export -- the ICS "my timetable"
 * export (user request, 2026-07-06).
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(ics_export::class)]
final class ics_export_test extends advanced_testcase {
    /**
     * Creates a confscheduler instance (with its confprogram/confsubmissions
     * dependencies) and returns the pieces every test below needs. Mirrors
     * notifier_test::create_fixture().
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} [$confscheduler, $confprogram, $confsubmissions]
     */
    private function create_fixture(): array {
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
     * Creates an accepted submission owned by the given speaker.
     *
     * @param \stdClass $confsubmissions The confsubmissions instance record
     * @param \stdClass $confprogram The confprogram instance record
     * @param \stdClass $speaker The speaker's user record
     * @param string $title
     * @return int The confsubmissions_submission id
     */
    private function create_accepted_submission(
        \stdClass $confsubmissions,
        \stdClass $confprogram,
        \stdClass $speaker,
        string $title
    ): int {
        global $DB;

        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $speaker->id,
            'title'           => $title,
            'abstract'        => 'Abstract text',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        \mod_confsubmissions\api::sync_speakers($submissionid, [['userid' => $speaker->id]]);

        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        return $submissionid;
    }

    /**
     * build() includes only scheduled, favourited presentations for the given
     * user -- an unfavourited scheduled presentation is excluded.
     */
    public function test_build_includes_only_favourited_scheduled_presentations(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_fixture();
        $speaker = $this->getDataGenerator()->create_user();
        $attendee = $this->getDataGenerator()->create_user();

        $favouritedid = $this->create_accepted_submission($confsubmissions, $confprogram, $speaker, 'Favourited Talk');
        $otherid = $this->create_accepted_submission($confsubmissions, $confprogram, $speaker, 'Other Talk');

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $favouritedid
        );
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 11:00:00'),
            strtotime('2026-09-01 11:30:00'),
            $otherid
        );

        \mod_confprogram\api::add_favourite((int) $confprogram->id, $favouritedid, (int) $attendee->id);

        $ics = ics_export::build($confscheduler, (int) $attendee->id);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('SUMMARY:Favourited Talk', $ics);
        $this->assertStringContainsString('LOCATION:Main Hall', $ics);
        $this->assertStringNotContainsString('Other Talk', $ics);
    }

    /**
     * A user with no favourites in the instance gets a validly-serialized,
     * empty calendar -- not an error.
     */
    public function test_build_with_no_favourites_returns_empty_calendar(): void {
        $this->resetAfterTest();

        [$confscheduler, , ] = $this->create_fixture();
        $attendee = $this->getDataGenerator()->create_user();

        $ics = ics_export::build($confscheduler, (int) $attendee->id);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
    }

    /**
     * A column-spanning block (submissionid null) is never exported, even
     * though it can never actually be favourited -- defensive coverage of
     * build()'s own explicit exclusion.
     */
    public function test_build_excludes_span_blocks(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_fixture();
        $attendee = $this->getDataGenerator()->create_user();

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');
        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 13:00:00'),
            null,
            'Lunch'
        );

        $ics = ics_export::build($confscheduler, (int) $attendee->id);

        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
    }

    /**
     * A favourited presentation's exported LOCATION prefers roomnameoverride
     * when its container has one set, over the joined real room names.
     */
    public function test_build_prefers_roomnameoverride_for_location(): void {
        global $DB;

        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_fixture();
        $speaker = $this->getDataGenerator()->create_user();
        $attendee = $this->getDataGenerator()->create_user();

        $submissionid = $this->create_accepted_submission($confsubmissions, $confprogram, $speaker, 'Talk in Container');

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        // Create a container span block with roomnameoverride set to 'Exhibit Hall'.
        $containerid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            null,
            'Poster Session',
            null,
            true,
            'Exhibit Hall'
        );

        // Insert the presentation slot as a child inside the container.
        $now = time();
        $DB->insert_record('confscheduler_slot', (object) [
            'confscheduler' => $confscheduler->id,
            'submissionid'  => $submissionid,
            'parentslotid'  => $containerid,
            'starttime'     => strtotime('2026-09-01 10:00:00'),
            'endtime'       => strtotime('2026-09-01 10:30:00'),
            'timecreated'   => $now,
            'timemodified'  => $now,
        ]);

        // Mark the presentation as favourited by the attendee.
        \mod_confprogram\api::add_favourite((int) $confprogram->id, $submissionid, (int) $attendee->id);

        $ics = ics_export::build($confscheduler, (int) $attendee->id);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('SUMMARY:Talk in Container', $ics);
        $this->assertStringContainsString('LOCATION:Exhibit Hall', $ics);
        $this->assertStringNotContainsString('LOCATION:Main Hall', $ics);
    }

    /**
     * filename() produces a clean, instance-name-based .ics filename.
     */
    public function test_filename_is_based_on_instance_name(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_fixture();
        $confscheduler->name = 'My Conference 2026';

        $this->assertSame('My Conference 2026-timetable.ics', ics_export::filename($confscheduler));
    }
}
