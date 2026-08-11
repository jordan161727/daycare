# User stories — the attendance sheet

Written from the centre's own description of how the sheet is used. Each story
carries the acceptance criteria it is judged by and the test that holds it up, so
"done" is a thing you can check rather than a thing you are told.

Status is one of **Built** (working and covered by a test) or **Open**.

---

## A. Where the schedule comes from

### A1 — A week builds itself from the week before

> **As** the director
> **I want** a new week to open as a duplicate of the week before it
> **so that** I am never filling in a blank grid on Monday morning.

- Opening a week for the first time copies the previous week's ticked pattern.
- Copying is by **weekday**: last Tuesday's pattern lands on this Tuesday.
- If a week was skipped, it builds from the newest week that exists, not from
  nothing.
- Opening the same week twice does not rebuild it.

**Built** — `WeekScheduleTest::opening_a_week_copies_the_pattern_forward_by_weekday`,
`::a_skipped_week_copies_from_the_newest_week_that_exists`,
`::opening_a_week_twice_does_not_rebuild_it`

### A2 — Only the plan travels, never the attendance

> **As** the director
> **I want** the copy to carry the blue/gray pattern and nothing else
> **so that** one sick day never quietly becomes a child's new schedule.

- Sign-ins are not copied when a week opens.
- A child absent last Tuesday is still *scheduled* for this Tuesday.

**Built** — `WeekScheduleTest::attendance_never_copies_forward`

### A3 — A week is independent the moment it exists

> **As** the director
> **I want** edits to stay inside the week I am editing
> **so that** correcting this week never reaches back into a week I already set up.

- Each week holds its own rows; editing one leaves every other week untouched.
- Weeks **not yet created** still pick the change up when they copy forward.

**Built** — `WeekScheduleTest::a_week_is_independent_once_built`,
`ScheduleEditingTest::ticking_days_saves_only_this_week`

### A4 — Rebuild from a different week

> **As** the director
> **I want** to rebuild this week from a week I choose
> **so that** a holiday-wrecked previous week does not mean fixing every box by hand.

- **Copy from another week** lists the weeks on file, last week first and
  pre-selected.
- Each option shows **how many days it has ticked** and flags any **closed days**,
  so a normal week can be told from a ruined one without opening it.
- Two modes: **Add** (keeps what is here, adds theirs) and **Replace** (this week
  becomes an exact match).
- A copy that changes nothing says so instead of claiming success, and points at
  Replace when that is the mode that would help.

**Built** — `ScheduleEditingTest::copying_a_week_from_the_page_redirects_back_with_the_new_pattern`,
`::adding_a_week_keeps_the_days_already_ticked_here`,
`::replacing_a_week_clears_days_the_source_does_not_have`,
`::the_picker_shows_how_busy_each_week_was`,
`::a_copy_that_changes_nothing_says_so_instead_of_claiming_success`

### A5 — The first week is set up by hand

> **As** the director
> **I want** the very first week to start blank
> **so that** nothing is invented before I have said what the pattern is.

- With no earlier week, every box opens unticked.
- The sheet says so: *"First week in the system — set up by hand."*

**Built** — `WeekScheduleTest::the_first_week_opens_empty_because_there_is_nothing_to_copy`

---

## B. Reading a box

### B1 — Four states, at a glance

> **As** a teacher
> **I want** each box to tell me the child's status for that day
> **so that** I can read a week without asking anyone.

| Box | Meaning |
|---|---|
| Light blue | scheduled to attend |
| Gray | not scheduled |
| Nothing (dashed) | not enrolled — not started, or no longer coming |
| Time shown | signed in, with the actual arrival time |

An extra state falls out of the rules: **amber** — signed in on a day they were
not scheduled. Still billable, and visibly unplanned.

**Built** — `ScheduleEditingTest::the_grid_marks_an_unscheduled_sign_in_differently`

### B2 — A gray box still takes a sign-in

> **As** a teacher
> **I want** to sign in a child who turns up on an unscheduled day
> **so that** DSS is billed for attendance that actually happened.

- Clicking a gray box records the sign-in and stamps the time.
- The box turns amber, not green, so the day reads as unplanned.

**Built** — `ScheduleEditingTest::a_gray_day_still_accepts_a_sign_in`

---

## C. Editing a week

### C1 — Edit this week or next

