# Changelog

## Unreleased

- User feedback (2026-07-05): "Autoscheduler is not respecting preferred dates."
  Root cause: `candidate_start_times_for_room()` only ever seeded the window start
  itself plus (each existing slot's endtime + gap) as candidates for a room -- there
  is no "business hours reset each day" concept anywhere in this scheduling data
  model, only one continuous `[conferencestart, conferenceend]` span. For a
  genuinely empty room (the normal case for the first submissions placed into a
  fresh, multi-day conference), day 2/3/etc. were therefore structurally
  unreachable: a room's later days only ever became reachable once its OWN existing
  slots already chained sequentially all the way there, which does not happen when
  running the autoscheduler once from scratch. This silently made a submitter's
  preferred day unreachable whenever it wasn't the window's own first day -- the
  single most common real-world use of this feature. The original feature's own
  tests never caught this: `test_run_autoscheduler_honours_preferred_dates`
  deliberately pre-occupied one room with a whole day-1 span block specifically to
  force a genuine day-1-vs-day-2 candidate choice to exist at all, and that
  workaround's own docblock already flagged (but did not itself fix) that an
  ordinary empty room has only one candidate. Fixed by having
  `candidate_start_times_for_room()` also seed one additional candidate per
  calendar day in the window, at the same time-of-day as the window start (e.g.
  window start 09:00 on day 1 seeds 09:00 on day 2, day 3, ...) -- still subject to
  the same window-bounds filter and the same authoritative overlap/gap
  re-validation (`attempt_place()`/`add_slot()`) as every other candidate, so a
  seeded time that turns out to collide with something already scheduled is simply
  skipped like any other rejected candidate. New test:
  `test_run_autoscheduler_honours_preferred_dates_in_a_fresh_multiday_conference`
  (two rooms, both empty, two submissions each preferring a different non-first
  day, looped across 5 seeds) -- reproduced the bug before the fix (both
  submissions landed on day 1 regardless of preference) and passes after it.
- User feedback (2026-07-05): "the autoscheduler should randomly shuffle the order
  in which it schedules presentations. Of course it still needs to honour the
  tracks rule ... and the preferred days if that is set, but after that, the order
  the presentations appear is should be randomized every time the autoscheduler
  runs." Previously, only which track group (and the ungrouped set) got processed
  first was shuffled -- submissions *within* a single track group were always
  attempted in ascending submission-id order, so which one landed in the earliest
  slot of that group's shared room was fully deterministic. That group's own
  member order is now shuffled too, using the same seeded random source as
  everything else, so it's reproducible under a fixed test seed but genuinely
  random in production. The two rules this must still respect are untouched by
  this change: same-track submissions still preferentially land in the same room
  (that logic doesn't depend on processing order), and a submission with a
  recorded date preference still only lands on one of its preferred days
  (try_place_single()'s day-preference partition runs per-submission, independent
  of what order submissions are processed in). New test,
  `test_run_autoscheduler_shuffles_order_within_a_track_group`, places the same
  4-submission track group across 6 seeds and asserts at least one produces a
  different chronological order. 124/124 PHPUnit passing.
- User feedback (2026-07-05): "the column widths should be responsive to fill all
  the available space (like in css grid repeat(1fr)). There should be a min-width
  of 200px." Room columns (`.mod_confscheduler-room-header`/`-room-column`) now
  use `flex: 1 1 200px; min-width: 200px` instead of a fixed `flex: 0 0 200px`, so
  a small room set stretches to fill the available width instead of leaving empty
  space, while a large one still triggers the existing horizontal scroll once
  columns hit the 200px floor. This required more than a CSS change: the drag grid
  previously assumed a fixed, hard-coded `COLUMN_WIDTH` (200px) for every pixel
  computation -- block/preview positioning now uses percentages of the columns
  container's own width instead (correct regardless of actual rendered width),
  and the two drag-time mouse-position-to-room-index conversions now measure the
  real rendered column width live via `getBoundingClientRect()` (only safe at
  drag-time, once layout is guaranteed complete, unlike at initial render). Live-
  verified: 2 rooms in a wide viewport stretch to fill it (each ~656px in a test
  at 1800px), the same 2 rooms settle just above the 200px floor in a narrow
  viewport without triggering scroll, and a debug-instrumented drag confirmed the
  live-measured room-index computation lands on the intended column. 123/123
  PHPUnit passing, eslint/AMD rebuild clean.
- User feedback (2026-07-05): "the autoscheduler should try to honor these
  [submitter-preferred] preferences (time of day should still be shuffled) and in
  edit mode, the unscheduled presentation block should not show presentations if a
  non-preferred day is selected." `api::run_autoscheduler()`'s placement search
  (`try_place_single()`) now stably partitions its candidate list by whether a
  candidate falls on one of the submission's preferred days (from
  `mod_confsubmissions\api::get_date_preferences()`) before its existing
  track-overlap-avoidance partition, so a day-preference match always outranks
  same-track-elsewhere avoidance -- without touching which candidates exist or
  their own shuffled order, so time-of-day randomisation is unaffected. A
  submission with no recorded preference (the common case) is unaffected entirely.
  The edit-mode unscheduled-submissions panel now also hides a submission outright
  when the currently-selected single day isn't one of its preferred days (still
  shows everything in "All days" mode, where dragging is disabled anyway). New
  tests: `test_run_autoscheduler_honours_preferred_dates` (across 5 seeds, to prove
  the day-preference partition isn't shuffle-order-dependent) and
  `test_run_autoscheduler_places_freely_with_no_date_preference`. Known limitation
  documented in `amd/src/scheduler_grid.js`: the unscheduled-panel filter's day-key
  comparison is subject to the same browser-local-timezone-vs-Moodle-configured-
  timezone caveat as the rest of this module's day-key handling (see
  `amd/src/day_utils.js`) -- caught live during testing (a headless browser
  defaulting to UTC against a server configured for Europe/London genuinely showed
  the inverted day), not something this feature can fix without touching that
  pre-existing, already-accepted architecture.
