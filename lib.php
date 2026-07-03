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
 * Library functions for mod_confscheduler.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Returns the features this module supports.
 *
 * FEATURE_BACKUP_MOODLE2 is deliberately not claimed yet: no backup/restore
 * steps have been written for this plugin's tables, and this plugin also
 * depends on a course containing a mod_confprogram instance (referenced by
 * confprogramcmid), which complicates backup/restore further. Add the
 * backup/restore steplibs before flipping this to true.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function confscheduler_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO        => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_BACKUP_MOODLE2   => false, // Not yet implemented; set true once backup/restore steps exist.
        FEATURE_GRADE_HAS_GRADE  => false,
        FEATURE_MOD_PURPOSE      => MOD_PURPOSE_OTHER,
        default                  => null,
    };
}

/**
 * Adds a new instance of the confscheduler activity.
 *
 * @param stdClass $data Data from the settings form
 * @param mod_confscheduler_mod_form|null $form The form instance
 * @return int The id of the newly inserted record
 */
function confscheduler_add_instance(stdClass $data, ?mod_confscheduler_mod_form $form = null) {
    global $DB;

    $data->timecreated  = time();
    $data->timemodified = $data->timecreated;

    if (!isset($data->intro)) {
        $data->intro = '';
    }
    if (!isset($data->introformat)) {
        $data->introformat = FORMAT_HTML;
    }
    if (!isset($data->gapminutes)) {
        $data->gapminutes = 0;
    }

    confscheduler_normalise_conference_dates($data);

    return $DB->insert_record('confscheduler', $data);
}

/**
 * Updates an existing instance of the confscheduler activity.
 *
 * @param stdClass $data Data from the settings form
 * @param mod_confscheduler_mod_form|null $form The form instance
 * @return bool
 */
function confscheduler_update_instance(stdClass $data, ?mod_confscheduler_mod_form $form = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    confscheduler_normalise_conference_dates($data);

    return $DB->update_record('confscheduler', $data);
}

/**
 * Normalises the optional conference start/end date_time_selector fields: that element
 * yields 0 (not null) when its "enable" checkbox is unchecked, but
 * confscheduler.conferencestart/conferenceend are nullable columns (Revision round 1,
 * 2026-07-03) -- an explicit "not set" is represented as null, not the timestamp 0.
 *
 * @param stdClass $data Data from the settings form, modified in place
 * @return void
 */
function confscheduler_normalise_conference_dates(stdClass $data): void {
    if (empty($data->conferencestart)) {
        $data->conferencestart = null;
    }
    if (empty($data->conferenceend)) {
        $data->conferenceend = null;
    }
}

/**
 * Deletes an instance of the confscheduler activity and all associated data.
 *
 * Cascades confscheduler_slotroom -> confscheduler_slot -> confscheduler_room,
 * since confscheduler_slotroom rows reference both a slot and a room and must
 * be removed before either of those tables' rows are deleted.
 *
 * @param int $id The instance id
 * @return bool
 */
function confscheduler_delete_instance($id) {
    global $DB;

    if (!$confscheduler = $DB->get_record('confscheduler', ['id' => $id])) {
        return false;
    }

    $slotids = $DB->get_fieldset_select(
        'confscheduler_slot',
        'id',
        'confscheduler = :confscheduler',
        ['confscheduler' => $id]
    );
    if ($slotids) {
        [$insql, $params] = $DB->get_in_or_equal($slotids);
        $DB->delete_records_select('confscheduler_slotroom', "slotid $insql", $params);
    }

    $DB->delete_records('confscheduler_slot', ['confscheduler' => $id]);
    $DB->delete_records('confscheduler_room', ['confscheduler' => $id]);

    $DB->delete_records('confscheduler', ['id' => $id]);

    return true;
}