> **As** the director
> **I want** to open any week and edit it
> **so that** I can set next week up before it starts.

- **Set schedule** switches the sheet into edit mode.
- **‹ Prev** and **Next ›** step a week at a time; the date box jumps anywhere.
- Opening a future week builds it, so it can be edited before it arrives.

**Built** — `ScheduleEditingTest::the_page_offers_both_views_and_the_copy_control`,
`::visiting_the_week_builds_its_schedule`

### C2 — Clicking a box cycles it

> **As** the director
> **I want** to click a box to turn a day on or off
> **so that** correcting an exception is one click.

- In **Set schedule**, a click toggles between scheduled and not.
- Dragging across boxes fills a run; **Space** toggles the focused box.
- It saves as you go — there is no save button to forget.

**Built** — `ScheduleEditingTest::ticking_days_saves_only_this_week`

### C3 — A whole row or a whole column at once

> **As** the director
> **I want** to set one child's week, or one day for everyone, in a single move
> **so that** I am not clicking sixty boxes.

- Row: **Full week**, **M W F**, **T Th**, **Clear**.
- Column: tap the day name — ticks it for everyone, tap again to clear.
- The checklist shows **every child in every room**, so a column really is
  everyone. Room and search filters do not apply here.

**Built** — `ScheduleEditingTest::set_schedule_lists_every_child_whatever_the_sign_in_filter`

### C4 — Past weeks freeze

> **As** the director
> **I want** a week to lock once it has ended
> **so that** what was billed cannot be rewritten afterwards.

- After its Friday, a week's schedule is read-only: no ticking, no copying into it.
- The sheet says *"This week has ended — the schedule is locked"* and drops the
  controls rather than showing ones that would be refused.
- Sign-ins already recorded stay readable.

**Built** — `ScheduleEditingTest::a_week_that_has_ended_is_locked`,
`::a_finished_week_cannot_be_copied_into`,
`::the_locked_week_hides_the_schedule_controls`

### C5 — Centre closure days

> **As** the director
> **I want** to close the centre for a day in one move
> **so that** a snow day does not mean graying out every child by hand.

- **Close day** under a column header grays every child in every room at once,
  with a reason recorded.
- A closed day cannot be ticked, and stays gray when a pattern copies into it.
- The closure belongs to that **date** — the same weekday next week is normal.
- A closed day **still accepts a sign-in**, because a child who turns up is
  still billable.

**Built** — `ScheduleEditingTest::closing_a_day_grays_every_child_at_once`,
`::a_closed_day_cannot_be_ticked`,
`::a_holiday_stays_gray_when_the_pattern_copies_forward`,
`::a_closed_day_still_accepts_a_sign_in`

---

## D. Enrolment dates decide whether the box exists

### D1 — The child's record holds the dates

> **As** the director
> **I want** to click a child's name and see their record
> **so that** I can check or change the dates that govern their boxes.

- The name is a link on both the sign-in grid and the checklist.
- The record holds date of birth, enrolment start and enrolment end.
- Admin only — a teacher sees the name as plain text.

**Built** — `ScheduleEditingTest::the_childs_name_opens_their_record_for_an_admin`

### D2 — Before the start date there is no box

> **As** the director
> **I want** nothing at all before a child's start date
> **so that** a child who has not started cannot be scheduled or signed in.

- Not gray — **nothing**, a dashed placeholder reading *"Not enrolled on this date"*.
- A start date of the 12th means boxes begin on the 12th.

**Built** — `WeekScheduleTest::a_child_gets_no_slots_outside_their_enrolment_dates`

### D3 — Both dates are inclusive

> **As** the director
> **I want** the last day to still have a box
> **so that** "August 10th is his last day" means the 10th is a working day.

- End date **10th** → the 10th has a box, the 11th has nothing.
- Start date **12th** → the 12th has a box, the 11th has nothing.

**Built** — `ScheduleEditingTest::a_new_last_day_takes_the_later_boxes_away`,
`::a_later_start_date_takes_the_earlier_boxes_away`

### D4 — Changing the dates fixes weeks already built

> **As** the director
> **I want** a new leaving date to take the later boxes away immediately
> **so that** I do not have to remember which weeks were already created.

