# mod_confscheduler

**Conference Scheduler** — a Moodle activity that turns the accepted submissions from [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) into a drag-and-drop time × room schedule, with autoscheduling and print/export support.

*Documentation: English (this file) · [日本語](README.ja.md)*

Part of the [Conference Tools](https://github.com/adamjenkins/moodle-conference-tools) suite:

- [mod_confsubmissions](https://github.com/adamjenkins/moodle-mod_confsubmissions) — call for abstracts
- [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) — reviewer vetting + public program
- **mod_confscheduler** (this plugin) — drag-and-drop block schedule
- [mod_confcheckin](https://github.com/adamjenkins/moodle-mod_confcheckin) — tickets, badges, QR check-in

## What it does

A time × room grid that reads accepted talks from the linked Conference Program instance. Which mode you see follows Moodle's site-wide **Edit mode** switch (edit controls also require `mod/confscheduler:manageschedule`).

**Edit mode**

- **Drag** accepted talks from the unscheduled panel into the grid, and reschedule by dragging within it. A live highlight shows where a block will land.
- **SnapGap** nudges a drop to the nearest valid position instead of rejecting it, keeping a configurable minimum gap between talks. Blocks resize from a grip on their bottom edge; a talk's initial length comes from its submission type's duration.
- **Rooms** are editable, colour-themeable, re-orderable, and can carry an optional capacity. A talk whose favourite count exceeds its room's capacity is flagged as a possible overbooking.
- **Column-spanning blocks** (with their own colour) cover plenaries and breaks, and are editable in place.
- **Autoscheduler** fills a chosen window automatically, grouping same-track talks and honouring submitters' preferred days (a hard constraint by default, with an opt-in override).
- **Quick controls** in the toolbar adjust the minimum gap, row height, and the daily display window live.
- **Send notifications** emails a schedule-change note to every talk whose time/room changed since it was last notified (manual, never re-sent unchanged). Template editable.

**Display mode**

- A read-only rendering of the same grid. Blocks open the talk's Conference Program page (as a link, or an in-place modal with JS).
- A **my timetable** toggle highlights your favourited talks; an **Export (.ics)** link downloads them as an iCalendar file.
- Printable in colour or black & white via a live toggle (CSS only; paper size/orientation left to the browser).
- A day selector pages a multi-day schedule one day at a time.

**Integration & data**

- Implements `\mod_confscheduler\api::get_schedule_for_submission()`, the contract Conference Program reads for time/room, and writes favourites straight into Conference Program (favourite state is owned there, not duplicated here).
- Conference start/end dates are set in the activity settings; placements outside that window are rejected server-side and greyed out in the grid.
- **Backup/restore & course reset** — fully supported. Reset clears the schedule but keeps rooms as configuration.

## Requirements

- Moodle 5.2 (`2026042000`) or later.
- mod_confprogram (and, through it, mod_confsubmissions) installed in the same course.

## Installation

```
git clone https://github.com/adamjenkins/moodle-mod_confscheduler.git mod/confscheduler
php admin/cli/upgrade.php
```

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).

## Author

Adam Jenkins <adam@wisecat.net>
