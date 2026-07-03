# mod_confscheduler

Conference Scheduler — a Moodle activity module that turns accepted submissions from [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) into a drag-and-drop block schedule (time × room grid), with autoscheduling and print support.

Part of the [Conference Tools](https://github.com/adamjenkins/moodle-conference-tools) suite:

- [mod_confsubmissions](https://github.com/adamjenkins/moodle-mod_confsubmissions) — call for abstracts / submissions
- [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) — reviewer vetting workflow + public program display
- **mod_confscheduler** (this plugin) — drag-and-drop block schedule / timetable
- [mod_confcheckin](https://github.com/adamjenkins/moodle-mod_confcheckin) — tickets, badges, QR check-in, certificates

## What it does

- **Edit mode**: a time × room grid. Drag accepted presentations from an unscheduled panel into slots, and reschedule by dragging within the grid. Rooms are editable, colour-themeable, and re-orderable, with column header text colour automatically switching between black/white for legibility against the chosen colour. "GapSnap" enforces a configurable gap between presentations while dragging. An autoscheduler can populate a timespan automatically, prioritising same-session and same-track grouping. Column-spanning blocks (with their own optional colour theme, same auto-contrast text) support plenaries/lunch, and are fully editable in place after creation, not just add/delete. Track pill badges link through to the linked `mod_confprogram` instance's accepted-submissions list, filtered to that track. A day selector pages a multi-day schedule one calendar day at a time.
- **Display mode** (`mod/confscheduler:viewschedule` without `:manageschedule`): a read-only rendering of the same grid data. Blocks link to the presentation's `mod_confprogram` page (both a real `<a href>` fallback and, with JS, an in-place modal identical to `mod_confprogram`'s own). A "my timetable" toggle highlights favourited presentations and greys out the rest, persisted in `sessionStorage` per instance. The same day selector as edit mode pages a multi-day schedule. Printable in colour or black & white, at A4/A3/A2 in either orientation, via CSS only (no PDF generation).
- Implements the `\mod_confscheduler\api::get_schedule_for_submission()` contract that `mod_confprogram`'s Display phase reads for time/room info, and calls `mod_confprogram`'s `api::add_favourite()`/`remove_favourite()` directly to keep favourites in sync both ways.
- Organisers can declare conference start/end dates in the activity's General settings section (purely informational for now -- not yet derived from or validated against scheduled slots). Dark mode is currently disabled site-wide for this plugin (the CSS is kept, just inert) pending a possible future reintroduction.

## Architecture notes

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
- **"Session" grouping is plugin-local (Phase 3.4)**: the autoscheduler's
  "keep same-session presentations consecutive" priority needs a "session"
  grouping concept, but no such concept exists anywhere in the shipped
  `mod_confsubmissions`/`mod_confprogram` schema — only "track" does. Rather
  than modifying those already-shipped, committed, security-reviewed sibling
  plugins, `confscheduler_sessiontag` (a table local to this plugin, FK'd to
  `confscheduler` and cross-plugin-referencing a `confsubmissions_submission`
  id the same way `confscheduler_slot.submissionid` already does) implements
  it as an organiser-assigned label scoped entirely to a single
  `confscheduler` instance. Two submissions sharing the same non-empty label
  within the same instance are "the same session" for the autoscheduler; see
  `classes/api.php`'s `set_session_tag()`/`get_session_tags()`/
  `run_autoscheduler()` docblocks for the full design and the exact
  placement algorithm.
- **Autoscheduler placement search**: `run_autoscheduler()` deliberately does
  not re-implement `validate_placement()`'s GapSnap/overlap math a second
  time. Every candidate placement it considers is attempted via `add_slot()`
  itself (wrapped in a try/catch): a rejected candidate is simply skipped in
  favour of the next one, and a successful attempt IS the real, final
  placement — there is no separate "simulate then commit" step. This trades
  a little redundant validation-query overhead for a guarantee that the
  autoscheduler can never place something `add_slot()` would have refused.
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
