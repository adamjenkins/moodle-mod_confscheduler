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

namespace mod_confscheduler\backup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');
require_once($CFG->dirroot . '/mod/confscheduler/backup/moodle2/restore_confscheduler_stepslib.php');

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Backup/restore tests for mod_confscheduler (user request, 2026-07-06: "Also make sure
 * backup/restore/reset all works fine with all plugins").
 *
 * Exercises the full three-plugin chain (confsubmissions -> confprogram ->
 * confscheduler) in one course backup/restore, since this is the plugin furthest down
 * the dependency chain: confprogramcmid and slot.submissionid must both resolve to the
 * restored copies, and a room's own id must be correctly remapped for a slot's
 * confscheduler_slotroom junction row.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\restore_confscheduler_activity_structure_step::class)]
final class restore_confscheduler_test extends \restore_date_testcase {
    /**
     * A full backup/restore round-trip correctly reconstructs a confscheduler
     * instance's rooms and schedule, with confprogramcmid and every presentation
     * slot's submissionid pointing at the restored copies, not the originals, and a
     * slot's room assignment pointing at its own restored room.
     */
    public function test_backup_and_restore_remaps_cross_activity_references(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['startdate' => $this->startdate]);

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
            'defaultdateview' => 'all',
            'rememberlastday' => 1,
        ]);
        \mod_confscheduler\api::set_day_bounds((int) $confscheduler->id, 480, 1080);

        $speaker = $this->getDataGenerator()->create_user();
        $decider = $this->getDataGenerator()->create_user();
        $now = time();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $speaker->id,
            'title'           => 'A Test Talk',
            'abstract'        => 'Abstract text',
            'status'          => 'submitted',
            'timecreated'     => $now,
            'timemodified'    => $now,
        ]);
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        $roomid = \mod_confscheduler\api::add_room((int) $confscheduler->id, 'Main Hall', null, '#3366cc', 100);
        $slotid = \mod_confscheduler\api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            $this->startdate + (9 * HOURSECS),
            $this->startdate + (9 * HOURSECS) + (30 * MINSECS),
            $submissionid
        );
        // A column-spanning block (no submissionid) -- should always travel too.
        \mod_confscheduler\api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            $this->startdate + (12 * HOURSECS),
            $this->startdate + (13 * HOURSECS),
            null,
            'Lunch'
        );

        $newcourseid = $this->backup_and_restore($course);

        $newconfsubmissions = $DB->get_record('confsubmissions', ['course' => $newcourseid], '*', MUST_EXIST);
        $newconfprogram = $DB->get_record('confprogram', ['course' => $newcourseid], '*', MUST_EXIST);
        $newconfprogramcm = get_coursemodule_from_instance('confprogram', $newconfprogram->id);
        $newconfscheduler = $DB->get_record('confscheduler', ['course' => $newcourseid], '*', MUST_EXIST);
        $newsubmission = $DB->get_record(
            'confsubmissions_submission',
            ['confsubmissions' => $newconfsubmissions->id],
            '*',
            MUST_EXIST
        );

        // The critical cross-activity checks.
        $this->assertSame((int) $newconfprogramcm->id, (int) $newconfscheduler->confprogramcmid);

        // Plain scalar settings fields (defaultdateview/rememberlastday, added
        // 2026-07-07, and daystart/dayend -- a pre-existing gap in the backup field
        // list fixed alongside, since both belong to this same "instance settings"
        // group) must all survive the round-trip untouched, same as any other
        // instance-level setting.
        $this->assertSame('all', $newconfscheduler->defaultdateview);
        $this->assertSame(1, (int) $newconfscheduler->rememberlastday);
        $this->assertSame(480, (int) $newconfscheduler->daystart);
        $this->assertSame(1080, (int) $newconfscheduler->dayend);

        $newroom = $DB->get_record('confscheduler_room', ['confscheduler' => $newconfscheduler->id], '*', MUST_EXIST);
        $this->assertSame('Main Hall', $newroom->name);
        $this->assertSame(100, (int) $newroom->capacity);

        $newslots = $DB->get_records('confscheduler_slot', ['confscheduler' => $newconfscheduler->id], 'starttime ASC');
        $this->assertCount(2, $newslots);
        $newslots = array_values($newslots);

        $this->assertSame((int) $newsubmission->id, (int) $newslots[0]->submissionid);
        $this->assertNull($newslots[1]->submissionid);
        $this->assertSame('Lunch', $newslots[1]->label);

        // The slot's room assignment must point at the RESTORED room, not the original.
        $newslotroom = $DB->get_record('confscheduler_slotroom', ['slotid' => $newslots[0]->id], '*', MUST_EXIST);
        $this->assertSame((int) $newroom->id, (int) $newslotroom->roomid);
        $this->assertNotSame($roomid, (int) $newslotroom->roomid);
    }

    /**
     * A container span block and its nested child both survive a backup/
     * restore round trip, with the child's parentslotid remapped to the
     * restored container's new id (user request, 2026-07-08 -- poster/keynote
     * container blocks).
     */
    public function test_backup_and_restore_remaps_container_parentslotid(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['startdate' => $this->startdate]);

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

        $speaker = $this->getDataGenerator()->create_user();
        $decider = $this->getDataGenerator()->create_user();
        $now = time();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $speaker->id,
            'title'           => 'A Poster',
            'abstract'        => 'Abstract text',
            'status'          => 'submitted',
            'timecreated'     => $now,
            'timemodified'    => $now,
        ]);
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        $roomid = \mod_confscheduler\api::add_room((int) $confscheduler->id, 'Main Hall');
        $containerid = \mod_confscheduler\api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            $this->startdate + (9 * HOURSECS),
            $this->startdate + (11 * HOURSECS),
            null,
            'Poster Session',
            null,
            true,
            'Exhibit Hall'
        );
        $childid = \mod_confscheduler\api::add_presentation_to_container(
            (int) $confscheduler->id,
            $containerid,
            $submissionid
        );

        $newcourseid = $this->backup_and_restore($course);

        $newconfscheduler = $DB->get_record('confscheduler', ['course' => $newcourseid], '*', MUST_EXIST);
        $newslots = $DB->get_records('confscheduler_slot', ['confscheduler' => $newconfscheduler->id], 'starttime ASC');
        $this->assertCount(2, $newslots);
        $newslots = array_values($newslots);

        $newcontainer = null;
        $newchild = null;
        foreach ($newslots as $slot) {
            if ($slot->submissionid === null) {
                $newcontainer = $slot;
            } else {
                $newchild = $slot;
            }
        }

        $this->assertNotNull($newcontainer);
        $this->assertNotNull($newchild);
        $this->assertSame(1, (int) $newcontainer->iscontainer);
        $this->assertSame('Exhibit Hall', $newcontainer->roomnameoverride);
        $this->assertSame((int) $newcontainer->id, (int) $newchild->parentslotid);
        $this->assertNotSame($containerid, (int) $newcontainer->id);
        $this->assertNotSame($childid, (int) $newchild->id);
    }
}
