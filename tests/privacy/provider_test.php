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

namespace mod_confscheduler\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use mod_confscheduler\api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the mod_confscheduler privacy provider (2026-10-28 security checklist
 * audit, F5): the declared metadata and the export of the per-instance
 * last-viewed-day user preference.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /**
     * Creates a confscheduler instance linked to a confprogram/confsubmissions chain.
     *
     * @return \stdClass The confscheduler record
     */
    protected function create_confscheduler(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $confsubmissions = $this->getDataGenerator()->create_module('confsubmissions', ['course' => $course->id]);
        $confsubmissionscm = get_coursemodule_from_instance('confsubmissions', $confsubmissions->id);
        $confprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $course->id,
            'confsubmissionscmid' => $confsubmissionscm->id,
        ]);
        $confprogramcm = get_coursemodule_from_instance('confprogram', $confprogram->id);

        return $this->getDataGenerator()->create_module('confscheduler', [
            'course'          => $course->id,
            'confprogramcmid' => $confprogramcm->id,
        ]);
    }

    /**
     * get_metadata() declares exactly the one user preference, with a lang string
     * that exists.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('mod_confscheduler'));
        $items = $collection->get_collection();

        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertInstanceOf(\core_privacy\local\metadata\types\user_preference::class, $item);
        $this->assertSame('mod_confscheduler_lastday', $item->get_name());
        $this->assertTrue(get_string_manager()->string_exists($item->get_summary(), 'mod_confscheduler'));
    }

    /**
     * export_user_preferences() exports each of the user's last-viewed-day
     * preferences (one per instance), and never another user's.
     */
    public function test_export_user_preferences(): void {
        $this->resetAfterTest();

        $first = $this->create_confscheduler();
        $second = $this->create_confscheduler();
        $user = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();

        api::set_last_viewed_day((int) $first->id, (int) $user->id, 'all');
        api::set_last_viewed_day((int) $second->id, (int) $user->id, 'all');
        api::set_last_viewed_day((int) $first->id, (int) $otheruser->id, 'all');

        provider::export_user_preferences((int) $user->id);

        $writer = writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data());
        $prefs = (array) $writer->get_user_context_preferences('mod_confscheduler');
        $this->assertCount(2, $prefs);
        $this->assertArrayHasKey(api::last_viewed_day_preference_name((int) $first->id), $prefs);
        $this->assertArrayHasKey(api::last_viewed_day_preference_name((int) $second->id), $prefs);
    }

    /**
     * A user who never used the day selector gets nothing exported.
     */
    public function test_export_user_preferences_none(): void {
        $this->resetAfterTest();

        $this->create_confscheduler();
        $user = $this->getDataGenerator()->create_user();

        provider::export_user_preferences((int) $user->id);

        $writer = writer::with_context(\context_system::instance());
        $this->assertFalse($writer->has_any_data());
    }
}
