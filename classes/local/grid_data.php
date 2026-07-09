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

use mod_confprogram\local\display_list;
use mod_confsubmissions\api as submissions_api;

/**
 * Builds the decorated grid payload the AJAX get_grid_data endpoint returns:
 * rooms in order, scheduled slots (with room(s), and, for presentation slots,
 * title/speakers/track resolved from the sibling plugins), and the list of
 * accepted-but-unscheduled submissions.
 *
 * Kept out of classes/external/get_grid_data.php (which stays a thin
 * page-logic file matching the sibling plugins' external function
 * conventions) so this is independently unit-testable without going through
 * the external API's parameter validation machinery.
 *
 * This class does not check capabilities: the caller (get_grid_data external
 * function) is responsible for require_capability('mod/confscheduler:viewschedule', ...)
 * before calling build().
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grid_data {
    /**
     * Builds the full grid payload for a confscheduler instance.
     *
     * @param \stdClass $confscheduler The confscheduler record
     * @param int $userid The current user id, used to resolve per-user favourited state
     * @return array{rooms: array, slots: array, unscheduled: array, gapminutes: int, pxperhour: int,
     *     conferencestart: ?int, conferenceend: ?int, daystart: ?int, dayend: ?int,
     *     daybounds: array, pendingnotifications: int}
     *
     * Each 'unscheduled' entry also includes 'preferreddates' (int[], midnight
     * timestamps; empty means no preference recorded). Each 'slots' entry with a
     * submissionid also includes 'nonpreferredday' (bool; true only when the
     * submission has a non-empty preferred-dates list AND this slot's own day is
     * not one of them -- user feedback, 2026-07-05) for the edit-mode grid to
     * highlight; the read-only Display-mode grid deliberately never reads this
     * field, so it renders identically whether a block is flagged or not. Each
     * 'rooms' entry also includes 'capacity' (int|null; null means unlimited).
     * Each 'slots' entry also includes 'favouritecount' (int, always computed,
     * even when 0 or the room has no capacity set) and 'overbooked' (bool; true
     * only when the presentation's room has a capacity configured AND
     * favouritecount exceeds it -- same edit-mode-only highlighting convention as
     * nonpreferredday, user request 2026-07-05). The top-level
     * 'pendingnotifications' is the count of presentation slots with a scheduling
     * change not yet notified (see \mod_confscheduler\api::count_pending_notifications()),
     * driving the edit-mode "Send notifications" button (user request, 2026-07-05). Each
     * 'slots'/'unscheduled' entry with a track also includes 'trackcolour' (string|null,
     * a 6-digit hex colour, or null when the track has none configured -- user request,
     * 2026-07-06), for the client to theme the track pill with. Each 'slots' entry with
     * a submissionid also includes 'withdrawn' (bool; true when
     * mod_confsubmissions's own confsubmissions_submission.status for it is 'withdrawn'
     * -- user request, 2026-07-07, so the read-only Display-mode grid can grey out and
     * strike through an already-scheduled block whose presentation was since withdrawn,
     * instead of clicking through to a submission detail modal for a cancelled talk;
     * always false for a span-block, matching every other submission-only field above).
     * Each 'slots' entry also includes 'iscontainer' (bool; true only for a container span
     * block -- always false for a presentation slot, even one nested inside a container),
     * 'parentslotid' (int|null; set only on a presentation slot nested in a container, to
     * that container's id) and 'roomnameoverride' (string|null; resolved from the PARENT
     * for a nested presentation, since a child's own row always has this null by
     * construction -- see api::add_presentation_to_container()) (user request, 2026-07-08,
     * poster/keynote container span blocks). Each 'slots' entry also includes
     * 'childtextalign' (string, one of 'left'/'center'/'right', never null) and
     * 'childtextvalign' (string, one of 'top'/'middle'/'bottom', never null), the
     * container's configured text alignment for its nested-presentation tiles --
     * resolved from the PARENT for a nested presentation, exactly matching
     * 'roomnameoverride''s pattern above, since a child's own row always carries just
     * the schema default (Round 2, 2026-07-08, modal filters + child tile alignment).
     * Each 'unscheduled' entry also includes 'type' (string|null, the submission
     * type's name, format_string()'d) and 'typeid' (int|null), resolved from the same
     * already-fetched submission-types rows this method uses for 'durationminutes'
     * above; both null when the submission has no type configured (or it was since
     * deleted) (Round 2, 2026-07-08).
     */
    public static function build(\stdClass $confscheduler, int $userid): array {
        global $DB;

        $confschedulerid = (int) $confscheduler->id;

        $confprogramcm = get_coursemodule_from_id('confprogram', $confscheduler->confprogramcmid, 0, false, MUST_EXIST);
        $confprogram = $DB->get_record('confprogram', ['id' => $confprogramcm->instance], '*', MUST_EXIST);
        $confsubmissionscm = get_coursemodule_from_id(
            'confsubmissions',
            $confprogram->confsubmissionscmid,
            0,
            false,
            MUST_EXIST
        );

        $rooms = \mod_confscheduler\api::get_rooms($confschedulerid);
        $roomsout = [];
        foreach ($rooms as $room) {
            $roomsout[] = [
                'id'        => (int) $room->id,
                'name'      => $room->name,
                'sortorder' => (int) $room->sortorder,
                'colour'    => $room->colour,
                'capacity'  => $room->capacity !== null ? (int) $room->capacity : null,
            ];
        }
        $capacitybyroomid = [];
        foreach ($rooms as $room) {
            $capacitybyroomid[(int) $room->id] = $room->capacity !== null ? (int) $room->capacity : null;
        }

        $tracksbyid = [];
        foreach (submissions_api::get_tracks($confsubmissionscm->id) as $track) {
            $tracksbyid[(int) $track->id] = $track;
        }

        $typedurationsbyid = [];
        $typenamesbyid = [];
        foreach (submissions_api::get_submission_types($confsubmissionscm->id) as $submissiontype) {
            $typedurationsbyid[(int) $submissiontype->id] = (int) $submissiontype->durationminutes;
            $typenamesbyid[(int) $submissiontype->id] = $submissiontype->name;
        }

        $slots = \mod_confscheduler\api::get_slots($confschedulerid);
        $slotroomsbyslot = [];
        if ($slots) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($slots), SQL_PARAMS_QM);
            $slotroomrows = $DB->get_records_select('confscheduler_slotroom', "slotid $insql", $params);
            foreach ($slotroomrows as $row) {
                $slotroomsbyslot[(int) $row->slotid][] = (int) $row->roomid;
            }
        }

        $slotsbyid = [];
        foreach ($slots as $slot) {
            $slotsbyid[(int) $slot->id] = $slot;
        }

        // Everything per-submission is fetched in BULK up front -- submissions,
        // speaker names, date preferences, favourite counts and the user's own
        // favourites -- instead of ~5 queries per scheduled slot plus more per
        // unscheduled submission (FABLE.md review, 2026-07-09: this payload is
        // re-fetched after every drag/resize/edit, on every Display-mode view,
        // and again per ICS download).
        $accepted = display_list::get_accepted_submissions((int) $confprogram->id, (int) $confsubmissionscm->instance);

        $slotsubmissionids = [];
        foreach ($slots as $slot) {
            if ($slot->submissionid !== null) {
                $slotsubmissionids[] = (int) $slot->submissionid;
            }
        }
        $acceptedids = array_map(static fn($submission): int => (int) $submission->id, $accepted);
        $allsubmissionids = array_values(array_unique(array_merge($slotsubmissionids, $acceptedids)));

        $submissionsbyid = submissions_api::get_submissions($slotsubmissionids);
        $speakernamesbyid = self::format_speakers_bulk($allsubmissionids);
        $preferreddatesbyid = submissions_api::get_date_preferences_for_submissions($allsubmissionids);
        $favouritecountsbyid = \mod_confprogram\api::count_favourites_for_submissions($slotsubmissionids);
        // The user's favourites for THIS instance's linked confprogram, in one
        // query. Also (deliberately) instance-scopes the read: the old per-slot
        // is_favourited() call was not confprogram-scoped, the long-documented
        // RELATIONS.md inconsistency whose proper fix was exactly this scoping.
        $favouritedids = [];
        foreach (\mod_confprogram\api::get_favourites($userid, (int) $confprogram->id) as $favourite) {
            $favouritedids[(int) $favourite->submissionid] = true;
        }

        $scheduledsubmissionids = [];
        $slotsout = [];
        foreach ($slots as $slot) {
            $parentslotid = !empty($slot->parentslotid) ? (int) $slot->parentslotid : null;
            $parentslot = $parentslotid !== null ? ($slotsbyid[$parentslotid] ?? null) : null;
            $effectiveslotid = $parentslot ? (int) $parentslot->id : (int) $slot->id;

            $entry = [
                'id'               => (int) $slot->id,
                'roomids'          => $slotroomsbyslot[$effectiveslotid] ?? [],
                'starttime'        => (int) $slot->starttime,
                'endtime'          => (int) $slot->endtime,
                'label'            => $slot->label,
                'colour'           => $slot->submissionid === null ? ($slot->colour ?? null) : null,
                'submissionid'     => $slot->submissionid !== null ? (int) $slot->submissionid : null,
                'iscontainer'      => $slot->submissionid === null ? (bool) $slot->iscontainer : false,
                'parentslotid'     => $parentslotid,
                'roomnameoverride' => $parentslot ? $parentslot->roomnameoverride : $slot->roomnameoverride,
                'childtextalign'   => $parentslot ? $parentslot->childtextalign : $slot->childtextalign,
                'childtextvalign'  => $parentslot ? $parentslot->childtextvalign : $slot->childtextvalign,
                'title'            => null,
                'speakers'         => null,
                'track'            => null,
                'trackid'          => null,
                'trackcolour'      => null,
                'favourited'       => false,
                'nonpreferredday'  => false,
                'favouritecount'   => 0,
                'overbooked'       => false,
                'withdrawn'        => false,
            ];

            if ($slot->submissionid !== null) {
                $scheduledsubmissionids[] = (int) $slot->submissionid;
                $submission = $submissionsbyid[(int) $slot->submissionid] ?? null;
                if ($submission) {
                    $entry['title'] = format_string($submission->title, true, ['escape' => false]);
                    $entry['speakers'] = $speakernamesbyid[(int) $submission->id] ?? '';
                    $entry['withdrawn'] = $submission->status === 'withdrawn';
                    $hastrack = !empty($submission->trackid) && isset($tracksbyid[(int) $submission->trackid]);
                    $entry['track'] = $hastrack
                        ? format_string($tracksbyid[(int) $submission->trackid]->name, true, ['escape' => false])
                        : null;
                    $entry['trackid'] = $hastrack ? (int) $submission->trackid : null;
                    $entry['trackcolour'] = $hastrack ? ($tracksbyid[(int) $submission->trackid]->colour ?: null) : null;
                    $entry['favourited'] = isset($favouritedids[(int) $submission->id]);

                    // Flagged for edit-mode-only highlighting (user feedback,
                    // 2026-07-05: scheduling onto a non-preferred day is now only
                    // possible via manual drag, or the autoscheduler's explicit
                    // "ignore preferred dates" override -- see api::run_autoscheduler()'s
                    // docblock). An empty preference array means "no preference
                    // recorded," never flagged as non-preferred -- matches every other
                    // consumer of get_date_preferences()'s "empty means unrestricted"
                    // contract.
                    $preferreddates = $preferreddatesbyid[(int) $submission->id] ?? [];
                    if ($preferreddates) {
                        $day = usergetmidnight((int) $slot->starttime);
                        $entry['nonpreferredday'] = !in_array($day, $preferreddates, true);
                    }

                    // Room-capacity overbooking warning (user request, 2026-07-05),
                    // edit-mode-only highlighting like nonpreferredday above. Only
                    // meaningful for a presentation placed in exactly one room (a
                    // column-spanning block has no submissionid and never reaches
                    // this branch at all) with a capacity actually configured --
                    // null capacity means unlimited, never a warning.
                    $entry['favouritecount'] = $favouritecountsbyid[(int) $submission->id] ?? 0;
                    if (count($entry['roomids']) === 1) {
                        $roomcapacity = $capacitybyroomid[$entry['roomids'][0]] ?? null;
                        if ($roomcapacity !== null && $entry['favouritecount'] > $roomcapacity) {
                            $entry['overbooked'] = true;
                        }
                    }
                }
            }

            $slotsout[] = $entry;
        }

        $unscheduledout = [];
        foreach ($accepted as $submission) {
            if (in_array((int) $submission->id, $scheduledsubmissionids, true)) {
                continue;
            }
            $hastrack = !empty($submission->trackid) && isset($tracksbyid[(int) $submission->trackid]);
            $submissiontypeid = !empty($submission->submissiontypeid) ? (int) $submission->submissiontypeid : null;
            $unscheduledout[] = [
                'submissionid'    => (int) $submission->id,
                'title'           => format_string($submission->title, true, ['escape' => false]),
                'speakers'        => $speakernamesbyid[(int) $submission->id] ?? '',
                'track'           => $hastrack
                    ? format_string($tracksbyid[(int) $submission->trackid]->name, true, ['escape' => false])
                    : null,
                'trackid'         => $hastrack ? (int) $submission->trackid : null,
                'trackcolour'     => $hastrack ? ($tracksbyid[(int) $submission->trackid]->colour ?: null) : null,
                'type'            => $submissiontypeid !== null && isset($typenamesbyid[$submissiontypeid])
                    ? format_string($typenamesbyid[$submissiontypeid], true, ['escape' => false])
                    : null,
                'typeid'          => $submissiontypeid,
                // Falls back to api::DEFAULT_DURATION_MINUTES when this submission has no
                // type (or the type was deleted after being chosen) -- see that constant's
                // docblock. The client uses this only as the INITIAL duration of a newly
                // dragged-out block; it never retroactively affects an already-scheduled one.
                'durationminutes' => $submissiontypeid !== null && isset($typedurationsbyid[$submissiontypeid])
                    ? $typedurationsbyid[$submissiontypeid]
                    : \mod_confscheduler\api::DEFAULT_DURATION_MINUTES,
                // Empty means "no preference recorded" -- the client must treat that as
                // "show on every day", not "hide everywhere" (user feedback, 2026-07-05;
                // see mod_confsubmissions\api::get_date_preferences()'s docblock).
                'preferreddates'  => $preferreddatesbyid[(int) $submission->id] ?? [],
            ];
        }

        // Per-day display-window overrides (user request, 2026-07-07), as a list of
        // {day (Y-m-d), daystart, dayend}. A day not listed here uses the instance
        // default (daystart/dayend below) -- see api::get_day_bounds() and
        // amd/src/day_utils.js::boundsForDay().
        $daybounds = [];
        foreach (\mod_confscheduler\api::get_day_bounds($confschedulerid) as $day => $bounds) {
            $daybounds[] = ['day' => $day, 'daystart' => $bounds['daystart'], 'dayend' => $bounds['dayend']];
        }

        return [
            'rooms'           => $roomsout,
            'slots'           => $slotsout,
            'unscheduled'     => $unscheduledout,
            'gapminutes'      => (int) $confscheduler->gapminutes,
            'pxperhour'       => (int) $confscheduler->pxperhour,
            'conferencestart' => $confscheduler->conferencestart !== null ? (int) $confscheduler->conferencestart : null,
            'conferenceend'   => $confscheduler->conferenceend !== null ? (int) $confscheduler->conferenceend : null,
            // Instance-level DEFAULT display window: applied to any day without its own
            // override in 'daybounds'. Both null means "automatic".
            'daystart'        => $confscheduler->daystart !== null ? (int) $confscheduler->daystart : null,
            'dayend'          => $confscheduler->dayend !== null ? (int) $confscheduler->dayend : null,
            'daybounds'       => $daybounds,
            // How many presentation slots have a scheduling change pending a
            // notification (user request, 2026-07-05) -- drives the edit-mode "Send
            // notifications" button's count, without itself sending anything.
            'pendingnotifications' => \mod_confscheduler\api::count_pending_notifications($confschedulerid),
        ];
    }

    /**
     * Formats many submissions' speaker names in bulk: one speakers query and one
     * user-record query for the whole set, instead of one speakers query plus one
     * user lookup PER SPEAKER per submission (FABLE.md review, 2026-07-09).
     * Per-submission output is identical to the old one-at-a-time formatter:
     * enrolled-user speakers resolve to their full name, non-user speakers fall
     * back to the manually-entered name, blanks are dropped, comma-joined.
     *
     * @param int[] $submissionids The confsubmissions_submission ids
     * @return array<int, string> Comma-joined speaker display names keyed by submissionid
     */
    protected static function format_speakers_bulk(array $submissionids): array {
        global $DB;

        $speakersbysubmission = submissions_api::get_speakers_for_submissions($submissionids);

        $userids = [];
        foreach ($speakersbysubmission as $speakers) {
            foreach ($speakers as $speaker) {
                if (!empty($speaker->userid)) {
                    $userids[] = (int) $speaker->userid;
                }
            }
        }
        $namefields = implode(', ', array_merge(['id'], \core_user\fields::for_name()->get_required_fields()));
        $users = $userids
            ? $DB->get_records_list('user', 'id', array_unique($userids), '', $namefields)
            : [];

        $result = [];
        foreach ($speakersbysubmission as $submissionid => $speakers) {
            $names = [];
            foreach ($speakers as $speaker) {
                if (!empty($speaker->userid)) {
                    $user = $users[(int) $speaker->userid] ?? null;
                    $name = $user ? fullname($user) : '';
                } else {
                    $name = (string) ($speaker->name ?? '');
                }
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            $result[(int) $submissionid] = implode(', ', $names);
        }

        return $result;
    }
}
