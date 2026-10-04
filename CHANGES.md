# Changes

## Unreleased

- The Japanese language pack (lang/ja) is no longer included: releases ship the English strings
  only, as the Moodle Plugins directory expects. Japanese is provided through Moodle's language
  packs.

## v0.4.2

First tagged release (the in-development v0.4.1 was never tagged).

Conference Scheduler: a Moodle activity that turns the accepted submissions
from `mod_confprogram` into a drag-and-drop time × room schedule, with
autoscheduling and print/export support. Part of the Conference Tools suite.

- Edit mode: drag accepted talks into a time × room grid, with editable rooms,
  column-spanning blocks, container sessions holding several nested
  presentations, and an autoscheduler that honours submitters' preferred days.
- Display mode: a read-only grid with favourites, a "my timetable" view,
  .ics export of your favourites, and colour or black & white printing.
- Manual schedule-change notifications with an editable template.
- Backup/restore and course reset.
- Declare Moodle 5.3 support (supported on Moodle 5.2–5.3).
- The schedule stays a light, readable surface in Boost's dark colour mode
  (Moodle 5.3, experimental). Light mode is unchanged.
- Installable with Composer (`adamjenkins/moodle-mod_confscheduler`, which
  also requires `adamjenkins/moodle-mod_confprogram` and
  `adamjenkins/moodle-mod_confsubmissions`).
- Releases are published to the camp registry.

See `changelog.md` for the full development history of this version.