- Shortening enrolment removes boxes from weeks that already exist.
- Extending it puts them back.
- **Two exceptions, on purpose:** a day already **signed in** keeps its box — a
  child who was here was here, and DSS bills it. And a **finished week** is
  never rewritten.

**Built** — `ScheduleEditingTest::a_day_already_signed_in_keeps_its_box_when_enrolment_shrinks`,
`::a_finished_week_keeps_its_boxes_when_enrolment_changes`

### D5 — A new child appears by themselves

> **As** the director
> **I want** a newly enrolled child to turn up in the grid from their start date
> **so that** I never add anyone to a week by hand.

- A child added after a week was built still gets a row, from their start date on.
- They start **unticked** — a new child is never silently scheduled.
- Inactive children never appear, in any week.
- **Not into a finished week.** Someone added today does not acquire a schedule
  for a week that ended before they were on the roster.

**Built** — `WeekScheduleTest::a_child_added_after_the_week_was_built_still_gets_boxes`,
`::a_child_added_today_gets_no_boxes_in_a_week_that_has_ended`,
`::a_newly_enrolled_child_starts_unticked_rather_than_scheduled`,
`::inactive_children_are_left_out_of_the_schedule`

---

## E. Access

### E1 — A teacher sees their own room

> **As** the director
> **I want** teachers limited to their own classrooms
> **so that** nobody schedules or signs in another room's children.

- The sheet shows only the rooms assigned to that teacher.
- The server refuses a tick on another room's child regardless of what is sent.

**Built** — `ScheduleEditingTest::a_teacher_cannot_tick_another_rooms_child`,
`TeacherClassroomAccessTest::teacher_only_sees_and_can_sign_in_children_in_assigned_classroom`

### E2 — Sign-ins are for today

> **As** the director
> **I want** sign-ins limited to the current day
> **so that** attendance is recorded as it happens rather than reconstructed.

- Any other date is refused, with a pop-up naming both days.
- To fill a past week's *pattern*, use the copy dialog instead.

**Built** — `AttendanceSheetTest::another_day_is_refused_with_a_reason_the_sheet_can_show`

---

## F. Which room a child is in

`classroom` used to be a free-text box typed by hand. It is now the worked-out
answer — the age bands below, unless the director has overridden it — and the
column every head count, report block and teacher filter reads.

### F1 — The room follows the date of birth

> **As** the director
> **I want** each child's room to be worked out from their date of birth
> **so that** nobody is in the wrong room because a field was typed wrong.

| Room | From | Up to |
|---|---|---|
| Infant | 6 weeks | 18 months |
| Transition | 18 months | 2 years |
| Toddler | 2 years | 3 years |
| PreK | 3 years | 4 years |
| UPK-4 | 4 years | 5 years |
| School Age | 5 years | 12 years |

- Every band is **closed at the top**: the boundary age belongs to the higher
  band. A child on the day they turn 18 months is Transition, not Infant.
- The room is worked out **as of a date**, not once and for ever — a child
  crosses into the next room on their birthday without anyone touching the record.
- A date of birth outside every band — under 6 weeks, or past the School Age
  ceiling — gets **no room**, and the child reads as unassigned. Better a visible
  blank than a wrong room, because that case is nearly always a typo.
- Ages are counted **forward from the date of birth**, and a month end lands on a
  month end: born the 31st of August, 18 months is the end of February. A child
  born a day later never moves up before one born a day earlier.

**Built** — `ClassroomAssignmentTest::each_band_starts_on_the_day_the_child_reaches_it`,
`::a_month_end_birthday_moves_room_on_the_month_end`,
`::a_younger_child_is_never_in_a_higher_band`,
`::a_child_outside_every_band_gets_no_room`,
`::a_child_is_filed_in_the_room_their_age_gives_them`,
`::a_birthday_moves_the_child_without_anyone_touching_the_record`

### F2 — Move a child up early, from the schedule

> **As** the director
> **I want** to set a child's room myself from the schedule page
> **so that** a 17½-month-old who is ready for Transition can go there now.

- The room is editable from the schedule, where the decision is actually made.
- The override belongs to the **child**, not to a day or a week. One child is in
  one room; the schedule is only where it gets set.
- It takes an **effective date**, defaulting to today. See the note below.
- **Clear override** puts the child back on the automatic rule.
- Only the director. Moving a child changes which teacher can see them, so a
  teacher cannot hand a child to another room or take one from it.
