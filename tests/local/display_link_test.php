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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for display_link::program_url().
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(display_link::class)]
final class display_link_test extends advanced_testcase {
    /**
     * A confscheduler record whose confprogramcmid resolves to a real course-module
     * produces a URL pointing at that course-module's mod_confprogram view page.
     */
    public function test_program_url_resolves_linked_confprogram(): void {
        global $CFG;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $confsubmissions = $this->getDataGenerator()->create_module('confsubmissions', ['course' => $course->id]);
        $confsubmissionscm = get_coursemodule_from_instance('confsubmissions', $confsubmissions->id);

        $confprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $course->id,
            'confsubmissionscmid' => $confsubmissionscm->id,
        ]);
        $confprogramcm = get_coursemodule_from_instance('confprogram', $confprogram->id);

        $confscheduler = (object) ['confprogramcmid' => $confprogramcm->id];

        $url = display_link::program_url($confscheduler);

        $this->assertSame($CFG->wwwroot . '/mod/confprogram/view.php', $url->out_omit_querystring());
        $this->assertSame((string) $confprogramcm->id, $url->get_param('id'));
    }

    /**
     * A confscheduler record whose confprogramcmid no longer resolves to any
     * course-module (e.g. the linked activity was deleted) throws rather than
     * silently building a broken/misleading URL.
     */
    public function test_program_url_throws_for_nonexistent_cmid(): void {
        $this->resetAfterTest();

        $confscheduler = (object) ['confprogramcmid' => 999999];

        $this->expectException(\dml_exception::class);
        display_link::program_url($confscheduler);
    }
}
