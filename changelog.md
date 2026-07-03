# Changelog

## Unreleased

- Phase 3.5: read-only Display mode, "my timetable" toggle, day/page pagination
  (shared between Display and edit modes), and print support. All client-side
  over the existing `get_grid_data` payload -- no new AJAX endpoints, no
  schema changes.
  - `view.php`'s `mod/confscheduler:viewschedule`-only branch now renders
    `templates/display.mustache` + `amd/src/scheduler_display.js` instead of
    the old placeholder notification. Deliberately a **separate** module from
    the edit grid rather than a shared renderer with a `canEdit` flag: the
    edit grid's block rendering is tightly interleaved with `core/dragdrop`
    state (drag proxies, resize handles, mid-drag dataset reads), and
    threading a read-only flag through every one of those paths would have
    made the already-shipped, security-reviewed DnD grid harder to read for
    no benefit to either mode. See README's "Architecture notes" for the
    full rationale.
  - Blocks link through to the presentation's `mod_confprogram` page two
    ways at once: a real `<a href>` to that activity's own view page (built
    via the new `classes/local/display_link.php`, tested), which is the
    honest no-JS/fallback/new-tab destination since `mod_confprogram` has no
    URL-parameter convention to deep-link a specific submission's modal and
    must not be modified to add one; and, when JS is available, a click
    handler that calls `mod_confprogram`'s own
    `mod_confprogram_get_submission_detail` AJAX endpoint directly and shows
    the identical `core/modal` that plugin's own list uses
    (`amd/src/scheduler_display.js`'s `openProgramDetail()`) -- a browser-side
    call into another plugin's already-hardened, capability- and
    phase-embargo-checked public external function, the same
    direct-API-coupling pattern this plugin's PHP layer already uses for
    favourites, requiring zero changes to `mod_confprogram`.
  - "My timetable" toggle: pure client-side highlight/grey-out over the
    `favourited` field `get_grid_data` already returns; state persists in
    `sessionStorage` per confscheduler instance so it survives page
    navigations within a visit (no existing UI-state-persistence convention
    to match -- the edit grid's fullscreen toggle uses the live Fullscreen
    API, not persisted storage -- so this establishes the pattern).
  - Day/page pagination: a new shared, pure, framework-agnostic
    `amd/src/day_utils.js` module (day-boundary grouping, default-day
    selection preferring "today" else the earliest day) used by **both**
    the new Display mode and, as a shared improvement, the existing
    interactive edit-mode grid (`scheduler_grid.js` gained a day `<select>`
    in its toolbar). This was judged low-risk to add to the edit grid
    because day filtering only narrows which slots feed
    `computeTimeline()`/`renderBody()`'s rendering -- the DnD math itself
    (hit-testing, `yToTime()`/`timeToY()`) already operated relative to
    `state.timelineStart`, which was already being recomputed per render, so
    scoping it to one day's bounds instead of the whole instance's needed no
    changes to the drag/resize/drop handlers themselves. Verified live via
    adversarial drag testing after the change rather than assumed safe.
  - Print support: colour and black-and-white modes, A4/A3/A2 paper sizes,
    portrait/landscape orientation, entirely via CSS (`@media print` rules
    plus a dynamically-written `@page` rule -- `@page` cannot itself be
    scoped under a class selector, so `applyPageSize()` rewrites a dedicated
    `<style>` element's content instead). B&W mode strips room
    colour-theming down to plain borders/text. A live adversarial check
    caught a real bug here: the print toolbar's own `.d-flex` Bootstrap
    utility class carries `display: flex !important`, which has equal
    specificity to a single-class `!important` hide rule and was winning the
    cascade tiebreak by load order, so the toolbar was NOT actually hiding
    under `@media print` despite the rule being present. Fixed by using a
    two-class compound selector (`.mod_confscheduler-toolbar.mod_confscheduler-print-toolbar`),
    which has strictly higher specificity and wins regardless of load order.
  - No new database schema and no new AJAX endpoints: `get_grid_data`
    (already gated on the weaker `:viewschedule` capability specifically to
    also serve this mode) and the existing `toggle_favourite` endpoint are
    reused as-is.

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
