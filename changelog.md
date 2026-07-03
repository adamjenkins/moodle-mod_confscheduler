# Changelog

## Unreleased

- Revision round 1 (user feedback, 2026-07-03): dark mode disabled, room/span-block
  colour auto-contrast text, conference start/end date settings, track-pill spacing +
  click-through, span-block colour picker + edit-in-place. Schema bumped twice
  (`2026070404` conference dates, `2026070405` span-block colour); see `db/upgrade.php`.
  - **Dark mode disabled**: `styles.css`'s `@media (prefers-color-scheme: dark)` rules
    are now wrapped under a selector that can never match, rather than deleted --
    real design work kept inert, not lost, per the explicit "may be reintroduced
    later" feedback. The plugin now always renders its light-mode styling.
  - **Room/span-block colour auto-contrast text**: a new shared, pure
    `amd/src/colour_utils.js` module (`hexToRgb()`/`perceivedBrightness()`/
    `contrastTextColour()`) picks black or white text automatically for any element
    whose background is a room's or span block's chosen hex colour, using the YIQ
    "perceived brightness" formula (not full gamma-corrected WCAG relative
    luminance -- simpler, no gamma-correction step, and sufficient for a binary
    black/white text choice; threshold 128 on the 0-255 scale). Wired into room
    column headers in both `scheduler_grid.js` and `scheduler_display.js`, and into
    span blocks (see below) in both modules.
  - **Conference start/end dates**: new nullable `confscheduler.conferencestart`/
    `conferenceend` unix-timestamp fields, settable via `date_time_selector` elements
    in `mod_form.php`'s **General** section (not a separate settings screen, per
    explicit feedback). Not derived from or validated against any scheduled slot yet
    -- purely an organiser-declared setting. Non-blocking validation (`conferenceend`
    must be after `conferencestart` when both are set) matches
    `mod_confsubmissions`'s `timeopen`/`timeclose` validation convention. The
    `date_time_selector`'s "optional" checkbox yields `0` (not `null`) when
    unchecked; `lib.php`'s new `confscheduler_normalise_conference_dates()` converts
    that to a real `null` before storage, to match the nullable column semantics.
  - **Track pill spacing + click-through**: `.mod_confscheduler-track-pill` now has
    `margin-bottom: 0.5em`. Track pills (grid blocks, the unscheduled panel, and
    Display-mode blocks) are now real `<a>` links to the linked `mod_confprogram`
    instance's accepted-submissions list, filtered to that track
    (`mod/confprogram/view.php?id=<confprogramcmid>&trackid=<id>`), with a
    descriptive `aria-label` beyond the bare track name. Requires a new
    `grid_data.php`/`get_grid_data.php` `trackid` field alongside the existing
    `track` name, and `view.php` now resolves the linked `mod_confprogram` activity's
    base URL for both edit and Display mode (previously Display-mode-only). A small,
    narrowly-scoped, additive change to the sibling `mod_confprogram` plugin makes
    this filter actually work there -- see that plugin's own changelog entry.
  - **Span-block colour picker + edit-after-creation**: new nullable
    `confscheduler_slot.colour` column (same type/length as
    `confscheduler_room.colour`), validated the same way via `api::validate_colour()`,
    applying only to label-only span-block slots (`submissionid IS NULL`) --
    `add_slot()` now rejects a non-null colour given together with a non-null
    submissionid. `templates/spanblock_form.mustache` gained a colour picker + "no
    colour" checkbox (mirroring `room_form.mustache`) and a hidden `slotid` field so
    the same modal serves both add and edit. Span blocks were previously add/delete
    only; a new `mod_confscheduler_update_span_block` AJAX endpoint (backed by a new
    `api::update_span_block()`, which refuses to operate on a presentation slot) and
    an edit (pencil) button on each span block now support editing label/colour/
    time/room-range in place, following the exact `scheduler_context_trait`
    IDOR-scoping pattern (instance-scoped slot id, identical not-found/wrong-instance
    message) every other write endpoint here already uses.

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
