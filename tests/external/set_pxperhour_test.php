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
 * Tests for the set_pxperhour AJAX external function, which backs the quick
 * row-height control at the top of the schedule grid (user feedback,
 * 2026-07-05).
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(set_pxperhour::class)]
final class set_pxperhour_test extends advanced_testcase {
    /**
     * Creates a full fixture.
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
     * An editing teacher can set the row height.
     */
    public function test_sets_pxperhour(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = set_pxperhour::execute($cmid, 200);
        $this->assertTrue($result['success']);
        $this->assertEquals(200, $DB->get_field('confscheduler', 'pxperhour', ['id' => $confscheduler->id]));
    }

    /**
     * A value below api::MIN_PX_PER_HOUR is rejected, and nothing is changed.
     */
    public function test_rejects_too_small_value(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        try {
            set_pxperhour::execute($cmid, api::MIN_PX_PER_HOUR - 1);
        } finally {
            $this->assertEquals(144, $DB->get_field('confscheduler', 'pxperhour', ['id' => $confscheduler->id]));
        }
    }

    /**
     * A value above api::MAX_PX_PER_HOUR is rejected, and nothing is changed.
     */
    public function test_rejects_too_large_value(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        try {
            set_pxperhour::execute($cmid, api::MAX_PX_PER_HOUR + 1);
        } finally {
            $this->assertEquals(144, $DB->get_field('confscheduler', 'pxperhour', ['id' => $confscheduler->id]));
        }
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
        set_pxperhour::execute($cmid, 200);
    }

    /**
     * The confschedulerid used is always derived from the validated cmid, never client
     * input: calling this endpoint against one course's cmid can never affect another
     * course's confscheduler instance.
     */
    public function test_only_affects_the_instance_the_cmid_belongs_to(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $cmid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        [, , $otherconfscheduler] = $this->create_fixture();

        set_pxperhour::execute($cmid, 200);

        $this->assertEquals(144, $DB->get_field('confscheduler', 'pxperhour', ['id' => $otherconfscheduler->id]));
    }
}
