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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the send_pending_notifications AJAX external function -- the one
 * external endpoint that previously had no test of its own (FABLE.md review,
 * 2026-07-09; the underlying api::send_pending_notifications() behaviour is
 * covered in tests/local/notifier_test.php, so this focuses on the endpoint's
 * capability gate and return shape).
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(send_pending_notifications::class)]
final class send_pending_notifications_test extends advanced_testcase {
    /**
     * Creates a course with the linked confsubmissions/confprogram/confscheduler chain.
     *
     * @return array{0: \stdClass, 1: int} [$course, $cmid]
     */
    protected function create_fixture(): array {
        $course = $this->getDataGenerator()->create_course();

        $confsubmissions = $this->getDataGenerator()->create_module('confsubmissions', ['course' => $course->id]);
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
        $cm = get_coursemodule_from_instance('confscheduler', $confscheduler->id);

        return [$course, (int) $cm->id];
    }

    /**
     * A manageschedule holder can call the endpoint; with nothing scheduled the
     * validated return reports zero slots notified.
     */
    public function test_returns_sent_count(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = \core_external\external_api::clean_returnvalue(
            send_pending_notifications::execute_returns(),
            send_pending_notifications::execute($cmid)
        );
        $this->assertSame(0, $result['sent']);
    }

    /**
     * A plain student (no manageschedule) cannot trigger notification sends.
     */
    public function test_requires_manageschedule_capability(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        send_pending_notifications::execute($cmid);
    }
}
