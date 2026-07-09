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
 * Upgrade steps for mod_confscheduler.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs upgrade steps between versions.
 *
 * @param int $oldversion Plugin version being upgraded from
 * @return bool
 */
function xmldb_confscheduler_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026070402) {
        // Phase 3.4 (Autoscheduler): confscheduler_sessiontag, the plugin-local
        // "session" grouping table. See db/install.xml's table comment and
        // README.md for why this is plugin-local rather than a change to the
        // sibling mod_confsubmissions/mod_confprogram schemas.
        $table = new xmldb_table('confscheduler_sessiontag');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('confscheduler', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('confscheduler', XMLDB_KEY_FOREIGN, ['confscheduler'], 'confscheduler', ['id']);

        $table->add_index('confschedulersubmission', XMLDB_INDEX_UNIQUE, ['confscheduler', 'submissionid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026070402, 'confscheduler');
    }

    if ($oldversion < 2026070404) {
        // Revision round 1 (2026-07-03): organiser-set conference start/end dates,
        // settable in mod_form.php's General section. Nullable: not currently derived
        // from or validated against any scheduled slot, purely an explicit setting.
        $table = new xmldb_table('confscheduler');

        $field = new xmldb_field('conferencestart', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'confprogramcmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('conferenceend', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'conferencestart');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070404, 'confscheduler');
    }

    if ($oldversion < 2026070405) {
        // Revision round 1 (2026-07-03): span-block colour picker. Nullable hex colour,
        // same type/length as confscheduler_room.colour, applying only to label-only
        // span-block slots (submissionid IS NULL) -- enforced in classes/api.php, not at
        // the schema level (the column exists on every slot row, but is only ever
        // written for span blocks).
        $table = new xmldb_table('confscheduler_slot');

        $field = new xmldb_field('colour', XMLDB_TYPE_CHAR, '7', null, null, null, null, 'label');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070405, 'confscheduler');
    }

    if ($oldversion < 2026070406) {
        // Revision round 1 batch B (2026-07-03): the "session" tagging feature is removed
        // entirely, per explicit user feedback ("In the unscheduled blocks there is a
        // 'session' setting, this should be removed"). This is a genuine schema removal,
        // not merely a stop-using: confscheduler_sessiontag is dropped outright, along with
        // api::set_session_tag()/get_session_tags(), the mod_confscheduler_set_session_tag
        // AJAX endpoint, the inline session-tag input in the unscheduled panel, and the
        // autoscheduler's former priority-1 "same-session-tag consecutive-same-room" tier
        // (see api.php's run_autoscheduler() docblock, now documenting two priority tiers
        // instead of three). See changelog.md for the full removal list.
        $table = new xmldb_table('confscheduler_sessiontag');

        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        upgrade_mod_savepoint(true, 2026070406, 'confscheduler');
    }

    if ($oldversion < 2026070407) {
        // No schema change here: this savepoint exists purely to make sure the upgrade
        // pipeline runs, which is what registers the new mod_confscheduler_set_gap_minutes
        // external function declared in db/services.php (Revision round 1 follow-up,
        // 2026-07-04 -- the SnapGap minimum gap setting moved from mod_form.php to a quick
        // control at the top of the schedule grid in edit mode).
        upgrade_mod_savepoint(true, 2026070407, 'confscheduler');
    }

    if ($oldversion < 2026070408) {
        // Row-height setting (user feedback, 2026-07-05): an organiser-adjustable "pixels
        // per hour" density for the grid/display timeline, following the same pattern as
        // gapminutes above -- a quick control at the top of the schedule grid in edit mode,
        // not a mod_form.php field. Defaults to 144 (the previously hard-coded value, so
        // existing instances render identically until an organiser changes it).
        $table = new xmldb_table('confscheduler');

        $field = new xmldb_field('pxperhour', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '144', 'gapminutes');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070408, 'confscheduler');
    }

    if ($oldversion < 2026070508) {
        // Room-capacity field + overbooking warnings (user request, 2026-07-05): a
        // room's capacity is compared, edit-mode only, against a scheduled
        // presentation's mod_confprogram favourite count.
        $table = new xmldb_table('confscheduler_room');
        $field = new xmldb_field('capacity', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'colour');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070508, 'confscheduler');
    }

    if ($oldversion < 2026070608) {
        // Manual "send notifications" button for schedule changes (user request,
        // 2026-07-05): notifiedtime tracks, per presentation slot, when a
        // schedule-change notification was last sent, compared against the
        // existing timemodified to decide whether a slot has an un-notified change
        // pending. confscheduler_notiftemplate is the organiser-editable template,
        // same shape/conventions as mod_confprogram_notiftemplate.
        $table = new xmldb_table('confscheduler_slot');
        $field = new xmldb_field('notifiedtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('confscheduler_notiftemplate');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('confscheduler', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('notiftype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('subject', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('body', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('bodyformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('confscheduler', XMLDB_KEY_FOREIGN, ['confscheduler'], 'confscheduler', ['id']);

            $table->add_index('confschedulertype', XMLDB_INDEX_UNIQUE, ['confscheduler', 'notiftype']);

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026070608, 'confscheduler');
    }

    if ($oldversion < 2026070609) {
        // Notifications master switch (user request, 2026-07-06): a single
        // instance-level on/off toggle that overrides the schedule-change
        // notification template. Defaults to 1 (enabled) so existing instances
        // keep sending exactly as they do today until an organiser explicitly
        // turns it off.
        $table = new xmldb_table('confscheduler');
        $field = new xmldb_field('notificationsenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'pxperhour');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070609, 'confscheduler');
    }

    if ($oldversion < 2026070613) {
        // Day start/end display-window quick control (user request, 2026-07-06):
        // both null means fully automatic (derive the axis from scheduled slots, as
        // before this feature existed) -- zero behaviour change for existing
        // instances until an organiser explicitly configures a daily display window.
        $table = new xmldb_table('confscheduler');

        $field = new xmldb_field('daystart', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'notificationsenabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('dayend', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'daystart');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070613, 'confscheduler');
    }

    if ($oldversion < 2026070700) {
        // Per-day display-window overrides (user request, 2026-07-07): the daily display
        // window ("Day start"/"Day end") is now settable per conference day, since these
        // often differ day to day. A day without a row here inherits the instance-level
        // daystart/dayend, which keeps existing instances behaving exactly as before.
        $table = new xmldb_table('confscheduler_daybounds');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('confscheduler', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('day', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('daystart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('dayend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('confscheduler', XMLDB_KEY_FOREIGN, ['confscheduler'], 'confscheduler', ['id']);
            $table->add_index('confschedulerday', XMLDB_INDEX_UNIQUE, ['confscheduler', 'day']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026070700, 'confscheduler');
    }

    if ($oldversion < 2026070702) {
        // Default date view + "remember last viewed day" (user request, 2026-07-07):
        // defaultdateview controls which day a first-time/no-preference viewer's
        // Display-mode day selector starts on ('closest', the previous behaviour, or
        // 'all'); rememberlastday is a master switch for a per-user last-viewed-day
        // preference (see classes/api.php::get_last_viewed_day()/set_last_viewed_day())
        // taking precedence over defaultdateview when set. Both default to the
        // previous behaviour (closest day, no remembering) so existing instances are
        // unaffected until an organiser opts in.
        $table = new xmldb_table('confscheduler');

        $field = new xmldb_field('defaultdateview', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'closest', 'dayend');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field(
            'rememberlastday',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'defaultdateview'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070702, 'confscheduler');
    }

    if ($oldversion < 2026070801) {
        $table = new xmldb_table('confscheduler_slot');

        $field = new xmldb_field('roomnameoverride', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'colour');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('iscontainer', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'endtime');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('parentslotid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'iscontainer');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('parentslotid', XMLDB_INDEX_NOTUNIQUE, ['parentslotid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2026070801, 'confscheduler');
    }

    if ($oldversion < 2026070802) {
        // No schema change here: this savepoint exists purely to make sure the upgrade
        // pipeline runs, which is what registers the new mod_confscheduler_add_to_container
        // external function declared in db/services.php (container-blocks feature, Task 9,
        // 2026-07-08 -- nesting an accepted-but-unscheduled presentation inside a container
        // span block). Same pattern as 2026070407 above for set_gap_minutes.
        upgrade_mod_savepoint(true, 2026070802, 'confscheduler');
    }

    if ($oldversion < 2026070803) {
        $table = new xmldb_table('confscheduler_slot');

        $field = new xmldb_field('childtextalign', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'left', 'parentslotid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('childtextvalign', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'top', 'childtextalign');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070803, 'confscheduler');
    }

    if ($oldversion < 2026070901) {
        // Notifications master switch default flipped 1 -> 0 (user request,
        // 2026-07-09): new instances now default to notifications OFF. Existing
        // instances' stored notificationsenabled values are deliberately left
        // untouched -- only the column's default (used by the next INSERT with
        // no explicit value) changes.
        $table = new xmldb_table('confscheduler');
        $field = new xmldb_field(
            'notificationsenabled',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'pxperhour'
        );
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_default($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070901, 'confscheduler');
    }

    return true;
}
