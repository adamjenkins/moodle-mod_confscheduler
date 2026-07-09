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
 * Defines the backup structure for mod_confscheduler.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the complete confscheduler structure for backup, with file annotations.
 *
 * Unlike the sibling plugins in this suite, NOTHING here is gated on the 'userinfo'
 * backup setting: no table in this plugin's schema stores a userid or any other
 * personal data of its own. The schedule itself (rooms, slots, room assignments) is
 * course CONTENT -- structurally more like a course's own assignment due dates than
 * like a submitted answer -- not personal data about who did what, so it is always
 * included, the same way `mod_choice`'s `choice_options` always are. The submissionid a
 * presentation slot carries is a reference to another activity's CONTENT (a
 * presentation), not to a user directly.
 *
 * confprogramcmid (on the main table) and every presentation slot's submissionid are
 * cross-activity references into sibling plugins in the same course -- see
 * restore_confscheduler_stepslib.php's docblock for why remapping them must happen in
 * after_restore(), not here or in restore's own process_*() methods.
 */
class backup_confscheduler_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the confscheduler activity structure for backup.
     *
     * @return backup_nested_element The root element, wrapped into standard activity structure
     */
    protected function define_structure() {
        $confscheduler = new backup_nested_element('confscheduler', ['id'], [
            'name', 'intro', 'introformat', 'confprogramcmid', 'conferencestart',
            'conferenceend', 'gapminutes', 'pxperhour', 'notificationsenabled',
            'daystart', 'dayend', 'defaultdateview', 'rememberlastday',
            'timecreated', 'timemodified',
        ]);

        $rooms = new backup_nested_element('rooms');
        $room = new backup_nested_element('room', ['id'], [
            'name', 'sortorder', 'colour', 'capacity',
        ]);

        $notiftemplates = new backup_nested_element('notiftemplates');
        $notiftemplate = new backup_nested_element('notiftemplate', ['id'], [
            'notiftype', 'subject', 'body', 'bodyformat', 'timecreated', 'timemodified',
        ]);

        $daybounds = new backup_nested_element('daybounds');
        $daybound = new backup_nested_element('daybound', ['id'], [
            'day', 'daystart', 'dayend',
        ]);

        $slots = new backup_nested_element('slots');
        $slot = new backup_nested_element('slot', ['id'], [
            'submissionid', 'label', 'colour', 'roomnameoverride', 'starttime', 'endtime', 'iscontainer',
            'parentslotid', 'childtextalign', 'childtextvalign', 'timecreated', 'timemodified', 'notifiedtime',
        ]);

        $slotrooms = new backup_nested_element('slotrooms');
        $slotroom = new backup_nested_element('slotroom', ['id'], [
            'roomid',
        ]);

        // Build the tree.
        $confscheduler->add_child($rooms);
        $rooms->add_child($room);

        $confscheduler->add_child($notiftemplates);
        $notiftemplates->add_child($notiftemplate);

        $confscheduler->add_child($daybounds);
        $daybounds->add_child($daybound);

        $confscheduler->add_child($slots);
        $slots->add_child($slot);

        $slot->add_child($slotrooms);
        $slotrooms->add_child($slotroom);

        // Define sources.
        $confscheduler->set_source_table('confscheduler', ['id' => backup::VAR_ACTIVITYID]);
        $room->set_source_table('confscheduler_room', ['confscheduler' => backup::VAR_PARENTID], 'sortorder ASC');
        $notiftemplate->set_source_table('confscheduler_notiftemplate', ['confscheduler' => backup::VAR_PARENTID]);
        $daybound->set_source_table('confscheduler_daybounds', ['confscheduler' => backup::VAR_PARENTID], 'day ASC');
        $slot->set_source_table('confscheduler_slot', ['confscheduler' => backup::VAR_PARENTID], 'starttime ASC');
        // Roomid is a cross-reference to a room ELSEWHERE in this same activity's own
        // structure (a room a slot occupies) -- restore remaps it via the
        // 'confscheduler_room' mapping set when that room is restored, same pattern as
        // mod_confsubmissions's trackid/submissiontypeid.
        $slotroom->set_source_table('confscheduler_slotroom', ['slotid' => backup::VAR_PARENTID]);

        // Define file annotations.
        $confscheduler->annotate_files('mod_confscheduler', 'intro', null);

        // Return the root element, wrapped into standard activity structure.
        return $this->prepare_activity_structure($confscheduler);
    }
}
