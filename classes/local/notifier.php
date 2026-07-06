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

use mod_confsubmissions\api as submissions_api;

/**
 * Sends this plugin's schedule-change notification (user request, 2026-07-05: "a
 * manually triggered (with a button) way to send notifications when changes are
 * made to the scheduling information for a presentation. Do not send
 * notifications to presentations if the scheduling information has not
 * changed") via Moodle's own core notification system, with an
 * organiser-editable template (notifications.php, confscheduler_notiftemplate) --
 * same conventions as mod_confprogram\local\notifier and
 * mod_confsubmissions\local\notifier (built-in default fallback, a plain fixed
 * `[[name]]` placeholder delimiter).
 *
 * Unlike its two sibling notifiers, sending is never automatic: this class only
 * ever runs from \mod_confscheduler\api::send_pending_notifications(), which is
 * itself only ever called from the manually-triggered
 * mod_confscheduler_send_pending_notifications AJAX endpoint (the edit-mode
 * "Send notifications" button). The "only when changed since last notified"
 * requirement is enforced by that api() method comparing each
 * confscheduler_slot's notifiedtime against its timemodified -- this class
 * itself does not check that; it unconditionally notifies whatever slot id it
 * is given.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifier {
    /**
     * The built-in fallback subject/body for the (single) 'scheduled' notification
     * type, used until an organiser configures their own via notifications.php.
     *
     * @return array{subject: string, body: string}
     */
    public static function default_template(): array {
        return [
            'subject' => get_string('notifdefaultsubject:scheduled', 'mod_confscheduler'),
            'body'    => get_string('notifdefaultbody:scheduled', 'mod_confscheduler'),
        ];
    }

    /**
     * The configured subject/body for this confscheduler instance's schedule-change
     * notification, or default_template()'s fallback if unset/blank.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @return array{subject: string, body: string, bodyformat: int}
     */
    public static function get_template(int $confschedulerid): array {
        global $DB;

        $template = $DB->get_record('confscheduler_notiftemplate', [
            'confscheduler' => $confschedulerid,
            'notiftype'     => 'scheduled',
        ]);

        $default = self::default_template();

        $subject = ($template && trim((string) $template->subject) !== '') ? $template->subject : $default['subject'];
        $body = ($template && trim((string) $template->body) !== '') ? $template->body : $default['body'];
        $bodyformat = $template->bodyformat ?? FORMAT_HTML;

        return ['subject' => $subject, 'body' => $body, 'bodyformat' => (int) $bodyformat];
    }

    /**
     * Substitutes every `[[name]]` placeholder in $text with its value in $context,
     * or '' if $context has no entry for that name.
     *
     * @param string $text The subject or body text
     * @param array $context Placeholder name => replacement value
     * @return string
     */
    public static function render(string $text, array $context): string {
        return preg_replace_callback(
            '/\[\[(\w+)\]\]/',
            static fn (array $matches): string => $context[$matches[1]] ?? '',
            $text
        );
    }

    /**
     * Notifies every real (userid-backed) speaker on a scheduled slot's
     * presentation that its scheduling information (time/room) is as currently
     * shown. The caller (api::send_pending_notifications()) is solely
     * responsible for only calling this for a slot that is actually a
     * presentation (non-null submissionid) with a pending (un-notified) change.
     *
     * @param int $confschedulerid The confscheduler instance id
     * @param \stdClass $slot The confscheduler_slot record (submissionid must be non-null)
     * @param string[] $roomnames The name(s) of every room this slot occupies
     * @return bool True if a send was attempted (the instance's master switch is on);
     *         false if it was skipped (no confscheduler/submission record, or
     *         notificationsenabled is off). The caller, api::send_pending_notifications(),
     *         only marks a slot's notifiedtime when this returns true -- otherwise it stays
     *         pending, so a later re-enable still delivers it.
     */
    public static function notify_slot(int $confschedulerid, \stdClass $slot, array $roomnames): bool {
        global $DB;

        $confscheduler = $DB->get_record('confscheduler', ['id' => $confschedulerid]);
        if (!$confscheduler || !$confscheduler->notificationsenabled) {
            return false;
        }

        $submission = submissions_api::get_submission((int) $slot->submissionid);
        if (!$submission) {
            return false;
        }

        $template = self::get_template($confschedulerid);
        $course = get_course((int) $confscheduler->course);

        $context = [
            'submissiontitle' => format_string($submission->title),
            'coursename'      => format_string($course->fullname),
            'roomnames'       => implode(', ', $roomnames),
            'starttime'       => userdate((int) $slot->starttime),
            'endtime'         => userdate((int) $slot->endtime),
        ];

        foreach (submissions_api::get_speakers((int) $slot->submissionid) as $speaker) {
            if (empty($speaker->userid)) {
                continue;
            }
            $touser = \core_user::get_user((int) $speaker->userid);
            if (!$touser || $touser->deleted) {
                continue;
            }

            $speakercontext = $context + ['fullname' => format_string(fullname($touser))];

            self::send(
                $touser,
                self::render($template['subject'], $speakercontext),
                self::render($template['body'], $speakercontext),
                $template['bodyformat'],
                (int) $confscheduler->course
            );
        }

        return true;
    }

    /**
     * Builds and sends one \core\message\message via message_send() -- see
     * \mod_confsubmissions\local\notifier::send()'s docblock for why this is what
     * makes "sent by email as well by default" free, and for why send failures
     * are caught and swallowed rather than allowed to propagate (a best-effort
     * notification must never break the real action -- here, the organiser's
     * "Send notifications" button click -- that triggered it).
     *
     * @param \stdClass $touser The recipient user record (a FULL record, not a
     *        trimmed-down one -- message_send() needs it)
     * @param string $subject Already placeholder-rendered
     * @param string $body Already placeholder-rendered
     * @param int $bodyformat FORMAT_HTML or FORMAT_PLAIN
     * @param int $courseid The course id, used to build the contexturl
     * @return void
     */
    private static function send(\stdClass $touser, string $subject, string $body, int $bodyformat, int $courseid): void {
        $bodyhtml = $bodyformat === FORMAT_HTML ? $body : nl2br(s($body));

        $message = new \core\message\message();
        $message->component = 'mod_confscheduler';
        $message->name = 'scheduleupdated';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $touser;
        $message->subject = $subject;
        $message->fullmessageformat = FORMAT_HTML;
        $message->fullmessage = html_to_text($bodyhtml);
        $message->fullmessagehtml = $bodyhtml;
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
        $message->contexturlname = get_string('pluginname', 'mod_confscheduler');

        try {
            message_send($message);
        } catch (\Throwable $e) {
            // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
            error_log('mod_confscheduler notification send failed: ' . $e->getMessage());
        }
    }
}
