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

namespace mod_confscheduler;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Smoke tests for mod_confscheduler: confirms the plugin installs cleanly and
 * that a course-module instance can be created via the standard data
 * generator, pointed at a mod_confprogram instance.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class confscheduler_test extends advanced_testcase {
    /**
     * Creates a course containing a mod_confsubmissions instance and a
     * mod_confprogram instance pointed at it, and returns the course plus the
     * confprogram course-module.
     *
     * @return array{0: \stdClass, 1: \stdClass} [$course, $confprogramcm]
     */
    protected function create_course_with_confprogram(): array {
        $course = $this->getDataGenerator()->create_course();

        $confsubmissions = $this->getDataGenerator()->create_module('confsubmissions', [
            'course' => $course->id,
        ]);
        $confsubmissionscm = get_coursemodule_from_instance('confsubmissions', $confsubmissions->id);

        $confprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $course->id,
            'confsubmissionscmid' => $confsubmissionscm->id,
        ]);
        $confprogramcm = get_coursemodule_from_instance('confprogram', $confprogram->id);

        return [$course, $confprogramcm];
    }

    /**
     * An activity instance can be added to a course via the data generator,
     * pointed at an existing mod_confprogram instance, and the resulting row
     * exists in the confscheduler table with its schema defaults applied.
     */
    public function test_instance_can_be_added_via_generator(): void {
        $this->resetAfterTest();

        [$course, $confprogramcm] = $this->create_course_with_confprogram();

        $confscheduler = $this->getDataGenerator()->create_module('confscheduler', [
            'course'          => $course->id,
            'name'            => 'Test schedule',
            'confprogramcmid' => $confprogramcm->id,
        ]);

        $this->assertNotEmpty($confscheduler->id);

        global $DB;
        $record = $DB->get_record('confscheduler', ['id' => $confscheduler->id]);
        $this->assertNotFalse($record);
        $this->assertSame('Test schedule', $record->name);
        $this->assertEquals($confprogramcm->id, $record->confprogramcmid);
        $this->assertEquals(0, $record->gapminutes);
        $this->assertEquals(144, $record->pxperhour);
    }

    /**
     * The privacy provider class exists and is a null_provider, matching this
     * plugin's tables (which hold no personal data).
     */
    public function test_privacy_provider_exists(): void {
        $this->assertTrue(class_exists(\mod_confscheduler\privacy\provider::class));
        $this->assertInstanceOf(
            \core_privacy\local\metadata\null_provider::class,
            new \mod_confscheduler\privacy\provider()
        );
    }

    /**
     * confscheduler_supports() answers sensibly for the core feature constants used.
     */
    public function test_supports_returns_expected_values(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/confscheduler/lib.php');

        $this->assertTrue(confscheduler_supports(FEATURE_MOD_INTRO));
        $this->assertFalse(confscheduler_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertSame(MOD_PURPOSE_OTHER, confscheduler_supports(FEATURE_MOD_PURPOSE));
        $this->assertNull(confscheduler_supports('some_unknown_feature'));
    }

    /**
     * confscheduler_delete_instance() cascades confscheduler_slotroom ->
     * confscheduler_slot -> confscheduler_room, and removes the instance itself.
     */
    public function test_delete_instance_cascades(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/confscheduler/lib.php');

        $this->resetAfterTest();

        [$course, $confprogramcm] = $this->create_course_with_confprogram();

        $confscheduler = $this->getDataGenerator()->create_module('confscheduler', [
            'course'          => $course->id,
            'confprogramcmid' => $confprogramcm->id,
        ]);

        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        // Inserted directly via $DB rather than through api::add_slot(): this test is only
        // about confscheduler_delete_instance()'s cascade, not about the submissionid=42
        // chain-of-custody validation add_slot() now enforces (see api_test.php for that).
        $now = time();
        $slotid = $DB->insert_record('confscheduler_slot', (object) [
            'confscheduler' => $confscheduler->id,
            'submissionid'  => 42,
            'label'         => null,
            'starttime'     => strtotime('2026-09-01 10:00:00'),
            'endtime'       => strtotime('2026-09-01 10:30:00'),
            'timecreated'   => $now,
            'timemodified'  => $now,
        ]);
        $DB->insert_record('confscheduler_slotroom', (object) ['slotid' => $slotid, 'roomid' => $roomid]);

        $this->assertTrue($DB->record_exists('confscheduler_slotroom', ['slotid' => $slotid]));

        confscheduler_delete_instance($confscheduler->id);

        $this->assertFalse($DB->record_exists('confscheduler', ['id' => $confscheduler->id]));
        $this->assertFalse($DB->record_exists('confscheduler_room', ['confscheduler' => $confscheduler->id]));
        $this->assertFalse($DB->record_exists('confscheduler_slot', ['confscheduler' => $confscheduler->id]));
        $this->assertFalse($DB->record_exists('confscheduler_slotroom', ['slotid' => $slotid]));
    }
}
