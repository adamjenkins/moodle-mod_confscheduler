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
 * Tests for the add_to_container AJAX external function.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(add_to_container::class)]
final class add_to_container_test extends advanced_testcase {
    /**
     * Creates a course containing a linked confscheduler instance with one
     * room, a container span block, and an accepted-but-unscheduled submission.
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass, 3: int, 4: int}
     *     [$course, $cmid, $confscheduler, $containerid, $submissionid]
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
        $containerid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 11:00:00'),
            null,
            'Poster Session',
            null,
            true
        );

        $submitter = $this->getDataGenerator()->create_user();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $submitter->id,
            'title'           => 'A Poster',
            'abstract'        => 'An abstract.',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        return [$course, (int) $cm->id, $confscheduler, $containerid, $submissionid];
    }

    /**
     * An editing teacher can nest an accepted submission inside a container.
     */
    public function test_adds_presentation_to_container(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $containerid, $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = \core_external\external_api::clean_returnvalue(
            add_to_container::execute_returns(),
            add_to_container::execute($cmid, $containerid, $submissionid)
        );

        global $DB;
        $child = $DB->get_record('confscheduler_slot', ['id' => $result['slotid']], '*', MUST_EXIST);
        $this->assertSame($submissionid, (int) $child->submissionid);
        $this->assertSame($containerid, (int) $child->parentslotid);
    }

    /**
     * A container slot id belonging to a DIFFERENT confscheduler instance is
     * rejected -- the same IDOR-prevention pattern every other write endpoint
     * in this plugin uses (see scheduler_context_trait's docblock).
     */
    public function test_rejects_container_from_a_different_instance(): void {
        $this->resetAfterTest();

        [$course, $cmid, , , $submissionid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $othercourse = $this->getDataGenerator()->create_course();
        $otherconfsubmissions = $this->getDataGenerator()->create_module('confsubmissions', ['course' => $othercourse->id]);
        $otherconfsubmissionscm = get_coursemodule_from_instance('confsubmissions', $otherconfsubmissions->id);
        $otherconfprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $othercourse->id,
            'confsubmissionscmid' => $otherconfsubmissionscm->id,
        ]);
        $otherconfprogramcm = get_coursemodule_from_instance('confprogram', $otherconfprogram->id);
        $otherconfschedulerrecord = $this->getDataGenerator()->create_module('confscheduler', [
            'course'          => $othercourse->id,
            'confprogramcmid' => $otherconfprogramcm->id,
        ]);
        $otherroomid = api::add_room((int) $otherconfschedulerrecord->id, 'Other Hall');
        $othercontainerid = api::add_slot(
            (int) $otherconfschedulerrecord->id,
            [$otherroomid],
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 11:00:00'),
            null,
            'Other Poster Session',
            null,
            true
        );

        $this->expectException(\invalid_parameter_exception::class);
        add_to_container::execute($cmid, $othercontainerid, $submissionid);
    }

    /**
     * A plain student (no manageschedule) cannot call this endpoint.
     */
    public function test_requires_manageschedule_capability(): void {
        $this->resetAfterTest();

        [$course, $cmid, , $containerid, $submissionid] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        add_to_container::execute($cmid, $containerid, $submissionid);
    }
}
