# Changes

## v0.4.5

- Security: before the linked Conference Program is switched to its Display phase, people who
  cannot edit the schedule (for example students and guests) no longer receive the scheduled
  presentations, unscheduled accepted submissions or speakers' preferred dates through the
  schedule or its calendar (ICS) export. They see only the general sessions (span blocks) until
  the programme is published. Schedule editors see no change.
- Speakers and track are hidden on the schedule and in the calendar export when the Conference
  Program's display settings hide them from the programme list. Schedule editors still see them.
- Presentations can be added to "my timetable" only once the programme has been published.
- Sending the pending schedule-change notifications now also requires the "Manage the
  schedule-change notification template and send pending notifications" capability. The
  schedule capability is now marked as carrying data-loss and spam risk, and the notification
  capability as carrying spam risk.
- Restoring a backup cleans room and slot names, colours, capacity and text alignment, so a
  damaged backup cannot break the schedule grid.
- Course reset now also removes each user's remembered schedule day.
- On short (about 25-minute) cards the room and time stay visible next to the track badge,
  shortening if needed instead of being cut off, and in edit mode the resize grip on short cards
  moves to the corner so it no longer covers the track badge.
- Black or white text on coloured blocks and badges is now chosen by WCAG contrast ratio, which
  reads better on some mid-tone colours.

See `changelog.md` for the full development history.
