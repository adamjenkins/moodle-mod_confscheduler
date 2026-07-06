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

namespace mod_confscheduler\local;

use advanced_testcase;
use mod_confscheduler\api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \mod_confscheduler\local\notifier and its manually-triggered,
 * change-tracked hook points in \mod_confscheduler\api
 * (get_pending_notification_slots(), count_pending_notifications(),
 * send_pending_notifications()).
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(notifier::class)]
#[CoversClass(api::class)]
final class notifier_test extends advanced_testcase {
    /**
     * Creates a confscheduler instance (with its confprogram/confsubmissions
     * dependencies) and returns the pieces every test below needs.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} [$confscheduler, $confprogram, $confsubmissions]
     */
    private function create_fixture(): array {
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
     * Creates an accepted submission with a real (userid-backed) speaker.
     *
     * @param \stdClass $confsubmissions The confsubmissions instance record
     * @param \stdClass $confprogram The confprogram instance record
     * @param \stdClass $speaker The speaker's user record
     * @return int The confsubmissions_submission id
     */
    private function create_accepted_submission_with_speaker(
        \stdClass $confsubmissions,
        \stdClass $confprogram,
        \stdClass $speaker
    ): int {
        global $DB;

        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $speaker->id,
            'title'           => 'A Test Talk',
            'abstract'        => 'Abstract text',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        \mod_confsubmissions\api::sync_speakers($submissionid, [['userid' => $speaker->id]]);

        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        return $submissionid;
    }

    /**
     * render() substitutes every recognised placeholder and drops (replaces with '')
     * any placeholder not present in the context.
     */
    public function test_render_substitutes_known_and_drops_unknown_placeholders(): void {
        $this->assertSame(
            'Room: Main Hall, note: .',
            notifier::render('Room: [[roomnames]], note: [[doesnotexist]].', ['roomnames' => 'Main Hall'])
        );
    }

    /**
     * get_template() falls back to default_template() when no
     * confscheduler_notiftemplate row exists, and uses the configured row once one
     * does.
     */
    public function test_get_template_falls_back_to_default(): void {
        $this->resetAfterTest();
        global $DB;

        [$confscheduler] = $this->create_fixture();

        $default = notifier::default_template();
        $template = notifier::get_template((int) $confscheduler->id);
        $this->assertSame($default['subject'], $template['subject']);

        $DB->insert_record('confscheduler_notiftemplate', (object) [
            'confscheduler' => $confscheduler->id,
            'notiftype'     => 'scheduled',
            'subject'       => 'Custom subject [[roomnames]]',
            'body'          => 'Custom body',
            'bodyformat'    => FORMAT_HTML,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        $template = notifier::get_template((int) $confscheduler->id);
        $this->assertSame('Custom subject [[roomnames]]', $template['subject']);
    }

    /**
     * A newly-scheduled presentation slot has a pending (never notified) change,
     * and send_pending_notifications() notifies its speaker and marks it notified.
     */
    public function test_send_pending_notifications_sends_and_marks_notified(): void {
        $this->resetAfterTest();
        global $DB;

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_fixture();
        $speaker = $this->getDataGenerator()->create_user();
        $submissionid = $this->create_accepted_submission_with_speaker($confsubmissions, $confprogram, $speaker);
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        $this->assertSame(1, api::count_pending_notifications((int) $confscheduler->id));

        $sink = $this->redirectMessages();
        $sent = api::send_pending_notifications((int) $confscheduler->id);
        $messages = $sink->get_messages_by_component_and_type('mod_confscheduler', 'scheduleupdated');

        $this->assertSame(1, $sent);
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertSame((int) $speaker->id, (int) $message->useridto);

        $this->assertGreaterThan(0, (int) $DB->get_field('confscheduler_slot', 'notifiedtime', ['id' => $slotid]));
        $this->assertSame(0, api::count_pending_notifications((int) $confscheduler->id));
    }

    /**
     * A slot already notified with no scheduling change since is never re-sent,
     * per the explicit user request ("Do not send notifications to presentations
     * if the scheduling information has not changed").
     */
    public function test_send_pending_notifications_skips_unchanged_slot(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_fixture();
        $speaker = $this->getDataGenerator()->create_user();
        $submissionid = $this->create_accepted_submission_with_speaker($confsubmissions, $confprogram, $speaker);
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        $sink = $this->redirectMessages();
        api::send_pending_notifications((int) $confscheduler->id);
        $sink->clear();

        $sentagain = api::send_pending_notifications((int) $confscheduler->id);
        $this->assertSame(0, $sentagain);
        $this->assertCount(0, $sink->get_messages_by_component_and_type('mod_confscheduler', 'scheduleupdated'));
    }

    /**
     * Rescheduling an already-notified slot makes it pending again (timemodified
     * moves past notifiedtime), and it is re-sent on the next
     * send_pending_notifications() call.
     */
    public function test_reschedule_after_notify_makes_slot_pending_again(): void {
        $this->resetAfterTest();

        [$confscheduler, $confprogram, $confsubmissions] = $this->create_fixture();
        $speaker = $this->getDataGenerator()->create_user();
        $submissionid = $this->create_accepted_submission_with_speaker($confsubmissions, $confprogram, $speaker);
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        $slotid = api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 10:00:00'),
            strtotime('2026-09-01 10:30:00'),
            $submissionid
        );

        $sink = $this->redirectMessages();
        api::send_pending_notifications((int) $confscheduler->id);
        $sink->clear();

        // Backdate notifiedtime: this and the reschedule below can otherwise land
        // within the same second, and notifiedtime < timemodified would then
        // (correctly, but unhelpfully for this test) be false.
        global $DB;
        $DB->set_field('confscheduler_slot', 'notifiedtime', time() - 60, ['id' => $slotid]);

        api::update_slot($slotid, [$roomid], strtotime('2026-09-01 11:00:00'), strtotime('2026-09-01 11:30:00'));

        $this->assertSame(1, api::count_pending_notifications((int) $confscheduler->id));

        $sent = api::send_pending_notifications((int) $confscheduler->id);
        $this->assertSame(1, $sent);
        $this->assertCount(1, $sink->get_messages_by_component_and_type('mod_confscheduler', 'scheduleupdated'));
    }

    /**
     * A column-spanning block (no submissionid) is never notifiable and never
     * counted as pending, regardless of how many times it changes.
     */
    public function test_span_block_never_counted_as_pending(): void {
        $this->resetAfterTest();

        [$confscheduler] = $this->create_fixture();
        $roomid = api::add_room((int) $confscheduler->id, 'Main Hall');

        api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            strtotime('2026-09-01 12:00:00'),
            strtotime('2026-09-01 13:00:00'),
            null,
            'Lunch'
        );

        $this->assertSame(0, api::count_pending_notifications((int) $confscheduler->id));
    }
}
