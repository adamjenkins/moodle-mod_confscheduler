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
 * Tests for the run_autoscheduler AJAX external function.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(run_autoscheduler::class)]
final class run_autoscheduler_test extends advanced_testcase {
    /**
     * Creates a full fixture and two accepted submissions.
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass, 3: int, 4: int} [$course, $cmid, $confscheduler, $sub1, $sub2]
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

        $decider = $this->getDataGenerator()->create_user();
        $submissionids = [];
        for ($i = 1; $i <= 2; $i++) {
            $submitter = $this->getDataGenerator()->create_user();
            $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
                'confsubmissions' => $confsubmissions->id,
                'userid'          => $submitter->id,
                'title'           => "Talk $i",
                'abstract'        => 'An abstract.',
                'status'          => 'submitted',
                'timecreated'     => time(),
                'timemodified'    => time(),
            ]);
            \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);
            $submissionids[] = $submissionid;
        }

        return [$course, (int) $cm->id, $confscheduler, $submissionids[0], $submissionids[1]];
    }

    /**
     * An editing teacher can run the autoscheduler and gets a real summary back.
     */
    public function test_runs_autoscheduler(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        api::add_room((int) $confscheduler->id, 'Main Hall');

        $result = \core_external\external_api::clean_returnvalue(
            run_autoscheduler::execute_returns(),
            run_autoscheduler::execute(
                $cmid,
                strtotime('2026-09-01 09:00:00'),
                strtotime('2026-09-01 17:00:00'),
                false
            )
        );

        $this->assertSame(2, $result['scheduled']);
        $this->assertSame(0, $result['skipped']);
    }

    /**
     * An invalid window (end <= start) is rejected, not silently corrected.
     */
    public function test_rejects_invalid_window(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        api::add_room((int) $confscheduler->id, 'Main Hall');

        $this->expectException(\invalid_parameter_exception::class);
        run_autoscheduler::execute(
            $cmid,
            strtotime('2026-09-01 17:00:00'),
            strtotime('2026-09-01 09:00:00'),
            false
        );
    }

    /**
     * A plain student (no manageschedule) cannot call this endpoint.
     */
    public function test_requires_manageschedule_capability(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        run_autoscheduler::execute(
            $cmid,
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 17:00:00'),
            false
        );
    }

    /**
     * The confschedulerid used is always derived from the validated cmid, never
     * client input: calling this endpoint against one course's cmid can never
     * affect another course's confscheduler instance. Verified here by running
     * the autoscheduler in course A and confirming course B's room/candidate
     * pool is completely unaffected.
     */
    public function test_only_affects_the_instance_the_cmid_belongs_to(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        api::add_room((int) $confscheduler->id, 'Main Hall');

        [, , $otherconfscheduler] = $this->create_fixture();
        $otherroomid = api::add_room((int) $otherconfscheduler->id, 'Other Course Room');

        run_autoscheduler::execute(
            $cmid,
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 17:00:00'),
            false
        );

        $this->assertSame([], api::get_slots((int) $otherconfscheduler->id));
        // The other course's room is still there, untouched.
        global $DB;
        $this->assertTrue($DB->record_exists('confscheduler_room', ['id' => $otherroomid]));
    }
}
