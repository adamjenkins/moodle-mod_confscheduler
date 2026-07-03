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

namespace mod_confscheduler\local;

/**
 * Resolves the mod_confprogram activity a confscheduler instance is linked to, for the
 * read-only Display mode's block "link through to the presentation's mod_confprogram
 * page" feature (Phase 3.5).
 *
 * Kept out of view.php (a thin page-logic file, matching the sibling plugins'
 * conventions) so the cmid resolution is independently unit-testable.
 *
 * confscheduler.confprogramcmid is a trusted DB field (set only via mod_form.php's
 * course-scoped activity picker, never taken from an untrusted request parameter at
 * read time), so no additional chain-of-custody check is needed here -- this is a pure
 * "does this id still resolve to a real course-module" lookup, the same MUST_EXIST
 * pattern classes/local/grid_data.php and classes/api.php already use for the same
 * field.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class display_link {
    /**
     * Builds the URL to the mod_confprogram activity's own view page that a confscheduler
     * instance is linked to.
     *
     * This is the honest fallback destination for a read-only block's link: it lands on
     * mod_confprogram's Display-phase accepted-submissions list for the right activity
     * (not just "some" activity), not a specific submission's row. Landing on the exact
     * submission is instead handled client-side, by calling mod_confprogram's own
     * mod_confprogram_get_submission_detail AJAX endpoint directly and showing the same
     * modal it shows on its own page (see amd/src/scheduler_display.js's
     * openProgramDetail()) -- there is no server-side URL-parameter convention in
     * mod_confprogram to deep-link a specific submission's modal, and this plugin must
     * not be modified to add one (see the project's cross-plugin "do not touch the
     * sibling plugin" constraint), so this URL is deliberately just the activity's own
     * page, used as the real, working <a href> fallback when JS is unavailable.
     *
     * @param \stdClass $confscheduler The confscheduler record (must have confprogramcmid)
     * @return \moodle_url
     * @throws \moodle_exception if confprogramcmid no longer resolves to a real course-module
     */
    public static function program_url(\stdClass $confscheduler): \moodle_url {
        $confprogramcm = get_coursemodule_from_id(
            'confprogram',
            (int) $confscheduler->confprogramcmid,
            0,
            false,
            MUST_EXIST
        );

        return new \moodle_url('/mod/confprogram/view.php', ['id' => $confprogramcm->id]);
    }
}
