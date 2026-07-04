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

declare(strict_types=1);

namespace mod_confscheduler;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;

/**
 * Tests for the Phase 3.4 additions to \mod_confscheduler\api:
 * run_autoscheduler() and its placement algorithm.
 *
 * The "session"-tag tests that originally lived here (set_session_tag()/
 * get_session_tags() round-trip/chain-of-custody/overlong-label, and the
 * priority-1 same-session-consecutive-placement tests) were deleted, not
 * merely skipped or commented out, when the "session" tagging feature was
 * removed entirely (Revision round 1 batch B, 2026-07-03) -- see
 * changelog.md and \mod_confscheduler\api::run_autoscheduler()'s docblock.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api::class)]
final class api_autoscheduler_test extends advanced_testcase {
    /**
     * Creates a confscheduler instance (with the mod_confsubmissions +
     * mod_confprogram instances it depends on) and returns every fixture
     * piece the autoscheduler tests below need.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} [$confscheduler, $confprogram, $confsubmissions]
     */
    protected function create_full_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $confsubmissions = $this->getDataGenerator()->create_module('confsubmissions', ['course' => $course->id]);
        $confsubmissionscm = get_coursemodule_from_instance('confsubmissions', $confsubmissions->id);

        $confprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $course->id,
            'confsubmissionscmid' => $confsubmissionscm->id,
        ]);
        $confprogramcm = get_coursemodule_from_instance('confprogram', $confprogram->id);

        $confschedulerrecord = $this->getDataGenerator()->create_module('confscheduler', [
            'course'          => $course->id,
            'confprogramcmid' => $confprogramcm->id,
        ]);
        $confscheduler = $DB->get_record('confscheduler', ['id' => $confschedulerrecord->id], '*', MUST_EXIST);

        return [$confscheduler, $confprogram, $confsubmissions];
    }

    /**
     * Creates an accept-decided submission, optionally with a trackid.
     *
     * @param \stdClass $confsubmissions The confsubmissions instance record
     * @param \stdClass $confprogram The confprogram instance record
     * @param string $title Submission title
     * @param int|null $trackid Track to assign, or null for no track
     * @return int The confsubmissions_submission id
     */
    protected function create_accepted_submission(
        \stdClass $confsubmissions,
        \stdClass $confprogram,
        string $title = 'Test Talk',
        ?int $trackid = null
    ): int {
        global $DB;

        $submitter = $this->getDataGenerator()->create_user();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $submitter->id,
            'trackid'         => $trackid,
            'title'           => $title,
            'abstract'        => 'An abstract.',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);

        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        return $submissionid;
    }

    /**
     * Asserts that a set of scheduled slots contains no true time overlap and
     * no GapSnap violation within any single room -- i.e. that whatever the
     * autoscheduler's own placement preferences were, add_slot()'s
     * authoritative validation was never bypassed.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param int $gapminutes The instance's configured GapSnap gap
     */
    protected function assert_no_overlap_or_gap_violation(int $confschedulerid, int $gapminutes): void {
        global $DB;

        $rooms = api::get_rooms($confschedulerid);
        $gapseconds = $gapminutes * MINSECS;

        foreach ($rooms as $room) {
            $sql = "SELECT s.id, s.starttime, s.endtime
                      FROM {confscheduler_slot} s
                      JOIN {confscheduler_slotroom} sr ON sr.slotid = s.id
                     WHERE sr.roomid = :roomid
                  ORDER BY s.starttime ASC";
            $slots = array_values($DB->get_records_sql($sql, ['roomid' => $room->id]));

            for ($i = 0; $i < count($slots) - 1; $i++) {
                $current = $slots[$i];
                $next = $slots[$i + 1];

                $this->assertLessThanOrEqual(
                    (int) $next->starttime,
                    (int) $current->endtime,
                    'Two slots in the same room must never truly overlap.'
                );

                if ($gapseconds > 0) {
                    $gap = (int) $next->starttime - (int) $current->endtime;
                    $this->assertGreaterThanOrEqual(
                        $gapseconds,
                        $gap,
                        'Two slots in the same room must respect the configured GapSnap minimum gap.'
                    );
                }
            }
        }
    }

    /**
     * run_autoscheduler() rejects an invalid window (end <= start) rather than
     * silently swapping or clamping it.
     */
    public function test_run_autoscheduler_rejects_invalid_window(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_full_fixture();

        $this->expectException(\invalid_parameter_exception::class);
        api::run_autoscheduler(
            (int) $confscheduler->id,
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 09:00:00'),
            30,
            false
        );
    }

    /**
     * run_autoscheduler() rejects a non-positive default duration.
     */
    public function test_run_autoscheduler_rejects_invalid_duration(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_full_fixture();

        $this->expectException(\invalid_parameter_exception::class);
        api::run_autoscheduler(
            (int) $confscheduler->id,
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 17:00:00'),
            0,
            false
        );
    }

    /**
     * With no rooms configured, every accepted submission is skipped with the
     * "no rooms configured" reason, and nothing throws.
     */
    public function test_run_autoscheduler_skips_everything_when_no_rooms(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $this->create_accepted_submission($confsubmissions, $confprogram, 'Talk A');
        $this->create_accepted_submission($confsubmissions, $confprogram, 'Talk B');

        $summary = api::run_autoscheduler(
            (int) $confscheduler->id,
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 17:00:00'),
            30,
            false
        );

        $this->assertSame(0, $summary['scheduled']);
        $this->assertSame(2, $summary['skipped']);
        $this->assertCount(2, $summary['skippedreasons']);
    }

    /**
     * Priority 1: two submissions sharing a trackid are preferentially placed
     * in the same room.
     */
    public function test_run_autoscheduler_prefers_same_room_for_track_group(): void {
        global $DB;
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        api::add_room((int) $confscheduler->id, 'Room A');
        api::add_room((int) $confscheduler->id, 'Room B');
        api::add_room((int) $confscheduler->id, 'Room C');

        $trackid = \mod_confsubmissions\api::add_track((int) $confsubmissions->id, 'Data Science');

        $first = $this->create_accepted_submission($confsubmissions, $confprogram, 'DS Talk 1', $trackid);
        $second = $this->create_accepted_submission($confsubmissions, $confprogram, 'DS Talk 2', $trackid);

        $summary = api::run_autoscheduler(
            (int) $confscheduler->id,
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 17:00:00'),
            30,
            false,
            7
        );

        $this->assertSame(2, $summary['scheduled']);

        $firstslot = $DB->get_record('confscheduler_slot', ['confscheduler' => $confscheduler->id, 'submissionid' => $first]);
        $secondslot = $DB->get_record('confscheduler_slot', ['confscheduler' => $confscheduler->id, 'submissionid' => $second]);
        $firstroomids = array_column($DB->get_records('confscheduler_slotroom', ['slotid' => $firstslot->id]), 'roomid');
        $secondroomids = array_column($DB->get_records('confscheduler_slotroom', ['slotid' => $secondslot->id]), 'roomid');

        $this->assertSame($firstroomids, $secondroomids, 'Same-track submissions should preferentially land in the same room.');
    }

    /**
     * An autoscheduler run over a tightly-packed fixture (multiple track
     * groups and ungrouped submissions competing for limited room capacity,
     * with GapSnap > 0) never produces a true overlap or a GapSnap violation
     * in the resulting schedule, regardless of the algorithm's own grouping
     * preferences.
     */
    public function test_run_autoscheduler_never_violates_gapsnap_or_overlap(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        global $DB;
        $DB->set_field('confscheduler', 'gapminutes', 5, ['id' => $confscheduler->id]);

        api::add_room((int) $confscheduler->id, 'Room A');
        api::add_room((int) $confscheduler->id, 'Room B');

        $trackx = \mod_confsubmissions\api::add_track((int) $confsubmissions->id, 'Track X');
        $tracky = \mod_confsubmissions\api::add_track((int) $confsubmissions->id, 'Track Y');

        $this->create_accepted_submission($confsubmissions, $confprogram, 'X1', $trackx);
        $this->create_accepted_submission($confsubmissions, $confprogram, 'X2', $trackx);
        $this->create_accepted_submission($confsubmissions, $confprogram, 'Y1', $tracky);
        $this->create_accepted_submission($confsubmissions, $confprogram, 'Y2', $tracky);

        $this->create_accepted_submission($confsubmissions, $confprogram, 'U1');
        $this->create_accepted_submission($confsubmissions, $confprogram, 'U2');
        $this->create_accepted_submission($confsubmissions, $confprogram, 'U3');

        $summary = api::run_autoscheduler(
            (int) $confscheduler->id,
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 11:00:00'),
            30,
            false,
            123
        );

        // Capacity is tight (2 rooms x 2 hours x 30-minute slots with a 5-minute
        // gap = well under 9 submissions), so a partial skip is expected; the
        // point of this test is that whatever DID get scheduled never overlaps
        // or violates GapSnap, not that everything fits.
        $this->assertGreaterThan(0, $summary['scheduled']);
        $this->assert_no_overlap_or_gap_violation((int) $confscheduler->id, 5);
    }

    /**
     * clearfirst = true removes existing slots that overlap the window, and
     * leaves slots entirely outside the window untouched.
     */
    public function test_run_autoscheduler_clearfirst_true_clears_only_within_window(): void {
        global $DB;
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        $insidesubmission = $this->create_accepted_submission($confsubmissions, $confprogram, 'Inside Window');
        $outsidesubmission = $this->create_accepted_submission($confsubmissions, $confprogram, 'Outside Window');

        $insideslotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $insidesubmission
        );
        $outsideslotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-02 10:00:00'),
            strtotime('2026-09-02 10:30:00'),
            $outsidesubmission
        );

        api::run_autoscheduler(
            (int) $confscheduler->id,
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 17:00:00'),
            30,
            true
        );

        $this->assertFalse($DB->record_exists('confscheduler_slot', ['id' => $insideslotid]));
        $this->assertTrue($DB->record_exists('confscheduler_slot', ['id' => $outsideslotid]));
        // The submission that was cleared is now unscheduled-but-accepted, so the
        // autoscheduler should have re-placed it somewhere in the window.
        $this->assertNotNull(api::get_schedule_for_submission($insidesubmission));
    }

    /**
     * clearfirst = false leaves existing slots untouched and simply avoids
     * colliding with them.
     */
    public function test_run_autoscheduler_clearfirst_false_avoids_existing_slots(): void {
        global $DB;
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        $manualsubmission = $this->create_accepted_submission($confsubmissions, $confprogram, 'Manually Placed');
        $manualslotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $manualsubmission
        );

        $this->create_accepted_submission($confsubmissions, $confprogram, 'Needs Placing');

        api::run_autoscheduler(
            (int) $confscheduler->id,
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 17:00:00'),
            30,
            false
        );

        // The manual slot is completely untouched: same id, same time.
        $manualslot = $DB->get_record('confscheduler_slot', ['id' => $manualslotid]);
        $this->assertSame(strtotime('2026-09-01 10:00:00'), (int) $manualslot->starttime);

        $this->assert_no_overlap_or_gap_violation((int) $confscheduler->id, 0);
    }

    /**
     * When the window genuinely cannot fit every accepted submission, the run
     * reports a partial success (some scheduled, some skipped with a reason)
     * instead of throwing and aborting.
     */
    public function test_run_autoscheduler_reports_partial_skip_when_window_too_small(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        api::add_room((int) $confscheduler->id, 'Only Room');

        $this->create_accepted_submission($confsubmissions, $confprogram, 'Talk 1');
        $this->create_accepted_submission($confsubmissions, $confprogram, 'Talk 2');
        $this->create_accepted_submission($confsubmissions, $confprogram, 'Talk 3');

        // Only one 30-minute submission can fit in a 30-minute window with one room.
        $summary = api::run_autoscheduler(
            (int) $confscheduler->id,
            strtotime('2026-09-01 09:00:00'),
            strtotime('2026-09-01 09:30:00'),
            30,
            false
        );

        $this->assertSame(1, $summary['scheduled']);
        $this->assertSame(2, $summary['skipped']);
        $this->assertCount(2, $summary['skippedreasons']);
        foreach ($summary['skippedreasons'] as $reason) {
            $this->assertArrayHasKey('submissionid', $reason);
            $this->assertArrayHasKey('title', $reason);
            $this->assertArrayHasKey('reason', $reason);
            $this->assertNotSame('', $reason['reason']);
        }
    }

    /**
     * Randomisation: make_random_source()/fisher_yates_shuffle() (accessed via
     * reflection, since they are protected implementation details of
     * run_autoscheduler()) produce the SAME shuffle for the same seed, and
     * (overwhelmingly likely, for a 12-element array) a DIFFERENT shuffle for
     * a different seed or for unseeded (null) calls.
     */
    public function test_random_source_is_deterministic_for_a_fixed_seed_and_varies_otherwise(): void {
        $this->resetAfterTest();

        $reflection = new ReflectionClass(api::class);
        $makesource = $reflection->getMethod('make_random_source');
        $makesource->setAccessible(true);
        $shuffle = $reflection->getMethod('fisher_yates_shuffle');
        $shuffle->setAccessible(true);

        $items = range(1, 12);

        $seed42first = $shuffle->invoke(null, $items, $makesource->invoke(null, 42));
        $seed42second = $shuffle->invoke(null, $items, $makesource->invoke(null, 42));
        $this->assertSame($seed42first, $seed42second, 'The same seed must produce the same shuffle.');

        $seed43 = $shuffle->invoke(null, $items, $makesource->invoke(null, 43));
        $this->assertNotSame($seed42first, $seed43, 'Different seeds should (overwhelmingly likely) differ.');

        $unseededfirst = $shuffle->invoke(null, $items, $makesource->invoke(null, null));
        $unseededsecond = $shuffle->invoke(null, $items, $makesource->invoke(null, null));
        $this->assertNotSame(
            $unseededfirst,
            $unseededsecond,
            'Unseeded (production) calls should (overwhelmingly likely) differ run to run.'
        );
    }

    /**
     * Integration-level companion to the reflection-based determinism test
     * above: running the full autoscheduler twice with the same seed (and
     * clearfirst = true to reset between runs) over the same candidate pool
     * produces an identical placement (room + start time per submission);
     * trying a small handful of alternate seeds against that same pool
     * produces at least one different placement. Checking against several
     * alternate seeds (rather than just one) keeps this non-flaky: a
     * collision with any single alternate seed is plausible by chance, a
     * collision with all of them is not.
     */
    public function test_run_autoscheduler_seed_reproducibility_end_to_end(): void {
        global $DB;
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_full_fixture();
        api::add_room((int) $confscheduler->id, 'Room A');
        api::add_room((int) $confscheduler->id, 'Room B');
        api::add_room((int) $confscheduler->id, 'Room C');

        for ($i = 1; $i <= 6; $i++) {
            $this->create_accepted_submission($confsubmissions, $confprogram, "Talk $i");
        }

        $windowstart = strtotime('2026-09-01 09:00:00');
        $windowend = strtotime('2026-09-01 10:10:00');

        $placementsnapshot = function () use ($DB, $confscheduler): array {
            $sql = "SELECT s.submissionid, s.starttime, sr.roomid
                      FROM {confscheduler_slot} s
                      JOIN {confscheduler_slotroom} sr ON sr.slotid = s.id
                     WHERE s.confscheduler = :confschedulerid
                  ORDER BY s.submissionid ASC";
            $rows = $DB->get_records_sql($sql, ['confschedulerid' => $confscheduler->id]);
            $snapshot = [];
            foreach ($rows as $row) {
                $snapshot[(int) $row->submissionid] = [(int) $row->roomid, (int) $row->starttime];
            }
            return $snapshot;
        };

        api::run_autoscheduler((int) $confscheduler->id, $windowstart, $windowend, 30, false, 42);
        $seed42run1 = $placementsnapshot();

        api::run_autoscheduler((int) $confscheduler->id, $windowstart, $windowend, 30, true, 42);
        $seed42run2 = $placementsnapshot();

        $this->assertSame($seed42run1, $seed42run2, 'The same seed must reproduce the same placement.');

        $founddifference = false;
        foreach ([1, 2, 3, 4, 5] as $altseed) {
            api::run_autoscheduler((int) $confscheduler->id, $windowstart, $windowend, 30, true, $altseed);
            if ($placementsnapshot() !== $seed42run1) {
                $founddifference = true;
                break;
            }
        }
        $this->assertTrue($founddifference, 'At least one alternate seed should produce a different placement.');
    }
}
