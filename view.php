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
 * Main view page for mod_confscheduler.
 *
 * Edit mode: renders the grid shell only. All grid data (rooms, slots,
 * unscheduled submissions) is fetched via the mod_confscheduler_get_grid_data
 * AJAX endpoint from amd/src/scheduler_grid.js once the page loads, and every
 * mutation (schedule/reschedule/unschedule, room CRUD, favourite toggle)
 * goes through the same validated AJAX write paths -- nothing here
 * pre-renders grid data as PHP-generated HTML.
 *
 * Display mode: a read-only rendering of the same grid data via
 * amd/src/scheduler_display.js and templates/display.mustache (Phase 3.5) --
 * see that module's docblock for why it is a separate module from the edit
 * grid rather than a shared renderer, and README.md's "Architecture notes"
 * for the day-filtering sharing decision.
 *
 * Edit-mode gating (Revision round 1 batch B, 2026-07-03): which mode renders is NOT
 * purely a function of holding mod/confscheduler:manageschedule any more. Per explicit
 * user feedback, this deliberately reuses Moodle's own site-wide "Edit mode" switch
 * ($PAGE->user_is_editing(), the same course-editing toggle already used elsewhere --
 * e.g. mod_confprogram's view.php gates its own organiser controls the identical way)
 * rather than a plugin-bespoke toggle/preference: it is already visible in the page
 * header, already restricted to roles with editing capabilities, and users already
 * understand what it does. A manageschedule holder sees the same read-only Display mode
 * as everyone else while course editing is off, and the interactive edit grid only once
 * they turn course editing on. $canmanage still gates every write AJAX endpoint
 * independently (unchanged) -- $editmode (== $canmanage AND $PAGE->user_is_editing()) only
 * decides which template/JS module renders below, it is not itself a security boundary.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confscheduler/lib.php');

use mod_confscheduler\local\display_link;

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'confscheduler');
$confscheduler = $DB->get_record('confscheduler', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/confscheduler:viewschedule', $context);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$pageurl = new moodle_url('/mod/confscheduler/view.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confscheduler->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$canmanage = has_capability('mod/confscheduler:manageschedule', $context);

// See this file's docblock: edit-mode gating reuses Moodle's own site-wide "Edit mode"
// switch rather than a plugin-bespoke toggle. $editmode can never be true without
// $canmanage also true, so a capability revocation needs no separate handling here.
$editmode = $canmanage && $PAGE->user_is_editing();

// Track-pill click-through (Revision round 1, 2026-07-03) needs the linked
// mod_confprogram activity's base view URL in both edit and Display mode -- resolved
// once here rather than duplicated. confprogramcmid is a trusted DB field (set via
// mod_form.php's course-scoped activity picker, never from request input at read
// time); display_link::program_url() still resolves it defensively (MUST_EXIST) in
// case the linked activity has since been deleted (the edit-mode grid already
// assumes this resolves, via classes/local/grid_data.php's own MUST_EXIST lookup, so
// this introduces no new failure mode for that mode).
$programurl = display_link::program_url($confscheduler);

if ($editmode) {
    $PAGE->requires->js_call_amd('mod_confscheduler/scheduler_grid', 'init', [
        $cm->id,
        (int) $confscheduler->id,
        $programurl->out(false),
    ]);
}

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($confscheduler->name));

if ($confscheduler->intro) {
    echo $OUTPUT->box(
        format_module_intro('confscheduler', $confscheduler, $cm->id),
        'generalbox mod_introbox',
        'confschedulerintro'
    );
}

if ($editmode) {
    echo $OUTPUT->render_from_template('mod_confscheduler/grid', [
        'cmid'            => $cm->id,
        'confschedulerid' => (int) $confscheduler->id,
        'canmanage'       => true,
    ]);
} else {
    // Read-only Display mode (Phase 3.5), shown to everyone by default, including a
    // manageschedule holder viewing with course editing off. $programurl was already
    // resolved above.

    // Guests cannot meaningfully favourite (there is no per-guest persisted state to
    // toggle), matching the same isguestuser() exclusion mod_confprogram's own
    // Display-phase list applies to its favourite-star column.
    $canfavourite = !isguestuser() && has_capability('mod/confscheduler:favourite', $context);

    echo $OUTPUT->render_from_template('mod_confscheduler/display', [
        'cmid'            => $cm->id,
        'confschedulerid' => (int) $confscheduler->id,
        'canfavourite'    => $canfavourite,
    ]);

    $PAGE->requires->js_call_amd('mod_confscheduler/scheduler_display', 'init', [
        $cm->id,
        (int) $confscheduler->id,
        (int) $confscheduler->confprogramcmid,
        $programurl->out(false),
        $canfavourite,
    ]);
}

echo $OUTPUT->footer();
