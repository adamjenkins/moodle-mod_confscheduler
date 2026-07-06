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

namespace mod_confscheduler\privacy;

/**
 * Privacy provider for mod_confscheduler.
 *
 * This plugin's own tables store no personal data:
 * - confscheduler: activity instance settings only (confprogramcmid, gapminutes,
 *   pxperhour, notificationsenabled, daystart, dayend).
 * - confscheduler_room: a room/column name, sortorder and colour, none of which are
 *   personal data.
 * - confscheduler_slot: a scheduled block's time range and either a submissionid
 *   (a cross-plugin reference into mod_confsubmissions's confsubmissions_submission
 *   table, which is that plugin's privacy responsibility, not personal data owned
 *   here) or a label for a span-block (e.g. "Lunch Break").
 * - confscheduler_slotroom: a junction of slotid/roomid only.
 *
 * None of these rows carry a userid, or any other column identifying an individual.
 * The "my timetable" favourite state (mod/confscheduler:favourite) is written
 * directly into mod_confprogram's own confprogram_favourite table via that
 * plugin's api::add_favourite()/remove_favourite() (see db/access.php); this
 * plugin never stores that data itself, so it is mod_confprogram's privacy
 * responsibility, covered by \mod_confprogram\privacy\provider.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Returns the language string key explaining why this plugin has no
     * personal data to export/delete.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
