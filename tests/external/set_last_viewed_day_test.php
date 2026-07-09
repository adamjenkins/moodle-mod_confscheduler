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
 * Tests for the set_last_viewed_day AJAX external function, which backs the
 * "remember each user's last viewed day" feature (user request, 2026-07-07).
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(set_last_viewed_day::class)]
final class set_last_viewed_day_test extends advanced_testcase {
    /**
     * Creates a full fixture.
     *
     * @param bool $rememberlastday Whether the instance has rememberlastday enabled
     * @return array{0: \stdClass, 1: int, 2: \stdClass} [$course, $cmid, $confscheduler]
     */
    protected function create_fixture(bool $rememberlastday): array {
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
            'rememberlastday' => $rememberlastday ? 1 : 0,
        ]);
        $cm = get_coursemodule_from_instance('confscheduler', $confschedulerrecord->id);
        $confscheduler = $DB->get_record('confscheduler', ['id' => $confschedulerrecord->id], '*', MUST_EXIST);

        return [$course, (int) $cm->id, $confscheduler];
    }

    /**
     * A viewer (no manageschedule needed, just viewschedule) can record a day key.
     */
    public function test_records_a_day_key(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture(true);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $result = \core_external\external_api::clean_returnvalue(
            set_last_viewed_day::execute_returns(),
            set_last_viewed_day::execute($cmid, '2026-08-15')
        );
        $this->assertTrue($result['success']);
        $this->assertSame('2026-08-15', api::get_last_viewed_day((int) $confscheduler->id, (int) $student->id));

        $clean = \core_external\external_api::clean_returnvalue(set_last_viewed_day::execute_returns(), $result);
        $this->assertTrue($clean['success']);
    }

    /**
     * 'all' is also accepted, for the All days view.
     */
    public function test_records_all_days(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture(true);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        set_last_viewed_day::execute($cmid, 'all');
        $this->assertSame('all', api::get_last_viewed_day((int) $confscheduler->id, (int) $student->id));
    }

    /**
     * When the instance's rememberlastday switch is off, the call still succeeds (the
     * JS caller does not need to check the switch itself) but nothing is persisted.
     */
    public function test_no_op_when_rememberlastday_disabled(): void {
        $this->resetAfterTest();

        [$course, $cmid, $confscheduler] = $this->create_fixture(false);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $result = \core_external\external_api::clean_returnvalue(
            set_last_viewed_day::execute_returns(),
            set_last_viewed_day::execute($cmid, '2026-08-15')
        );
        $this->assertTrue($result['success']);
        $this->assertNull(api::get_last_viewed_day((int) $confscheduler->id, (int) $student->id));
    }

    /**
     * A malformed day value (not a Y-m-d key or 'all') is rejected.
     */
    public function test_rejects_invalid_day(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture(true);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\invalid_parameter_exception::class);
        set_last_viewed_day::execute($cmid, 'not-a-day');
    }
}
