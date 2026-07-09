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

use core_privacy\local\metadata\collection;

/**
 * Privacy provider for mod_confscheduler.
 *
 * This plugin's own TABLES store no personal data:
 * - confscheduler: activity instance settings only (confprogramcmid, gapminutes,
 *   pxperhour, notificationsenabled, daystart, dayend).
 * - confscheduler_room: a room/column name, sortorder and colour, none of which are
 *   personal data.
 * - confscheduler_slot: a scheduled block's time range and either a submissionid
 *   (a cross-plugin reference into mod_confsubmissions's confsubmissions_submission
 *   table, which is that plugin's privacy responsibility, not personal data owned
 *   here) or a label for a span-block (e.g. "Lunch Break").
 * - confscheduler_slotroom: a junction of slotid/roomid only.
 * - confscheduler_daybounds: a per-conference-day display-window override (a day key
 *   and two times-of-day), instance configuration with no personal data.
 *
 * None of these rows carry a userid, or any other column identifying an individual.
 * The "my timetable" favourite state (mod/confscheduler:favourite) is written
 * directly into mod_confprogram's own confprogram_favourite table via that
 * plugin's api::add_favourite()/remove_favourite() (see db/access.php); this
 * plugin never stores that data itself, so it is mod_confprogram's privacy
 * responsibility, covered by \mod_confprogram\privacy\provider.
 *
 * The plugin DOES store one piece of per-user data outside its tables: the
 * mod_confscheduler_lastday_<instanceid> USER PREFERENCE, written by
 * api::set_last_viewed_day() whenever a user changes the Display-mode day
 * selector on an instance with rememberlastday enabled. That behavioural data
 * is declared and exported here (this class was previously a null_provider,
 * added before the preference existed — FABLE.md review, 2026-07-09).
 * Deletion is handled by core automatically when a user is deleted (all
 * user_preferences rows go), and per-instance cleanup happens in
 * confscheduler_delete_instance() (lib.php).
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Declares the one user preference this plugin stores.
     *
     * @param collection $collection The collection to add to
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_user_preference(
            'mod_confscheduler_lastday',
            'privacy:metadata:preference:lastday'
        );

        return $collection;
    }

    /**
     * Exports the user's remembered last-viewed-day preferences (one per
     * confscheduler instance they have used the day selector on).
     *
     * @param int $userid The user to export for
     * @return void
     */
    public static function export_user_preferences(int $userid) {
        global $DB;

        $prefs = $DB->get_records_select(
            'user_preferences',
            'userid = :userid AND ' . $DB->sql_like('name', ':name'),
            ['userid' => $userid, 'name' => 'mod_confscheduler_lastday_%']
        );

        foreach ($prefs as $pref) {
            \core_privacy\local\request\writer::export_user_preference(
                'mod_confscheduler',
                $pref->name,
                $pref->value,
                get_string('privacy:metadata:preference:lastday', 'mod_confscheduler')
            );
        }
    }
}
