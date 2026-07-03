# Changelog

## Unreleased

- Initial scaffold: schema (`confscheduler`, `confscheduler_room`,
  `confscheduler_slot`, `confscheduler_slotroom`), capabilities
  (`:manageschedule`, `:viewschedule`, `:favourite`, `:addinstance`), a
  `null_provider` privacy provider (this plugin's own tables store no
  personal data), and a `classes/api.php` integration surface.
- Implemented `api::get_schedule_for_submission()` for real (not a stub) —
  this is the read contract `mod_confprogram`'s Display phase already
  depends on. Other `api.php` methods (`get_rooms`, `get_slots`,
  `add_room`, `add_slot`) remain schema-only stubs pending the
  drag-and-drop grid feature.
- DnD research spike: `core/dragdrop` chosen for 2D block placement in the
  grid, `core/sortable_list` for room/column header reordering. See
  README's "Architecture notes".
- `moodle-reviewer` security pass: approved as-is, no critical/high
  findings. Flagged one forward-looking requirement to carry into the
  grid build — `add_slot()` must validate a submission belongs to this
  scheduler's own linked `confprogram` instance before inserting, since
  submission ids are global, not per-course.
- Phase 3.3: the drag-and-drop schedule grid (edit mode). Room CRUD with
  hex-colour theming and drag-reorder (`core/sortable_list`), block
  scheduling/rescheduling/unscheduling via `core/dragdrop`, column-spanning
  blocks (Lunch/Plenary), a server-authoritative GapSnap + true-overlap
  check with correct inclusive-boundary math, an instant favourite-star
  toggle that calls `mod_confprogram\api::add_favourite()`/
  `remove_favourite()` directly, fullscreen toggle, and track pill badges.
  Implemented the chain-of-custody check flagged by the scaffold review:
  `add_slot()` now verifies a submission was actually accepted by *this*
  scheduler's own linked `confprogram` instance specifically (not merely
  accepted by some confprogram instance somewhere), compensating for
  `mod_confprogram\api::get_decision()` not itself being instance-scoped.
  Every external function instance-scopes every room/slot id it's given
  via a shared `scheduler_context_trait`, following the IDOR-prevention
  pattern established in the sibling plugins.
- `moodle-reviewer` re-pass on the grid feature: approved, no
  critical/high/medium findings. Two lows: a dead lang string (removed),
  and a same-user cross-instance favourite-display inconsistency whose
  root cause is `mod_confprogram\api::is_favourited()` not being
  confprogram-instance-scoped — tracked as a known open item in the
  coordination repo's `RELATIONS.md`/`SUMMARY.md`, not fixed here since
  it lives in the already-shipped sibling plugin.
- Phase 3.4: the autoscheduler. New `confscheduler_sessiontag` table
  implements a plugin-local "session" grouping label (no such concept
  exists in the shipped `mod_confsubmissions`/`mod_confprogram` schema)
  via `api::set_session_tag()`/`get_session_tags()`, exposed through an
  inline input in the unscheduled panel and a new
  `mod_confscheduler_set_session_tag` AJAX endpoint, chain-of-custody
  checked the same way `add_slot()` is. `api::run_autoscheduler()` places
  accepted-but-unscheduled submissions into an organiser-chosen time
  window in three priority tiers (same-session-tag consecutive-same-room,
  then same-track same-room preference with soft different-room-overlap
  avoidance, then unconstrained), with per-tier processing order
  (and per-placement room search order) shuffled via a self-contained
  seedable RNG so re-runs vary without mutating PHP's global RNG state.
  Every candidate placement is attempted through `add_slot()` itself
  (wrapped in try/catch) rather than re-implementing its GapSnap/overlap
  math, so the autoscheduler can never place something `add_slot()` would
  have refused. New `mod_confscheduler_run_autoscheduler` AJAX endpoint
  and "Run autoscheduler" modal (time window, default duration, optional
  "clear existing schedule in window first").
