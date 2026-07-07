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
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function confscheduler_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO        => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_BACKUP_MOODLE2   => true,
        FEATURE_GRADE_HAS_GRADE  => false,
        FEATURE_MOD_PURPOSE      => MOD_PURPOSE_COLLABORATION,
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
 * be removed before either of those tables' rows are deleted. Also deletes
 * confscheduler_notiftemplate rows (added alongside the manual "Send
 * notifications" feature) -- otherwise every deleted instance leaves an
 * orphaned template row behind with no other cleanup path (moodle-reviewer
 * finding, 2026-07-06).
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
    $DB->delete_records('confscheduler_notiftemplate', ['confscheduler' => $id]);
    $DB->delete_records('confscheduler_daybounds', ['confscheduler' => $id]);

    $DB->delete_records('confscheduler', ['id' => $id]);

    return true;
}

/**
 * Adds the confscheduler-specific elements to the course reset form.
 *
 * @param MoodleQuickForm $mform The course reset form
 * @return void
 */
function confscheduler_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'confschedulerheader', get_string('modulenameplural', 'confscheduler'));
    $mform->addElement('advcheckbox', 'reset_confscheduler_schedule', get_string('removeschedule', 'confscheduler'));
}

/**
 * Course reset form defaults.
 *
 * @param stdClass $course The course object
 * @return array
 */
function confscheduler_reset_course_form_defaults($course) {
    return ['reset_confscheduler_schedule' => 1];
}

/**
 * Clears the schedule (every slot and its room assignment(s)) for every confscheduler
 * instance in a course, when a teacher resets the course for reuse. Rooms are instance
 * CONFIGURATION (the venues themselves, likely reused for a new conference) and are
 * deliberately left untouched, matching mod_confsubmissions's tracks/types/fields and
 * mod_confprogram's Display-phase field settings -- only the schedule ITSELF, not the
 * setup that produced it, is cleared.
 *
 * @param stdClass $data The data submitted from the reset course form
 * @return array status array
 */
function confscheduler_reset_userdata($data) {
    global $DB;

    $componentstr = get_string('modulenameplural', 'confscheduler');
    $status = [];

    if (!empty($data->reset_confscheduler_schedule)) {
        $confschedulerids = $DB->get_fieldset_select('confscheduler', 'id', 'course = ?', [$data->courseid]);

        if ($confschedulerids) {
            [$insql, $params] = $DB->get_in_or_equal($confschedulerids);
            $slotids = $DB->get_fieldset_select('confscheduler_slot', 'id', "confscheduler $insql", $params);

            if ($slotids) {
                [$slotinsql, $slotparams] = $DB->get_in_or_equal($slotids);
                $DB->delete_records_select('confscheduler_slotroom', "slotid $slotinsql", $slotparams);
            }

            $DB->delete_records_select('confscheduler_slot', "confscheduler $insql", $params);
        }

        $status[] = [
            'component' => $componentstr,
            'item' => get_string('removeschedule', 'confscheduler'),
            'error' => false,
        ];
    }

    if (!empty($data->timeshift)) {
        // Any changes to the list of dates that needs to be rolled should be the same
        // during course restore and course reset (see MDL-9367, and
        // restore_confscheduler_activity_structure_step::process_confscheduler()'s
        // identical apply_date_offset() treatment of the same two columns).
        shift_course_mod_dates(
            'confscheduler',
            ['conferencestart', 'conferenceend'],
            $data->timeshift,
            $data->courseid
        );
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('date'),
            'error' => false,
        ];
    }

    return $status;
}