- Added a Japanese (`lang/ja/confscheduler.php`) language pack, translating every
  string in `lang/en/confscheduler.php` (verified live: every key present in both,
  no extras or omissions on either side; confirmed rendering correctly in-browser,
  including the newly-added row-height/"All days"/conference-date strings).
- User feedback (2026-07-05): conference start/end dates are now **required**
  (`mod_form.php` keeps the `optional => true` checkbox, unchecked by default, so a
  genuinely-unset state is still representable for validation to reject -- an earlier
  attempt that removed `optional => true` entirely was a real bug, since a
  `date_time_selector` without it always submits a real timestamp defaulting to "now",
  so a bare `empty()` check could never fire; caught live before being reverted). This
  range now drives: the day selector's list of selectable days (`DayUtils.
  selectableDayKeys()`, unioned with any day that already has a slot so legacy data is
  never hidden); a new **"All days" view** in both edit mode and read-only Display
  mode, rendering every day as its own complete table instead of one continuous
  timeline (dragging is disabled while "All days" is showing -- a viewing-only mode
  for that state; every click-based interaction still works); the autoscheduler
  modal's default time window; and a new **greyed-out out-of-conference-hours band**
  (`DayUtils.outOfHoursBands()`) with a matching **server-side rejection** in
  `api::validate_placement()` (a no-op when either date is unset, for backward
  compatibility with existing instances) and a client-side "bounce back" nudge
  (`amd/src/conference_bounds_utils.js`, mirroring the existing SnapGap pattern).
  **`moodle-reviewer` caught a real gap**: `api::update_span_block()` (the "edit span
  block" pencil-modal path) was not forwarding the new conference-bounds check at all,
  unlike `add_slot()`/`update_slot()` -- fixed, with a new covering test. 125/125
  PHPUnit passing (was 115), phpcs/moodlecheck/eslint clean, AMD rebuilt from scratch
  and diff-verified stable. Extensive live verification (Playwright): day selector
  correctly conference-range-scoped; greyed bands verified via direct DOM inspection
  at the exact expected pixel offsets; "All days" correct in both modes; autoscheduler
  defaults correct; required-date validation correctly blocks submission (this is
  what caught the `date_time_selector` bug above).
- User feedback (2026-07-05), found during a live bug-hunt session: scheduled blocks
  wasted their entire first line on just the favourite-star icon, with the title
  pushed below it by a fixed top margin. Fixed by floating the remove (×) and
  favourite (★) buttons instead of absolutely positioning them, so the title/label
  text now wraps around them starting on the same first line -- clamped to two lines
  with an ellipsis (`-webkit-line-clamp`) so a long title degrades gracefully rather
  than being hard-clipped mid-word at a very short block height. Landed together with
  a new organiser-adjustable row-height setting (`confscheduler.pxperhour`, default
  144 px/hour, range 60-480, validated server-side): a quick control in the grid
  toolbar right next to SnapGap's, following that exact same pattern (a new
  `mod_confscheduler_set_pxperhour` AJAX endpoint, no `mod_form.php` field), read by
  both edit mode and read-only Display mode from the same
  `mod_confscheduler_get_grid_data` payload so both always render at the same
  density. New tests: `tests/api_test.php::test_set_pxperhour`,
  `tests/external/set_pxperhour_test.php`; existing `tests/confscheduler_test.php`/
  `tests/external/get_grid_data_test.php` updated for the new field/payload key.
  `moodle-reviewer` pass: approved, 0 critical/high/medium findings; 2 lows both
  fixed (a stale docblock comment referencing the removed `PX_PER_MINUTE` constant,
  and the title-clipping edge case above, found only by deliberately testing a long
  title at the new minimum row height).
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

- Revision round 1 batch B (user feedback, 2026-07-03): the "session" tagging feature
  removed entirely, edit-mode gating (reusing Moodle's own site-wide Edit mode switch,
  separate from merely holding the capability), and a SnapGap UX redesign (auto-nudge
  instead of hard-reject on drag-drop). Schema bumped once (`2026070406`, dropping
  `confscheduler_sessiontag`); see `db/upgrade.php`.
  - **"Session" tagging removed**: per explicit feedback ("In the unscheduled blocks
    there is a 'session' setting, this should be removed"), this reverses part of
    Phase 3.4's autoscheduler design. This is a genuine removal, not a stop-using: the
    `confscheduler_sessiontag` table is dropped by a real `db/upgrade.php` step
    (`$dbman->table_exists()` + `$dbman->drop_table()`), not merely abandoned in the
    schema. Removed: `api::set_session_tag()`/`get_session_tags()` (and the
    now-unused `try_place_group_consecutive()` helper, confirmed unused elsewhere
    before deleting); the `mod_confscheduler_set_session_tag` AJAX endpoint
    (`classes/external/set_session_tag.php`), its `db/services.php` registration, and
    its test file; the inline session-tag `<input>` and its change handler in the
    unscheduled panel (`scheduler_grid.js`'s `renderUnscheduledPanel()`/
    `onSessionTagChange()`) and its CSS; the `sessiontag` field from
    `grid_data.php`'s payload and `get_grid_data.php`'s return schema; every
    session-tag lang string and the now-inapplicable "session-grouping labels"
    mention in `privacy:metadata`; and the session-tag-specific PHPUnit tests
    (deleted outright, not left failing or commented out).
    `api::run_autoscheduler()`'s placement priority was previously three tiers
    (same-session-tag consecutive-same-room, then same-track same-room preference,
    then unconstrained); it is now two (same-track same-room preference, then
    unconstrained), with its docblock updated accordingly. A thorough `grep -rin
    session` sweep across the whole plugin (not just the obvious files) was used to
    find every reference before starting the removal.
  - **Edit-mode gating**: whether the interactive drag-and-drop grid can be used is now
    gated on Moodle's own site-wide "Edit mode" switch (`$PAGE->user_is_editing()`),
    not purely on holding `mod/confscheduler:manageschedule`. An initial version of
    this built a plugin-bespoke "Schedule edit mode" toggle with its own persisted
    user preference and AJAX endpoint; per explicit follow-up feedback, that was
    scrapped in favour of reusing the course's existing Edit mode switch -- it is
    already visible in the page header, already restricted to roles with editing
    capabilities, and users already understand what it does, so a second, plugin-
    specific toggle would only have added confusion. `view.php` now derives
    `$editmode` as `$canmanage && $PAGE->user_is_editing()`, exactly mirroring how
    `mod_confprogram`'s own `view.php` already gates its organiser controls. A
    `manageschedule` holder sees the same read-only Display mode as everyone else
    while course editing is off; turning course editing on reveals the interactive
    grid. (An initial version of this also added a small notification pointing at
    the real switch; per further feedback that was removed too, as unnecessary --
    the switch is already visible in the page header on its own.) No new
    capability, AJAX endpoint, schema, or stored preference was needed -- this
    plugin still stores no personal data of its own, so `classes/privacy/provider.php`
    remains a `null_provider`, unchanged.
  - **SnapGap UX redesign (auto-nudge instead of hard-reject)**: per explicit feedback
    ("SnapGap should automatically have sessions 'bounce' off existing blocks without
    throwing error:gapviolation or error:timeoverlap type errors ... automatically
    nudges (or snaps) the blocks the appropriate distance away"), a drag-and-drop drop
    that would violate SnapGap or truly overlap another block in the same room is no
    longer submitted as-is to be hard-rejected. A new, pure `amd/src/gapsnap_utils.js`
    module re-implements `api::validate_placement()`'s exact overlap/gap math
    client-side (`starttime < otherend && endtime > otherstart` for overlap; `gap =
    starttime >= otherend ? starttime - otherend : otherstart - endtime` compared
    against the configured gap otherwise; both span-block-exempt the same way) --
    this is the one place in the project where the same validation logic genuinely
    needs to exist in two languages (there is no way to call PHP synchronously
    mid-drag on the client), and the two implementations cross-reference each other
    in their docblocks to stay in sync by hand. `beginScheduleDrag()`/
    `beginMoveDrag()` in `scheduler_grid.js` now compute the nearest valid position
    (searching both forward and backward in time from the raw drop position, picking
    whichever is closer, ties broken toward forward/later per the feedback's own "eg.
    5 min later" example) and submit THAT position; the server's
    `validate_placement()` is completely unchanged and remains the sole
    authoritative check, so a client-side nudge bug can at worst cause a server
    rejection, never an actually-invalid save. If no valid position exists nearby
    (room genuinely packed), the raw position is submitted and the pre-existing
    error-notification+revert path is deliberately kept as the fallback for that
    edge case. Not applied to block resizing (out of this batch's explicit scope).
    Verified live with a deliberately crowded room (see the live-verification notes
    for this round); no JS unit-test harness exists anywhere in this project, so this
    module -- like `day_utils.js`/`colour_utils.js` before it -- relies on that live
    verification rather than an automated JS unit test.

- Revision round 1, follow-up (user feedback, 2026-07-04): a live drag-position
  highlight, a visible resize handle, and per-submission-type scheduling durations.
  - **Live drag-preview highlight**: per explicit feedback ("the new start/end
    times should be highlighted, and clearly visible while dragging so that when
    dropped, the block can be exactly where the user wanted it"), `core/dragdrop`'s
    `onMove` callback (previously a no-op in both `beginMoveDrag()` and
    `beginScheduleDrag()`) now drives a live-updating dashed overlay + time-range
    label via a shared `showDragPreview()`/`clearDragPreview()` pair. Both
    functions' `onMove` and `onDrop` handlers call the identical `computeTarget()`
    closure, so the preview shown while dragging can never disagree with where a
    drop right now would actually land -- including any SnapGap auto-nudge.
    Verified live: a drop always lands exactly where the preview last showed, and
    a deliberately conflicting drag correctly showed the nudge computing a
    backward position matching the eventual drop precisely.
  - **Visible resize handle**: per explicit feedback, the bottom-edge resize hit
    area -- previously a fully invisible 6px strip with only a cursor change --
    now renders a visible grip bar, tinted via a CSS custom property to match a
    span block's own auto-contrast text colour so it stays legible against any
    chosen background. Display mode's read-only blocks have no resize handle
    element at all, so this only affects the interactive edit-mode grid.
  - **Per-submission-type scheduling durations**: a new submission is given the
    duration of its own `mod_confsubmissions` submission type (falling back to a
    fixed `api::DEFAULT_DURATION_MINUTES` for a submission with none) instead of
    a single fixed 30-minute default, both when dragged out of the unscheduled
    panel and when placed by the autoscheduler -- the block can still be resized
    afterwards via the handle above without affecting the type's own setting. The
    "Run autoscheduler" modal's "Default duration" input was removed entirely
    (and `run_autoscheduler()`'s `$defaultdurationminutes` parameter along with
    it) rather than left in place doing nothing, since a single uniform override
    became meaningless once every submission carries its own type-based duration.
  - 80/80 PHPUnit passing (one obsolete "rejects invalid duration" test removed
    along with the parameter it tested), phpcs/moodlecheck clean, AMD rebuilt from
    scratch and confirmed byte-stable. Verified live: the unscheduled panel
    correctly carried a real submission's configured type duration end to end into
    a newly-scheduled block's actual length; the autoscheduler modal no longer
    shows a duration field.

- Revision round 1, follow-up (user feedback, 2026-07-04): **renamed "GapSnap" to
  "SnapGap"** (the feature's actual intended name) throughout the plugin -- lang
  strings, code comments/docblocks, the `amd/src/gapsnap_utils.js` module (renamed
  to `amd/src/snapgap_utils.js`, along with its `GapSnapUtils` import alias in
  `scheduler_grid.js`, now `SnapGapUtils`), test names, and this project's own
  documentation. The underlying `gapminutes` column/setting name is unchanged
  (it never actually said "gapsnap"), so this is a display-text and identifier
  correction, not a schema rename.
  - **The SnapGap minimum gap setting moved out of the activity's settings form**,
    per explicit feedback ("the SnapGap ... setting should appear at the top of
    the schedule when edit mode is on, rather than in the module settings").
    `mod_form.php`'s "Scheduling settings" section (which held only this one
    field) is gone entirely. A new quick control in the grid toolbar
    (`templates/grid.mustache`, visible only to a `manageschedule` holder) reads
    and writes it live via a new `mod_confscheduler_set_gap_minutes` AJAX
    endpoint (`classes/external/set_gap_minutes.php` / `api::set_gap_minutes()`),
    following the same `scheduler_context_trait` IDOR-scoping and capability
    gating every other write endpoint here uses. This mirrors why room/track/
    submission-type management already live on their own screens rather than in
    the settings form: it is organiser-facing configuration that only makes
    sense once the instance already exists. Schema unchanged; version bumped
    (`2026070407`) purely to register the new external function, matching the
    established convention for a services-only change (see
    `mod_confsubmissions`'s own `db/upgrade.php` for the precedent).
  - 85/85 PHPUnit passing (was 80), phpcs/moodlecheck clean, AMD rebuilt from
    scratch and confirmed byte-stable. Verified live: the "Scheduling settings"
    section and its field are gone from the activity's settings page; the quick
    control appears in the grid toolbar in edit mode, initialises from the
    instance's real stored value, and a change persists immediately to the
    database via AJAX.

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
  blocks (Lunch/Plenary), a server-authoritative SnapGap + true-overlap
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
  (wrapped in try/catch) rather than re-implementing its SnapGap/overlap
  math, so the autoscheduler can never place something `add_slot()` would
  have refused. New `mod_confscheduler_run_autoscheduler` AJAX endpoint
  and "Run autoscheduler" modal (time window, default duration, optional
  "clear existing schedule in window first").
