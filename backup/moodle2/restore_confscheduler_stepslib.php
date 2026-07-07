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

/**
 * Defines the restore structure for mod_confscheduler.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one confscheduler activity.
 *
 * confprogramcmid and every presentation slot's submissionid are cross-activity
 * references into sibling plugins (mod_confprogram, mod_confsubmissions) in the same
 * course backup -- NOT values this step can resolve during its own main structure
 * processing, since restore does not guarantee those siblings have already been
 * restored by the time this step's process_*() methods run (activities are restored in
 * whatever order the backup file lists them, not in dependency order). Every activity's
 * main structure step completes before ANY activity's after_restore() runs, so that IS
 * the safe place to resolve them -- this class inserts every affected row with its OLD
 * (unmapped) cross-activity value during the main pass, then fixes them all up in
 * after_restore() below.
 */
class restore_confscheduler_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the confscheduler activity structure for restore.
     *
     * @return array The restore_path_element[] paths, wrapped into standard activity structure
     */
    protected function define_structure() {
        $paths = [];

        $paths[] = new restore_path_element('confscheduler', '/activity/confscheduler');
        $paths[] = new restore_path_element('confscheduler_room', '/activity/confscheduler/rooms/room');
        $paths[] = new restore_path_element(
            'confscheduler_notiftemplate',
            '/activity/confscheduler/notiftemplates/notiftemplate'
        );
        $paths[] = new restore_path_element(
            'confscheduler_daybounds',
            '/activity/confscheduler/daybounds/daybound'
        );
        $paths[] = new restore_path_element('confscheduler_slot', '/activity/confscheduler/slots/slot');
        $paths[] = new restore_path_element(
            'confscheduler_slotroom',
            '/activity/confscheduler/slots/slot/slotrooms/slotroom'
        );

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores the main confscheduler instance record. confprogramcmid is left as its
     * old (unmapped) value here -- see this class's docblock -- and corrected in
     * after_restore().
     *
     * @param array|stdClass $data The parsed confscheduler element
     * @return void
     */
    protected function process_confscheduler($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();

        if ($data->conferencestart !== null) {
            $data->conferencestart = $this->apply_date_offset($data->conferencestart);
        }
        if ($data->conferenceend !== null) {
            $data->conferenceend = $this->apply_date_offset($data->conferenceend);
        }

        $newitemid = $DB->insert_record('confscheduler', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restores a room, and records its old-to-new id mapping for
     * process_confscheduler_slotroom() to resolve roomid against.
     *
     * @param array|stdClass $data The parsed room element
     * @return void
     */
    protected function process_confscheduler_room($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->confscheduler = $this->get_new_parentid('confscheduler');

        $newitemid = $DB->insert_record('confscheduler_room', $data);
        $this->set_mapping('confscheduler_room', $oldid, $newitemid);
    }

    /**
     * Restores a notification template.
     *
     * @param array|stdClass $data The parsed notiftemplate element
     * @return void
     */
    protected function process_confscheduler_notiftemplate($data) {
        global $DB;

        $data = (object) $data;
        $data->confscheduler = $this->get_new_parentid('confscheduler');

        $DB->insert_record('confscheduler_notiftemplate', $data);
    }

    /**
     * Restores a per-day display-window override. The 'day' is a Y-m-d key, not a
     * timestamp, so it is not date-offset -- an overridden window applies to the same
     * calendar-day label regardless of when the course is restored (see this feature's
     * install.xml table comment for why the day is stored as a key, not a timestamp).
     *
     * @param array|stdClass $data The parsed daybound element
     * @return void
     */
    protected function process_confscheduler_daybounds($data) {
        global $DB;

        $data = (object) $data;
        $data->confscheduler = $this->get_new_parentid('confscheduler');

        $DB->insert_record('confscheduler_daybounds', $data);
    }

    /**
     * Restores a scheduled slot (presentation or column-spanning block). submissionid
     * (when set) is left as its old (unmapped) value here -- see this class's docblock
     * -- and corrected in after_restore(). Dates are date-offset the same way
     * mod_choice's timeopen/timeclose are, since a slot's start/end time is exactly the
     * kind of scheduled date that should shift along with a course's start date.
     *
     * Records its own old-to-new id mapping -- required for
     * process_confscheduler_slotroom() to resolve its parent slot via
     * get_new_parentid('confscheduler_slot'), since (unlike the activity's own root
     * element, wired up automatically by apply_activity_instance()) nothing else sets
     * this mapping.
     *
     * @param array|stdClass $data The parsed slot element
     * @return void
     */
    protected function process_confscheduler_slot($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->confscheduler = $this->get_new_parentid('confscheduler');
        $data->starttime = $this->apply_date_offset($data->starttime);
        $data->endtime = $this->apply_date_offset($data->endtime);

        $newitemid = $DB->insert_record('confscheduler_slot', $data);
        $this->set_mapping('confscheduler_slot', $oldid, $newitemid);
    }

    /**
     * Restores a slot's room assignment.
     *
     * @param array|stdClass $data The parsed slotroom element
     * @return void
     */
    protected function process_confscheduler_slotroom($data) {
        global $DB;

        $data = (object) $data;
        $data->slotid = $this->get_new_parentid('confscheduler_slot');
        $newroomid = $this->get_mappingid('confscheduler_room', $data->roomid);
        if (!$newroomid) {
            // The room this slot occupies wasn't included in this backup/restore (should
            // not normally happen -- rooms are always backed up alongside slots -- but
            // defensively skip rather than insert a NOTNULL roomid pointing at an
            // unrelated room that happens to share the old numeric id).
            return;
        }
        $data->roomid = $newroomid;

        $DB->insert_record('confscheduler_slotroom', $data);
    }

    /**
     * Restores files attached to the confscheduler intro.
     *
     * @return void
     */
    protected function after_execute() {
        $this->add_related_files('mod_confscheduler', 'intro', null);
    }

    /**
     * Fixes up every cross-activity reference into mod_confprogram/mod_confsubmissions
     * now that ALL activities in this course restore have completed their main
     * structure step (see this class's docblock for why this can only safely happen
     * here).
     *
     * @return void
     */
    protected function after_restore() {
        global $DB;

        $confschedulerid = $this->task->get_activityid();

        $confscheduler = $DB->get_record('confscheduler', ['id' => $confschedulerid], '*', MUST_EXIST);
        $newcmid = $this->get_mappingid('course_module', $confscheduler->confprogramcmid);
        // 0 (rather than leaving the old, no-longer-relevant cmid) when the linked
        // mod_confprogram instance wasn't included in this backup/restore --
        // deliberately a visibly BROKEN state (every page here MUST_EXIST-resolves this
        // cmid) rather than a silently WRONG one pointing at an unrelated activity that
        // happens to share the old numeric id in the destination site. An organiser must
        // re-link the setting to recover.
        $DB->set_field('confscheduler', 'confprogramcmid', $newcmid ?: 0, ['id' => $confschedulerid]);

        $slots = $DB->get_records_select(
            'confscheduler_slot',
            'confscheduler = :confscheduler AND submissionid IS NOT NULL',
            ['confscheduler' => $confschedulerid]
        );
        foreach ($slots as $slot) {
            $newsubmissionid = $this->get_mappingid('confsubmissions_submission', $slot->submissionid);
            if ($newsubmissionid) {
                $DB->set_field('confscheduler_slot', 'submissionid', $newsubmissionid, ['id' => $slot->id]);
            } else {
                // The submission this presentation slot references wasn't included in
                // this backup/restore -- delete the slot (and its room assignment(s))
                // rather than leave a presentation slot with a submissionid silently
                // pointing at an unrelated submission that happens to share the old
                // numeric id. Nulling submissionid instead would misrepresent it as a
                // column-spanning block, which it is not.
                $DB->delete_records('confscheduler_slotroom', ['slotid' => $slot->id]);
                $DB->delete_records('confscheduler_slot', ['id' => $slot->id]);
            }
        }
    }
}
