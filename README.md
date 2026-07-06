# mod_confscheduler

Conference Scheduler — a Moodle activity module that turns accepted submissions from [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) into a drag-and-drop block schedule (time × room grid), with autoscheduling and print support.

Part of the [Conference Tools](https://github.com/adamjenkins/moodle-conference-tools) suite:

- [mod_confsubmissions](https://github.com/adamjenkins/moodle-mod_confsubmissions) — call for abstracts / submissions
- [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) — reviewer vetting workflow + public program display
- **mod_confscheduler** (this plugin) — drag-and-drop block schedule / timetable
- [mod_confcheckin](https://github.com/adamjenkins/moodle-mod_confcheckin) — tickets, badges, QR check-in, certificates

## What it does

- **Edit mode** (shown only while Moodle's own site-wide "Edit mode" switch is on, for a `mod/confscheduler:manageschedule` holder -- see "Architecture notes" below, not just holding the capability): a time × room grid. Drag accepted presentations from an unscheduled panel into slots, and reschedule by dragging within the grid; a live highlight shows exactly where a drop will land (including any SnapGap nudge) while dragging. SnapGap automatically nudges a dropped/dragged block to the nearest valid position instead of hard-rejecting an invalid drop, and its minimum-gap setting is itself a quick control in the grid toolbar rather than a settings-form field (see "Architecture notes" below). A newly-scheduled block's initial length comes from its own `mod_confsubmissions` submission type's configured duration, and can still be freely resized afterwards via a visible grip handle on its bottom edge. Row height (pixels per hour) is also a quick control in the grid toolbar, letting an organiser make the whole timeline taller or more compact. Each block's title starts on the same line as its favourite-star icon, wrapping around it (clamped to two lines with an ellipsis if it's still too long), rather than losing a whole line of vertical space to the star alone. Rooms are editable, colour-themeable, and re-orderable, with column header text colour automatically switching between black/white for legibility against the chosen colour. An autoscheduler can populate a timespan automatically, prioritising same-track grouping. Column-spanning blocks (with their own optional colour theme, same auto-contrast text) support plenaries/lunch, and are fully editable in place after creation, not just add/delete. Track pill badges reflect the track's own configured colour (with automatic black/white contrast text, falling back to a default blue when a track has none) and link through to the linked `mod_confprogram` instance's accepted-submissions list, filtered to that track. A day selector pages a multi-day schedule one calendar day at a time.
- **Display mode** (Moodle's site-wide Edit mode off, or `mod/confscheduler:viewschedule` without `:manageschedule`): a read-only rendering of the same grid data. Blocks link to the presentation's `mod_confprogram` page (both a real `<a href>` fallback and, with JS, an in-place modal identical to `mod_confprogram`'s own). A "my timetable" toggle highlights favourited presentations and greys out the rest, persisted in `sessionStorage` per instance. The same day selector as edit mode pages a multi-day schedule. Printable in colour or black & white (a live on-screen toggle, not print-only -- user feedback, 2026-07-06), via CSS only (no PDF generation); paper size and orientation are left entirely to the browser's own print dialog.
- Implements the `\mod_confscheduler\api::get_schedule_for_submission()` contract that `mod_confprogram`'s Display phase reads for time/room info, and calls `mod_confprogram`'s `api::add_favourite()`/`remove_favourite()` directly to keep favourites in sync both ways.
- Organisers declare conference start/end dates in the activity's General settings section: `api::validate_placement()` rejects any placement outside that window server-side, the edit-mode grid greys out (and the client-side SnapGap nudge logic in `conference_bounds_utils.js` clamps drags away from) out-of-bounds hours, and the autoscheduler defaults its run window to these dates. Dark mode is currently disabled site-wide for this plugin (the CSS is kept, just inert) pending a possible future reintroduction.
- Rooms have an optional capacity, shown in the column header when set. A scheduled presentation in a single room whose capacity is exceeded by its `mod_confprogram` favourite count is highlighted (edit mode only) as a possible overbooking -- informational, not a hard restriction.
- A "Send notifications" button in the edit-mode toolbar manually sends a schedule-change email/notification to every presentation whose scheduling information has changed since it was last notified (never automatic, and never re-sent to an unchanged presentation), with an organiser-editable template.
- Display mode's "my timetable" toolbar has an "Export my timetable (.ics)" link that downloads the current user's own favourited, scheduled presentations as an iCalendar file (via Moodle's own Bennu library), importable into any calendar app.
- Full course backup/restore support, and course reset (clears the schedule -- every slot and its room assignments -- but keeps rooms as instance configuration, likely reused for a new conference).

## Architecture notes

- **The "Run autoscheduler" and "Add/edit span block" modals use explicit year/month/day/hour/minute
  `<select>` groups (`amd/src/datetime_select_utils.js`) instead of a native `<input type="datetime-local">`**:
  a native datetime-local input's value is always ISO internally, but the widget the browser draws for it
  follows the browser/OS locale, not the page or site language -- an en-US browser renders it mm/dd/yyyy
  regardless of what the site is configured to. This matches the same reasoning behind this plugin's own
  instance-settings mform using Moodle's `date_time_selector` element instead of a plain date input.
- **Drag-and-drop**: the grid uses core's `core/dragdrop` AMD module for free-form
  2D block placement (it exposes `prepare()`/`start()` and leaves hit-testing on
  raw page X/Y to the caller, which suits a time × room grid better than a
  list-oriented module). Room/column header reordering uses core's
  `core/sortable_list` AMD module instead (1D list reorder), since that's a
  better fit for reordering a row of headers than `dragdrop` is.
- **Cross-plugin contract**: `classes/api.php::get_schedule_for_submission()`
  implements the read contract `mod_confprogram`'s
  `classes/local/schedule_info.php` depends on (see that file's docblock for
  the shared contract definition). This plugin also calls
  `mod_confprogram\api::add_favourite()` / `remove_favourite()` directly for
  the "my timetable" toggle — favourite state is owned by `mod_confprogram`,
  not duplicated here.
- **`add_slot()` validation requirement**: when the scheduling grid is built,
  `add_slot()` must validate that a given `submissionid` actually belongs to
  a submission accepted by the `confprogram` instance referenced by this
  scheduler's own `confprogramcmid`, before inserting — a security review of
  the scaffold flagged that without this check, submission ids are globally
  unique (not per-course), so an unvalidated write could leak another
  course's schedule data into this course's Display phase. See `SUMMARY.md`
  in the coordination repo for the full finding.
- **"Session" grouping was removed entirely (Revision round 1 batch B,
  2026-07-03)**: Phase 3.4 originally gave the autoscheduler a
  "keep same-session presentations consecutive" priority tier, backed by a
  plugin-local `confscheduler_sessiontag` table (an organiser-assigned label
  scoped to a single `confscheduler` instance, since no "session" concept
  exists anywhere in the shipped `mod_confsubmissions`/`mod_confprogram`
  schema — only "track" does). Per explicit user feedback ("In the
  unscheduled blocks there is a 'session' setting, this should be removed"),
  this was removed outright rather than merely hidden: the table is dropped
  by a real `db/upgrade.php` step (not just abandoned in the schema),
  `api::set_session_tag()`/`get_session_tags()` and the
  `mod_confscheduler_set_session_tag` AJAX endpoint are deleted, the inline
  session-tag input is gone from the unscheduled panel, and
  `run_autoscheduler()` now documents two priority tiers (same-track
  same-room preference, then unconstrained) instead of three. See
  changelog.md for the full removal list.
- **Autoscheduler placement search**: `run_autoscheduler()` deliberately does
  not re-implement `validate_placement()`'s SnapGap/overlap math a second
  time. Every candidate placement it considers is attempted via `add_slot()`
  itself (wrapped in a try/catch): a rejected candidate is simply skipped in
  favour of the next one, and a successful attempt IS the real, final
  placement — there is no separate "simulate then commit" step. This trades
  a little redundant validation-query overhead for a guarantee that the
  autoscheduler can never place something `add_slot()` would have refused.
- **Candidate start times are seeded per calendar day, not just per existing
  slot (fixed 2026-07-05, user feedback: "Autoscheduler is not respecting
  preferred dates")**: this scheduling model has no "business hours reset
  each day" concept — only one continuous `[conferencestart, conferenceend]`
  span. `candidate_start_times_for_room()` therefore also seeds one candidate
  per calendar day in the window (at the window start's own time-of-day), not
  just the window start plus each existing slot's end — otherwise an empty
  room's only reachable candidate was ever the window's first day, since a
  room's later days only became reachable once its own slots already chained
  sequentially that far. This made a submitter's preferred day unreachable
  whenever it wasn't the window's first day, in the single most common
  real-world case: running the autoscheduler once, from scratch.
- **Preferred dates are a hard constraint by default (2026-07-05 follow-up,
  user feedback: "That should return a '1 could not be placed' message")**:
  once the seeding fix above made a preferred day genuinely reachable,
  `try_place_single()` was changed to only ever consider candidates on one of
  a submission's preferred days when it has any recorded — if none of them
  have room, the submission is skipped (reported via the existing "N could
  not be placed" summary) rather than silently falling back to a
  non-preferred day. The "Run autoscheduler" modal's new **"Ignore preferred
  dates"** checkbox (`$ignorepreferreddates` parameter, off by default)
  restores the old soft-preference fallback for a given run. A slot ending
  up on a non-preferred day either way (manual drag, or an
  ignore-preferred-dates run) is flagged `nonpreferredday` by
  `grid_data::build()` and highlighted only in the edit-mode grid
  (`scheduler_grid.js`) — the read-only Display mode (`scheduler_display.js`)
  never reads that field, so it renders identically either way.
- **Display mode is a separate AMD module, not a shared renderer with an
  edit/read-only flag (Phase 3.5)**: `amd/src/scheduler_grid.js`'s block
  rendering is tightly interleaved with `core/dragdrop` state — drag
  proxies, resize handles, mid-drag reads of a block's own `dataset` — and
  threading a `canEdit`/read-only flag through every one of those code paths
  would have made the already-shipped, security-reviewed drag-and-drop grid
  harder to read for no real benefit to either mode (a read-only caller has
  no use for any of that state machinery). `amd/src/scheduler_display.js` is
  instead a smaller, dedicated module that duplicates only the minimal
  block-positioning math (`COLUMN_WIDTH`/`PX_PER_MINUTE`, kept in comments
  cross-referencing the edit grid's copies the same way `styles.css` already
  cross-references both), fetches the same `mod_confscheduler_get_grid_data`
  payload, and renders it without any drag affordances. The genuinely
  *shared* logic — day-boundary computation — **is** factored out, into
  `amd/src/day_utils.js` (pure, framework-agnostic: no DOM access, no
  module-level state), and used by both modules; see the next point.
- **Day/page pagination is shared between edit and Display modes (Phase
  3.5)**: `amd/src/day_utils.js` groups a slot list by local calendar day
  and picks a sensible default (today if the schedule spans it, else the
  earliest day). Both `scheduler_grid.js` and `scheduler_display.js` render
  one day's slots at a time via a day `<select>` in their toolbar. Adding
  this to the edit grid (not just Display mode, where it was clearly
  needed) was judged low-risk rather than a large rewrite of already-shipped
  DnD code, because day filtering only narrows *which slots feed*
  `computeTimeline()`/`renderBody()`'s rendering — the drag/resize/drop
  handlers themselves already operated on absolute unix timestamps derived
  from `state.timelineStart` (already recomputed on every render), so
  scoping that to one day's bounds instead of the whole instance's needed no
  changes to the handlers themselves, only to what range they're computed
  over. This was verified live after the change (not just assumed safe): an
  adversarial drag that would overlap a neighbouring block was correctly
  rejected by the server with the block reverting in place, and a valid
  drag on the day-filtered timeline correctly persisted the new time.
- **Presentation-detail link-through calls `mod_confprogram`'s own AJAX
  endpoint directly, cross-plugin (Phase 3.5)**: a Display-mode block's
  primary link is a real `<a href>` to the linked `mod_confprogram`
  activity's own view page (built by `classes/local/display_link.php`,
  unit-tested) — the honest fallback destination, since `mod_confprogram`
  has no URL-parameter convention to deep-link a specific submission's
  detail modal, and per this project's cross-plugin rules `mod_confprogram`
  must not be modified to add one. When JS is available, clicking the block
  instead calls `mod_confprogram`'s own public
  `mod_confprogram_get_submission_detail` external function directly (the
  identical AJAX call `mod_confprogram`'s own list view makes,
  `amd/src/programlist.js`) and shows the same `core/modal` it would show on
  its own page. This introduces no new IDOR surface: that endpoint is
  already independently hardened (capability + Display-phase embargo +
  accept-decision + instance checks, all internal to `mod_confprogram`) and
  already globally callable by anyone who can reach `webservice/ajax.php`
  with the right capability, regardless of which plugin's JS happens to call
  it — calling it from here does not weaken any of those checks.
- **Print support is CSS-only (Phase 3.5)**: colour/black-and-white and
  A4/A3/A2 portrait/landscape are implemented via `@media print` rules plus
  a dynamically-written `@page` rule (`@page` cannot itself be scoped under
  a class selector, so `scheduler_display.js`'s `applyPageSize()` rewrites a
  dedicated `<style>` element's content on each control change instead of
  pre-declaring every combination as static CSS). A live adversarial check
  caught a real bug here during verification: the print toolbar's own
  `.d-flex` Bootstrap utility class carries `display: flex !important`,
  which has equal specificity to a single-class `!important` hide rule and
  was winning the cascade tiebreak by load order, so the toolbar was not
  actually hiding under `@media print` despite the rule being present.
  Fixed with a two-class compound selector
  (`.mod_confscheduler-toolbar.mod_confscheduler-print-toolbar`), which has
  strictly higher specificity and wins regardless of load order. No PDF
  generation, per the project's explicit non-goal for this phase.
- **Known follow-up, not built this phase**: the edit-mode grid's
  unscheduled-presentations panel is not day-scoped (by design — an
  unscheduled submission has no time yet, so there is no day to scope it
  to), and the "my timetable"/print controls are Display-mode-only per
  spec, not added to the edit grid.
- **Colour contrast is a shared pure helper, like day/page pagination
  (Revision round 1, 2026-07-03)**: `amd/src/colour_utils.js` (YIQ "perceived
  brightness", not full gamma-corrected WCAG relative luminance -- simpler
  and sufficient for a binary black/white text choice) is used by both
  `scheduler_grid.js` and `scheduler_display.js` wherever a room's or span
  block's chosen hex colour is used as a background, following the same
  "factor genuinely shared pure logic into its own module, don't share the
  DOM-heavy rendering code itself" pattern `day_utils.js` established in
  Phase 3.5.
- **Track-pill click-through needed one narrowly-scoped addition to
  `mod_confprogram`**: unlike the presentation-detail link-through (which
  calls an already-public `mod_confprogram` external function with zero
  changes to that plugin), filtering the accepted-submissions list by track
  required a new `trackid` query parameter on `mod_confprogram`'s own
  `view.php`, plus a new `display_list::filter_by_track()` method there.
  This is the one place in this phase that touches the sibling plugin
  (authorised narrowly for this feature only) -- it follows the exact
  pattern that file's existing `day` query parameter already uses (a
  request parameter that only narrows an already-instance-scoped list), and
  the trackid is verified to belong to the confsubmissions instance this
  confprogram vets before its name is ever echoed back, the same
  chain-of-custody discipline `RELATIONS.md` documents for every other
  caller-supplied cross-plugin id in this project.
- **Span blocks previously had no edit path**: only add/delete existed.
  `api::update_span_block()` (backed by a new
  `mod_confscheduler_update_span_block` AJAX endpoint) refuses to operate on
  anything but a span block (`submissionid IS NULL`) -- a data-integrity
  check, not a capability check, so it lives in `classes/api.php` rather
  than the external function, per this class's own documented split between
  the two kinds of checks.
- **Edit-mode gating reuses Moodle's own site-wide Edit mode switch, checked
  in addition to the capability, not a replacement for it (Revision round 1
  batch B, 2026-07-03)**: `view.php` previously dispatched purely on
  `mod/confscheduler:manageschedule`. Per explicit user feedback, holding the
  capability now only makes the interactive grid *available*; whether it
  actually renders also depends on `$PAGE->user_is_editing()` -- the same
  course-wide "Edit mode" switch already shown in the page header and already
  restricted to editing-capable roles, not a plugin-bespoke toggle. An
  earlier version of this feature built exactly that -- its own toggle
  control, a persisted `mod_confscheduler_editmode_<id>` user preference, and
  a dedicated AJAX endpoint to set it -- but that was deliberately scrapped
  in favour of the site's existing switch once it became clear a second,
  differently-named toggle doing a conceptually identical job would only
  confuse organisers already familiar with the real one. `view.php` derives
  `$editmode` as `$canmanage && $PAGE->user_is_editing()`, exactly mirroring
  how `mod_confprogram`'s own `view.php` already gates its organiser
  controls -- no new capability, AJAX endpoint, schema, or stored preference
  was needed, and this plugin still stores no personal data of its own. This
  does not change any existing write-path security boundary: it only changes
  which read-only-vs-interactive UI a `manageschedule` holder sees by
  default.
- **SnapGap now auto-nudges instead of hard-rejecting on drag-drop (Revision
  round 1 batch B, 2026-07-03)**: per explicit user feedback, a drop that
  would violate SnapGap or truly overlap another block in the same room is no
  longer submitted as-is to be rejected with an error notification. A new,
  pure `amd/src/snapgap_utils.js` module re-implements
  `api::validate_placement()`'s overlap/gap math client-side (there is no way
  to call the PHP version synchronously mid-drag -- this is the one place in
  the project where the same validation logic genuinely needs to exist in
  both languages, and the two files cross-reference each other in their
  docblocks to stay in sync by hand) and computes the nearest valid position,
  searching both forward and backward in time and preferring whichever is
  closer to the raw drop position (ties broken in favour of forward/later,
  matching the original feedback's own example -- "nudges ... eg. 5 min
  later"). That computed position, not the raw drop position, is what
  `beginScheduleDrag()`/`beginMoveDrag()` submit; the server's
  `validate_placement()` is completely unchanged and remains the sole
  authoritative check, so a bug in the client-side nudge math can at worst
  cause a server rejection (falling back to the pre-existing
  error-notification+revert path, deliberately kept as a safety net for the
  genuine "room is completely packed, no valid nudge exists" case) -- never
  an actually-invalid placement being saved. Not applied to block resizing
  (out of this batch's explicit scope; a resize that violates SnapGap still
  hard-rejects as before). No JS unit-test harness exists anywhere in this
  project as of this writing, so `snapgap_utils.js`, like `day_utils.js`/
  `colour_utils.js` before it, is verified live rather than via an automated
  JS unit test.
- **The SnapGap minimum-gap setting lives in the grid toolbar, not the
  settings form (Revision round 1 follow-up, 2026-07-04)**: per explicit
  feedback, `mod_form.php`'s "Scheduling settings" section (which held only
  this one field) is gone; a quick control in `templates/grid.mustache`
  (visible only to a `manageschedule` holder) reads and writes
  `confscheduler.gapminutes` live via a new
  `mod_confscheduler_set_gap_minutes` AJAX endpoint. This mirrors why room/
  track/submission-type management already live on their own screens: it is
  organiser-facing configuration that only makes sense once the instance
  already exists.
- **Row height is a quick control in the grid toolbar too (user feedback,
  2026-07-05)**: `confscheduler.pxperhour` (vertical pixels per hour of
  scheduled time, default 144 -- the previous hard-coded value, so existing
  instances render unchanged until an organiser adjusts it; valid range
  60-480, enforced server-side in `api::set_pxperhour()`) follows the exact
  same pattern as `gapminutes` above: a number input next to it in
  `templates/grid.mustache`, a `mod_confscheduler_set_pxperhour` AJAX
  endpoint, no `mod_form.php` field. Both `amd/src/scheduler_grid.js` (edit
  mode) and `amd/src/scheduler_display.js` (read-only Display mode) read it
  from the same `mod_confscheduler_get_grid_data` payload the grid already
  fetches and use it to compute every block's pixel position/height and the
  hourly gridline spacing (`styles.css`'s repeating-gradient background,
  scaled per instance via an inline `background-size`), so both modes always
  render at the same organiser-configured density. Landed together with a
  CSS fix for scheduled blocks wasting their entire first line on just the
  favourite star: the remove/favourite/edit-span-block buttons now `float`
  instead of sitting `position: absolute` over a reserved blank line, so the
  title/label text wraps around them starting on that same first line.
- **Room-capacity overbooking is an informational warning, not an enforced limit**: nothing stops an organiser from scheduling into a room whose capacity a presentation's favourite count already exceeds -- unlike SnapGap/overlap validation (`validate_placement()`, enforced server-side on every placement), a room may legitimately be worth overbooking (standing room, a bigger room being unavailable that slot), so this is deliberately advisory only, edit-mode-only highlighting (same convention as the existing "non-preferred day" highlight) rather than a hard rejection.
- **Schedule-change notifications are manually triggered, never automatic** (user request, 2026-07-05): unlike `mod_confsubmissions`'s and `mod_confprogram`'s notifiers (which fire automatically on submit/withdraw/decision), this plugin's own notifier only ever sends when an organiser clicks "Send notifications" in the edit-mode toolbar -- a schedule is typically rearranged many times while being built, and notifying speakers on every drag would be noise. Change-tracking (`confscheduler_slot.notifiedtime` compared against the existing `timemodified`) ensures a presentation whose scheduling information hasn't changed since it was last notified is silently skipped, so re-clicking the button after sending is always safe (a no-op for anything already up to date). Same organiser-editable-template/built-in-default/plain-`[[name]]`-placeholder conventions as the sibling plugins' notifiers, but scoped to a single 'scheduled' notification type (no phase-embargo concept applies here, since notifications.php's template only affects future manually-triggered sends, and there is no "revealed to speakers" concept to gate on).
- **A per-instance notifications master switch** (`confscheduler.notificationsenabled`, default on) overrides the schedule-change notification template. `notifier::notify_slot()` returns a bool so `api::send_pending_notifications()` only marks a slot's `notifiedtime` once a send was actually attempted -- a slot with a pending change stays pending while disabled (not silently marked "notified"), so re-enabling and clicking "Send notifications" again still delivers it.
- **ICS export reuses `grid_data::build()` rather than re-querying** (`classes/local/ics_export.php`, user request 2026-07-06): "my timetable" already means exactly "this user's favourited, scheduled presentations", and `grid_data::build()` already resolves everything an event needs (title, speakers, track, room names, per-user favourited flag) with the same N+1-avoiding queries the grid AJAX endpoint depends on -- filtering that same payload to `submissionid !== null && favourited` avoids a second, parallel data-assembly path that could drift out of sync with what the grid itself shows as favourited. Uses Moodle's own Bennu iCalendar library (`lib/bennu`, the same one core's `calendar/export_execute.php` uses), not a bespoke ICS string builder. `export.php` is a plain (non-AJAX) download endpoint, same pattern as `calendar/export_execute.php`/`mod_confcheckin`'s `badge.php` -- a plain `<a href>` in Display mode's toolbar, no JS needed. Always exports the current user's own favourites; there is no userid parameter, since "my timetable" only ever means "my own". A user with no favourites gets a validly-serialized, empty calendar, not an error.
- **Backup includes the whole schedule unconditionally, never gated on the "include user info" setting** (user request, 2026-07-06): unlike the sibling plugins, no table in this plugin's own schema stores a userid or any other personal data -- a scheduled slot is course CONTENT (structurally closer to a course's own assignment due dates than to a submitted answer), not a record of who did what, so it always travels with the backup, the same way `mod_choice`'s `choice_options` always do. `confprogramcmid` and every presentation slot's `submissionid` are cross-activity references resolved in `after_restore()`, not during the main restore structure step, for the same reason as the sibling plugins (restore order across activities in the same course backup is not guaranteed until every activity's main structure step has completed). A presentation slot whose `submissionid` can't be resolved (the linked `mod_confsubmissions` instance wasn't included in the same backup/restore) is deleted outright rather than left with a dangling reference -- nulling it instead would misrepresent it as a column-spanning block, which it is not.

## Requirements

- Moodle 5.2 (`2026042000`) or later.
- mod_confprogram installed in the same course.

## Installation

```
git clone https://github.com/adamjenkins/moodle-mod_confscheduler.git mod/confscheduler
php admin/cli/upgrade.php
```

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).

## Author

Adam Jenkins <adam@wisecat.net>
