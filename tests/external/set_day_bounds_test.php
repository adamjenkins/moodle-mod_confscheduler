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
 * Tests for the set_day_bounds AJAX external function, which backs the quick
 * "day start"/"day end" display-window control at the top of the schedule
 * grid in edit mode (user feedback, 2026-07-06).
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(set_day_bounds::class)]
final class set_day_bounds_test extends advanced_testcase {
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
     * An editing teacher can set both bounds together.
     */
    public function test_sets_day_bounds(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = set_day_bounds::execute($cmid, 480, 1080);
        $this->assertTrue($result['success']);
        $this->assertEquals(480, $DB->get_field('confscheduler', 'daystart', ['id' => $confscheduler->id]));
        $this->assertEquals(1080, $DB->get_field('confscheduler', 'dayend', ['id' => $confscheduler->id]));

        // Also exercise the real return-value schema validation, not just execute()'s
        // raw return array (see the confprogram trackcolour incident this same round --
        // a field silently dropped in transit would still pass a bare execute() check).
        $clean = \core_external\external_api::clean_returnvalue(set_day_bounds::execute_returns(), $result);
        $this->assertTrue($clean['success']);
    }

    /**
     * Passing null for both clears back to "automatic" (the "Automatic" checkbox path).
     */
    public function test_clears_day_bounds_with_both_null(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        set_day_bounds::execute($cmid, 480, 1080);
        set_day_bounds::execute($cmid, null, null);

        $this->assertNull($DB->get_field('confscheduler', 'daystart', ['id' => $confscheduler->id]));
        $this->assertNull($DB->get_field('confscheduler', 'dayend', ['id' => $confscheduler->id]));
    }

    /**
     * Exactly one of the two being null (rather than both) is rejected, and nothing changes.
     */
    public function test_rejects_exactly_one_null(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        try {
            set_day_bounds::execute($cmid, 480, null);
        } finally {
            $this->assertNull($DB->get_field('confscheduler', 'daystart', ['id' => $confscheduler->id]));
        }
    }

    /**
     * dayend must be strictly after daystart.
     */
    public function test_rejects_dayend_not_after_daystart(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        try {
            set_day_bounds::execute($cmid, 600, 600);
        } finally {
            $this->assertNull($DB->get_field('confscheduler', 'daystart', ['id' => $confscheduler->id]));
        }
    }

    /**
     * Values must be within a single day (0-1439 minutes).
     */
    public function test_rejects_out_of_range_values(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        set_day_bounds::execute($cmid, -1, 600);
    }

    /**
     * A per-day override (a day key given) is stored in confscheduler_daybounds and
     * leaves the instance-level default untouched (user request, 2026-07-07).
     */
    public function test_sets_per_day_override(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        // A default plus a different window for one specific day.
        set_day_bounds::execute($cmid, 540, 1020);
        $result = set_day_bounds::execute($cmid, 480, 1140, '2026-07-10');
        $this->assertTrue($result['success']);

        // The default is unchanged.
        $this->assertEquals(540, $DB->get_field('confscheduler', 'daystart', ['id' => $confscheduler->id]));
        $this->assertEquals(1020, $DB->get_field('confscheduler', 'dayend', ['id' => $confscheduler->id]));

        // The override exists for exactly that day.
        $overrides = api::get_day_bounds((int) $confscheduler->id);
        $this->assertSame(['2026-07-10' => ['daystart' => 480, 'dayend' => 1140]], $overrides);

        // Updating the same day upserts (no duplicate row).
        set_day_bounds::execute($cmid, 500, 1000, '2026-07-10');
        $this->assertSame(['2026-07-10' => ['daystart' => 500, 'dayend' => 1000]], api::get_day_bounds((int) $confscheduler->id));
        $this->assertEquals(1, $DB->count_records('confscheduler_daybounds', ['confscheduler' => $confscheduler->id]));
    }

    /**
     * Clearing an override (both bounds null with a day given) deletes just that day's
     * row and leaves the instance default and other days' overrides intact.
     */
    public function test_clears_per_day_override(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $cmid, $confscheduler] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        set_day_bounds::execute($cmid, 540, 1020);
        set_day_bounds::execute($cmid, 480, 1140, '2026-07-10');
        set_day_bounds::execute($cmid, 600, 900, '2026-07-11');

        set_day_bounds::execute($cmid, null, null, '2026-07-10');

        $this->assertSame(['2026-07-11' => ['daystart' => 600, 'dayend' => 900]], api::get_day_bounds((int) $confscheduler->id));
        // The default survives.
        $this->assertEquals(540, $DB->get_field('confscheduler', 'daystart', ['id' => $confscheduler->id]));
    }

    /**
     * A malformed day key (not Y-m-d) is rejected.
     */
    public function test_rejects_invalid_day_key(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        set_day_bounds::execute($cmid, 480, 1080, 'not-a-date');
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
        set_day_bounds::execute($cmid, 480, 1080);
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

        set_day_bounds::execute($cmid, 480, 1080);

        $this->assertNull($DB->get_field('confscheduler', 'daystart', ['id' => $otherconfscheduler->id]));
    }
}
