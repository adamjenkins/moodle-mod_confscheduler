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
