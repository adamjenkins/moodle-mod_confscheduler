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
 * Activity settings form for mod_confscheduler.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Settings form for the Conference Scheduler activity.
 *
 * Covers name/intro, the link to the mod_confprogram instance this scheduler
 * pulls accepted submissions from, and the conference start/end dates. The
 * SnapGap minimum-gap setting previously lived here too; it moved to a quick
 * control at the top of the schedule grid in edit mode (Revision round 1
 * follow-up, 2026-07-04, per explicit feedback) -- like room/track/
 * submission-type management elsewhere in this project, it is
 * organiser-facing configuration that only makes sense once the instance
 * already exists, so it does not belong in the settings form.
 */
class mod_confscheduler_mod_form extends moodleform_mod {
    /** @var int[] Valid confprogramcmid option keys (course_module ids in this course), set by definition(). */
    protected $confprogramcmids = [];

    /**
     * Defines the form fields.
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;
        $courseid = $this->current->course ?? $this->course->id;

        // General section.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Conference start/end dates (Revision round 1, 2026-07-03; made required,
        // 2026-07-05): organiser-declared, and now REQUIRED -- the edit-mode grid's
        // day selector, its "All days" view, the autoscheduler's default window, and
        // the greyed-out/bounce-back out-of-conference-hours behaviour (see
        // classes/api.php's validate_placement()) all now derive from this range, so
        // an instance needs it set to get any of that.
        //
        // Still built with 'optional' => true, NOT removed despite "required" above:
        // a plain formslib 'required' rule cannot actually enforce this on a
        // date_time_selector, because that element has no representable "empty"
        // state without its own optional-disable checkbox -- with 'optional' absent,
        // it always submits SOME real timestamp (defaulting to "now"), so a bare
        // empty()/required check could never fire (confirmed live: an earlier version
        // of this fix silently saved "now" as the conference date without the
        // organiser ever consciously choosing one). Keeping 'optional' => true (with
        // its checkbox left UNCHECKED by default, see setDefault(..., 0) below) is
        // what gives the field a genuine "not set" (0) state that validation() below
        // can actually detect and reject -- the checkbox itself is the only way
        // formslib supports "required" for this element type.
        //
        // An existing instance saved before this change may still have null dates
        // until next edited (the DB columns themselves stay nullable -- every reader
        // of them still tolerates null, matching this project's established
        // graceful-degradation pattern for optional cross-references, e.g.
        // RELATIONS.md's dangling-reference discussion), but the form itself no
        // longer allows saving a NEW or edited instance without both actually set.
        // Deliberately in the General section (not a separate settings screen), per
        // the original explicit feedback.
        $mform->addElement(
            'date_time_selector',
            'conferencestart',
            get_string('conferencestart', 'mod_confscheduler'),
            ['optional' => true]
        );
        $mform->setDefault('conferencestart', 0);
        $mform->addHelpButton('conferencestart', 'conferencestart', 'mod_confscheduler');

        $mform->addElement(
            'date_time_selector',
            'conferenceend',
            get_string('conferenceend', 'mod_confscheduler'),
            ['optional' => true]
        );
        $mform->setDefault('conferenceend', 0);
        $mform->addHelpButton('conferenceend', 'conferenceend', 'mod_confscheduler');

        // Which mod_confprogram instance this scheduler pulls accepted submissions from.
        $options = [0 => get_string('choosedots')];
        // Note: get_coursemodules_in_course('confprogram', $courseid) is an alternative,
        // but its exact return shape (does it include the activity name, or only
        // course_modules fields?) is unconfirmed against the target Moodle version;
        // querying course_modules joined to modules directly here is unambiguous.
        $confprogramcms = $DB->get_records_sql(
            "SELECT cm.id, cp.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'confprogram'
               JOIN {confprogram} cp ON cp.id = cm.instance
              WHERE cm.course = :courseid
           ORDER BY cp.name ASC",
            ['courseid' => $courseid]
        );
        foreach ($confprogramcms as $cm) {
            $options[$cm->id] = format_string($cm->name);
        }
        $this->confprogramcmids = array_keys($options);

        $mform->addElement(
            'select',
            'confprogramcmid',
            get_string('confprogramcmid', 'mod_confscheduler'),
            $options
        );
        $mform->addRule('confprogramcmid', null, 'required', null, 'client');
        $mform->addHelpButton('confprogramcmid', 'confprogramcmid', 'mod_confscheduler');
        if (count($options) <= 1) {
            $mform->addElement(
                'static',
                'noconfprogram',
                '',
                get_string('error:noconfprogram', 'mod_confscheduler')
            );
        }

        // Display-mode day-selector defaults (user request, 2026-07-07). Both default
        // to the previous, only behaviour: closest day, no remembering.
        $mform->addElement('header', 'displaysettings', get_string('displaysettings', 'mod_confscheduler'));

        $mform->addElement('select', 'defaultdateview', get_string('defaultdateview', 'mod_confscheduler'), [
            'closest' => get_string('dateview_closest', 'mod_confscheduler'),
            'all'     => get_string('dateview_all', 'mod_confscheduler'),
        ]);
        $mform->setDefault('defaultdateview', 'closest');
        $mform->addHelpButton('defaultdateview', 'defaultdateview', 'mod_confscheduler');

        $mform->addElement('advcheckbox', 'rememberlastday', get_string('rememberlastday', 'mod_confscheduler'));
        $mform->setDefault('rememberlastday', 0);
        $mform->addHelpButton('rememberlastday', 'rememberlastday', 'mod_confscheduler');

        // Standard module elements (visibility, groups, etc.).
        $this->standard_coursemodule_elements();

        $this->add_action_buttons();
    }

    /**
     * Server-side validation.
     *
     * @param array $data Submitted form data
     * @param array $files Uploaded files
     * @return array Errors keyed by field name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty($data['confprogramcmid'])) {
            $errors['confprogramcmid'] = get_string('required');
        } else if (!in_array((int) $data['confprogramcmid'], $this->confprogramcmids, true)) {
            // Reject a submitted value outside the course-scoped option set the UI actually
            // offered (e.g. a confprogram activity in an unrelated course), since every
            // downstream page trusts confscheduler.confprogramcmid implicitly.
            $errors['confprogramcmid'] = get_string('error:invalidconfprogramcmid', 'mod_confscheduler');
        }

        // Conference dates made required (2026-07-05): the "optional" checkbox on
        // each date_time_selector above (see its docblock for why that's kept, not
        // removed) submits 0 when left unchecked -- this is the actual "required"
        // enforcement, since downstream code (day selector, autoscheduler defaults,
        // out-of-hours greying/bounce-back) depends on both being genuinely set.
        if (empty($data['conferencestart'])) {
            $errors['conferencestart'] = get_string('required');
        }
        if (empty($data['conferenceend'])) {
            $errors['conferenceend'] = get_string('required');
        }

        if (
            !empty($data['conferencestart']) && !empty($data['conferenceend'])
                && $data['conferenceend'] < $data['conferencestart']
        ) {
            $errors['conferenceend'] = get_string('error:conferenceendbeforestart', 'mod_confscheduler');
        }

        return $errors;
    }
}
