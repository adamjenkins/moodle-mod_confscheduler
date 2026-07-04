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

    return true;
}
