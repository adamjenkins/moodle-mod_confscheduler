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
 * No upgrade steps exist yet: this is the initial scaffold release. Add
 * xmldb_table/xmldb_field steps here, each guarded by "if ($oldversion < ...)"
 * and closed with a matching upgrade_mod_savepoint() call, as the schema
 * evolves. Example:
 *
 *   if ($oldversion < 2026080100) {
 *       global $DB;
 *       $dbman = $DB->get_manager();
 *       // ... xmldb_table / xmldb_field changes ...
 *       upgrade_mod_savepoint(true, 2026080100, 'confscheduler');
 *   }
 *
 * @param int $oldversion Plugin version being upgraded from
 * @return bool
 */
function xmldb_confscheduler_upgrade($oldversion) {
    return true;
}