- An override cannot **start inside a finished week**. Room decides the ratio
  that had to be staffed, so it is part of what that week records — the same
  reason C4 freezes its schedule.

**Built** — `ClassroomAssignmentTest::the_director_can_move_a_child_up_early`,
`::an_override_does_nothing_before_the_date_it_starts`,
`::an_override_cannot_start_in_a_week_that_has_ended`,
`::a_teacher_cannot_move_a_child_between_rooms`,
`::a_room_outside_the_bands_is_refused`

### F3 — An override never looks like the automatic value

> **As** the director
> **I want** an overridden room to be a different colour
> **so that** I can see at a glance which rooms I chose and which the system did.

- An overridden room is shown in its own colour, distinct from the four box
  states in B1 and from amber.
- Hovering shows the automatic value it replaced: *"Automatic: Infant."*
- Once the child's age catches up to the room they were moved into, the marker
  changes to a **warning** state — the override is doing nothing any more and is
  there to be cleared.
- Violet for an override still moving the child, amber for one their age has
  overtaken, and a `✎` / `⚠` marker beside it so the two never rely on colour
  alone.

**Built** — `ClassroomAssignmentTest::an_override_the_age_has_caught_up_with_is_flagged_but_kept`

### F4 — An override holds until it is cleared

> **As** the director
> **I want** my choice to stick
> **so that** a child I moved into Transition is not back in Infant tomorrow.

- Nothing recalculates over the top of an override: not a birthday, not opening a
  new week, not editing the child's record.
- It survives the child ageing **past** the room they were moved into. The room
  is stale at that point, so F3 flags it, but the system does not quietly undo a
  decision the director made.
- Only **Clear override** returns the child to automatic.
- A room typed straight onto a record the rule cannot place — no date of birth,
  or one outside every band — is kept and recorded as the override it is, rather
  than being dropped on the floor.

**Built** — `ClassroomAssignmentTest::an_override_survives_a_birthday`,
`::opening_a_week_never_reassigns_an_overridden_child`,
`::clearing_an_override_hands_the_child_back_to_their_age`,
`::a_room_set_by_hand_on_a_child_with_no_date_of_birth_is_kept`

### F5 — Head counts read the overridden room

> **As** the director
> **I want** every count to use the room the child is actually in
> **so that** the ratio for that room is the one I have to staff.

- A child moved into Transition counts against **Transition**, and no longer
  against Infant, everywhere a room is counted or grouped.
- Places that read the room today: the report's room blocks and daily totals
  ([ReportController.php:58](../app/Http/Controllers/ReportController.php#L58)),
  the `Tr / Toddler` and `PreK & UPK-4` subtotals, the dashboard's room count
  ([AttendanceController.php:65](../app/Http/Controllers/AttendanceController.php#L65)),
  the AM/PM split for School Age (`Child::sessions()`), and teacher visibility
  ([Child::scopeVisibleTo](../app/Models/Child.php#L90)).
- Teacher visibility follows the override too: move a child into Transition and
  the Transition teacher can sign them in, the Infant teacher cannot.
- Moving in or out of **School Age** turns a full day into AM and PM. The week's
  boxes are reshaped to match, and the day's tick survives the split — a finished
  week and any day already signed in are left alone, as everywhere else.

**Built** — `ClassroomAssignmentTest::the_report_counts_an_overridden_child_in_their_new_room`,
`::teacher_visibility_follows_the_override`,
`::moving_into_school_age_splits_the_day_into_am_and_pm`,
`::a_day_already_ticked_stays_ticked_when_the_day_splits`,
`::a_day_already_signed_in_keeps_its_box_when_the_day_splits`

Ratios themselves do not exist yet — this story is head counts being right. See G4.

---

## Still open

| # | Story | Note |
|---|---|---|
| G1 | A teacher can view a child's record read-only | The record is admin-only, so teachers see the name as plain text. Needs a read-only profile page if teachers should see enrolment dates. |
| G2 | DSS reporting | The rule "bill actual attendance" is honoured throughout, but what DSS needs on paper has not been described yet. |
| G3 | Closure days as a managed list | Closures are set from the column header. There is no page listing the year's holidays to plan ahead. |
| G4 | Staff ratios | F5 makes head counts read the right room. Nothing yet turns a head count into "this room needs three staff". |
