# mod_confscheduler

Conference Scheduler — a Moodle activity module that turns accepted submissions from [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) into a drag-and-drop block schedule (time × room grid), with autoscheduling and print support.

Part of the [Conference Tools](https://github.com/adamjenkins/moodle-conference-tools) suite:

- [mod_confsubmissions](https://github.com/adamjenkins/moodle-mod_confsubmissions) — call for abstracts / submissions
- [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) — reviewer vetting workflow + public program display
- **mod_confscheduler** (this plugin) — drag-and-drop block schedule / timetable
- [mod_confcheckin](https://github.com/adamjenkins/moodle-mod_confcheckin) — tickets, badges, QR check-in, certificates

## What it does

- **Edit mode**: a time × room grid. Drag accepted presentations from an unscheduled panel into slots, and reschedule by dragging within the grid. Rooms are editable, colour-themeable, and re-orderable. "GapSnap" enforces a configurable gap between presentations while dragging. An autoscheduler can populate a timespan automatically, prioritising same-session and same-track grouping. Column-spanning blocks support plenaries/lunch.
- **Display mode**: read-only blocks link to the presentation's `mod_confprogram` page, with a "my timetable" highlight toggle synced with favourites. Printable in colour or black & white, at A4/A3/A2 in either orientation.
- Implements the `\mod_confscheduler\api::get_schedule_for_submission()` contract that `mod_confprogram`'s Display phase reads for time/room info, and calls `mod_confprogram`'s `api::add_favourite()`/`remove_favourite()` directly to keep favourites in sync both ways.

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
