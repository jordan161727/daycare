# AngelNest — Work Notes (Feature Modifications by Phase)

Source: `AngelNest-mockups_1.html` (14 screens).
Purpose: check each feature one by one against the live system and backend.
Not included: the "Kiosks · door + rooms" screen (sample only, not part of this work).

## How to use this file

- `[ ]` not checked yet · `[x]` done and matches the mockup · `[~]` partly done / needs fix
- **MODIFY** = the module is already live, update it to match the mockup.
- **NEW** = the mockup marks it NEW in the sidebar, so it may need new backend work.
- Finish one phase before moving to the next. Later phases read data from earlier ones.
- Mockup names, phone numbers and IDs are placeholders, not real data.
- `docs/manual-test.md` is the hand walk-through for Phase 1 to Phase 9 end to end (26 Oct 2026); `phase2-manual-test.md` and `phase5-manual-test.md` go deeper on their own pages.

---

## Design system (use this for every screen)

All values come from the mockup's CSS, so new screens match the approved Teacher Attendance look.

### Copy-paste prompt

```
Build this screen for AngelNest (daycare admin app) using this design system:

Font: "Instrument Sans" (Google Fonts, weights 400/500/600/700), fallback ui-sans-serif, system-ui, sans-serif.
Page background #f2f4f9, main text #1c2130, secondary text #5f6676, muted text #8a90a0.
Primary color #1b5e91 (buttons, active states, links on hover). Links #4c5a9e.
Cards: white, 1px border #e7e9f0, radius 16px, padding 16–18px.
Buttons: 13px/600, radius 10px, padding 9px 14px, min-height 38px, border #cfd3de, white bg.
Primary button: bg + border #1b5e91, white text.
Chips/filters: 13px/600, radius 999px, padding 6px 12px, border #e1e4ec; active chip bg #1c2130, white text.
Pills/status: 12px/600, radius 999px, padding 3px 10px.
  ok #e3f5ec/#15803d · warn #fef3c7/#92400e · bad #ffe4e6/#be123c · info #dcebfa/#1b5e91 · muted #f2f4f9/#5f6676 · missing #fff1e6/#b45309 dashed #f0b84a.
Room colors: Infant #8cc4b2, Transition #e0b577, Toddler #b3a3dc, PreK #9dc585, UPK-4 #e8a0b8, School Age #92b4df.
Sidebar: 232–260px wide, gradient #c8d2ea → #b6c3e6, padding 20px 14px; nav item 14px/500, radius 10px, padding 9px 12px; active item white with shadow 0 1px 2px rgba(28,33,48,.08).
Main area padding 28px 32px 48px. Page title 28px/700. Section title 17px/700. Body 13–14px. Table text 12–13px. Small labels 11px/600 uppercase, letter-spacing .08em, color #465577.
Spacing scale: 2, 4, 6, 8, 10, 12, 14, 16, 20px. Line-height 1.4–1.5.
Focus ring: 0 0 0 3px #dcebfa (error ring 0 0 0 3px #ffe4e6). Modal shadow 0 10px 28px rgba(28,33,48,.16).
Plain language labels, sentence case, no jargon.
```

### Color palette

| Role | Hex | Use |
|---|---|---|
| Primary | `#1b5e91` | Primary buttons, active tab, NEW badge, brand name |
| Primary light | `#dcebfa` | Info pills, focus ring, selected rows |
| Primary soft border | `#b9d6f5` | Selected cell / card outline |
| Link | `#4c5a9e` | Text links |
| Text | `#1c2130` | Headings, body, active chip bg |
| Text secondary | `#5f6676` | Descriptions, table secondary text |
| Text muted | `#8a90a0` | Hints, placeholders, timestamps |
| Section label | `#465577` | Uppercase nav / section labels |
| Page background | `#f2f4f9` | App background, muted pills |
| Surface | `#ffffff` | Cards, tables, buttons |
| Border | `#e7e9f0` | Card borders, dividers |
| Border strong | `#cfd3de` | Buttons, inputs |
| Chip border | `#e1e4ec` | Filter chips |
| Success | `#15803d` on `#e3f5ec` | Checked in, complete, at ratio |
| Warning | `#92400e` on `#fef3c7` | Due soon, needs attention |
| Danger | `#be123c` on `#ffe4e6` | Absent, below ratio, late, errors |
| Missing / to finish | `#b45309` on `#fff1e6` (dashed `#f0b84a`) | Missing record items |
| Purple accent | `#6d28d9` on `#e4dcfb` | DSS / special tags |
| Sidebar | `#c8d2ea` → `#b6c3e6` | Sidebar gradient |

Staff dot colors (floor plan): in `#10b981`, due `#cfd3de`, late `#f43f5e`, moved `#5b8fd6`.

### Room colors

| Room | Hex |
|---|---|
| Infant | `#8cc4b2` |
| Transition | `#e0b577` |
| Toddler | `#b3a3dc` |
| PreK | `#9dc585` |
| UPK-4 | `#e8a0b8` |
| School Age | `#92b4df` |

### Typography

| Element | Size | Weight |
|---|---|---|
| Page title | 28px (26px on child record, 22px on attendance pages) | 700 |
| Brand name (sidebar) | 20px | 700 |
| Big stat number | 24–28px | 700 |
| Section title | 17px | 700 |
| Card heading | 14–15px | 700 |
| Nav item | 14px | 500 |
| Body / buttons / chips | 13–14px | 500–600 |
| Table text | 12–13px | 500–600 |
| Pills / badges | 12px | 600 |
| Small label (uppercase) | 11px, letter-spacing .08em | 600 |
| NEW badge | 10px | 700 |

Font: **Instrument Sans** (400, 500, 600, 700). Line-height 1.4–1.5.

### Sizing & spacing

| Item | Value |
|---|---|
| Sidebar width | 232–260px, padding 20px 14px |
| Main content padding | 28px 32px 48px |
| Card | radius 16px, padding 16–18px, border 1px |
| Button | height min 38px, padding 9px 14px, radius 10px |
| Input | height 36–38px, radius 10px |
| Chip | padding 6px 12px, radius 999px |
| Pill | padding 3px 10px, radius 999px |
| Badge (NEW / count) | padding 2px 7px, radius 999px |
| Avatar / initials | 32–36px circle |
| Table row padding | 8px 10px to 12px 14px |
| Modal / side panel width | 360px (panel), modal radius 16px, padding 20px 22px 24px |
| Gaps | 2, 4, 6, 8, 10, 12, 14, 16, 20px (most used: 8, 10, 12) |
| Radius scale | 3px (cells), 6–8px (small), 10–12px (buttons, inputs), 16px (cards), 999px (pills) |

### Shadows & states

| Use | Value |
|---|---|
| Active nav / small lift | `0 1px 2px rgba(28,33,48,.08)` |
| Popover / modal | `0 10px 28px rgba(28,33,48,.16)` |
| Side drawer | `-12px 0 32px rgba(28,33,48,.18)` |
| Focus ring | `0 0 0 3px #dcebfa` |
| Error ring | `0 0 0 3px #ffe4e6` |
| Selected cell | `inset 0 0 0 2px #1b5e91` |
| Hover (nav) | `rgba(255,255,255,.5)` background |

---

## Phase 0 — Teacher Attendance (LOCKED ✅ 100% approved)

**Do not change this screen or its logic.** Every other module below must read from it, not rewrite it.

Approved behaviour (for reference only):
- Check-in screen with date, Export, Live mode
- Room filter chips (All, Infant, Transition, Toddler, PreK, UPK-4, School Age) with `in / total` counts
- Search by name or LAN
- "On premises" / "Currently out" lists with time badge
- Select child → health code → In / Out
- Health codes: Normal, Fever, Cough, Runny nose, Rash, Pink eye, Diarrhea, Vomiting, Injury / bruise, Lice, Tired / unwell, Other (specify)
- Child alerts shown on select (allergy, medical, court order)
- Live overview: currently in / currently out / per-room counts

Regression checks (run after **every** phase):
- [x] Check-in / check-out still works exactly as before — CheckInScreenTest, CheckInEditDayFlowTest green after Phases 3–9 (26 Oct 2026)
- [x] Health code still saves with the punch — HealthScreeningTest
- [x] Counts per room still correct — the chips and the glance panel rendered against mock-up-like data on 26 Oct 2026 match the approved screen
- [x] Nothing in later phases writes over Teacher Attendance data except the agreed Director Attendance "fix time" link (Phase 3) — and the DSS page, which uses the same rows and amendments; nothing else touches `attendances`

Checked against TeacherAttendance.dc.html on 26 Oct 2026: the screen *is* the mock-up (the mock-up calls itself "Current page") — date, Export, Live mode, chips with in / total, search, On premises / Currently out, the glance panel with per-room counts. Two differences found, one closed:
- **Child alerts on select** were not on the screen. Added, read-only: the record's alerts (`Child::alertList()` — allergy, medical, court order, note) show in the select panel under the status, in amber. Nothing about the clock or the codes changed. Test: CheckInAlertsTest.
- **Health codes** on the live screen are the `symptom_codes` table as seeded (0 Normal, 1 Asthma / wheezing, 2 Behavior change, 3 Diarrhea, 4 Fever, 5 Headache, 6 Rash, 7 Respiratory, 8 Stomach ache, 9 Urine problem, 10 Vomiting, 11 Other), not the mock-up's list (Normal, Fever, Cough, Runny nose, Rash, Pink eye, Diarrhea, Vomiting, Injury / bruise, Lice, Tired / unwell, Other). **Not changed**: audits store the code number, so relabelling would re-mean every check already taken. Jordan's call — if the mock-up list is wanted, it is a migration that adds the new codes under new numbers and retires the old ones.

Parked (Jordan, 26 Oct 2026): the **Comments** link beside Export and the **With comments only** chip are off the screen, each in an `@if(false)` in check-in.blade.php ready to flip back. The comment composer in the child dialog, the badges, the Latest comments panel and the comments log at `/comments` are unchanged. Tests: PastCommentsTest and PastCommentsDialogTest expect the two to be absent.

---

## Phase 1 — Foundation: Navigation + Settings · Rooms & hours (MODIFY)

Everything (floor plan, ratios, schedule) reads from this, so do it first.

### 1.1 Sidebar / navigation
- [x] Menu order: Dashboard, Floor plan (NEW), Director Attendance, Teacher Attendance, Children, Staff (NEW), Staff attendance (NEW), Schedule (NEW), Families → Messages (unread badge), DSS (Private / "Only you"), Reports (NEW), Settings
- [x] "NEW" badges on new modules — parked on 26 Oct 2026 at Jordan's request: `$new` is empty in sidebar.blade.php, the span is kept in a comment beside it
- [~] Unread count badge on Messages — the badge is wired (`$unread`) but there is no messaging yet; Messages opens a page saying so and pointing at the Comments log
- [~] DSS menu visible only to the allowed user (marked "Only you") — gate `see-dss`: the emails in `DAYCARE_DSS_USERS`, else any director; the page itself is a placeholder until Phase 8
- [x] User block: name, role (Admin), Log out

Notes (24 Oct 2026): existing office pages the mock-up does not list (Staff reports, Room Schedules, Holidays, Leave Requests, Payroll Prep, Payroll, Import Children) sat under an **Office** section at the foot, director only — parked on 26 Oct 2026 at Jordan's request, along with My Schedule, My Leave, Time Clock and Comments: the menu is the mock-up's twelve links, the pages stay live at their addresses, and each block sits in an `@if(false)` in sidebar.blade.php ready to flip back. Staff attendance opens the existing Timesheets grid until Phase 6; Schedule opens the existing week schedule until Phase 7.

### 1.2 Settings → Rooms & hours
- [x] Tabs: Children · Rooms & hours · Login history (plus Administrators and Devices, which this app already had; Company parked again 27 Oct 2026 at Jordan's request — the link sits in a comment in settings/tabs.blade.php, the page still answers at /settings/company)
- [x] Rooms table: Room, Ages, Staff : children ratio, Capacity, NY max group, Room open hours, Check
- [x] NY max group shown per age (18 NYCRR 418-1.8) — `daycare.ny_max_group`
- [x] Warning when capacity is above the group limit
- [x] Centre hours & shift limits: centre opens, centre closes, earliest start for everyone else, early drop-off room, School Age leave / return times, longest shift, most hours in a week
- [~] Only staff with "may open" can start before 7:30 — the rule is stated on the page and the earliest start is saved (`centre.earliest_start`); the schedule generator still reads `daycare.default_earliest` (Phase 7 wires it)
- [x] Warning when a saved value conflicts (e.g. close time mismatch)
- [x] Save button

Notes: `App\Services\CentreSettings` is the one reader — a saved value (settings table, keys `rooms.{room}.ratio`, `rooms.{room}.capacity`, `centre.*`) wins over `config/daycare.php`. Room hours are the existing `room_schedules` rows. The dashboard and the floor plan read ratios, capacities and the School Age return hour through it.

---

## Phase 2 — Children list + Child record (MODIFY)

### 2.1 Children list
- [x] "Records to finish" banner with grouped counts (No guardian, No emergency contact, Immunizations due, Photo consent unsigned, Hours not agreed) — click filters the list (`?gap=`)
- [x] Status tabs with counts: Active, Pending, Withdrawn, Archived, All — the column still says `Inactive`; the screen says Withdrawn (`Child::STATUS_LABELS`). The Archived tab stays behind the Settings › Children switch this app already had
- [x] Room filter chips with counts (`?room=`)
- [x] Pay filter: DSS / Private (`?pay=dss|private`; DSS = either DSS number on file)
- [x] Export + "Add child"
- [x] Columns: LAN, Child (last, first + nickname), Room, DOB, Age, Schedule (day dots), Pay, Guardian + phone, Alerts, Record (complete / missing items)
- [x] Sort by LAN, Child, Room, Age (and DOB, Schedule, Alerts, Record as before)
- [x] Pending child shows "Starts <date>"
- [x] Withdrawn tab columns: Last room, Last day, Reason, Note, Could return + Re-enroll button
- [x] Archived tab: Restore button
- [x] Click a row opens the child record

### 2.2 Child record — header & status lifecycle
- [x] Header: photo / initials, name + nickname, status, % complete, LAN, age (DOB), enrolled date, alert chips, missing-item chips
- [x] Actions: Message parents, Sign in / out, ⋯ menu (Edit details, Move to another room, Withdraw, Re-enroll, Archive, Start enrollment)
- [x] **Pending → Active** (Start enrollment): pick first day; auto-starts on agreed date if not done by hand (`children:sync-enrollments`, nightly at 00:10)
- [x] **Pending → Withdrawn** (Cancel enrollment): reasons — Family changed plans, Chose another provider, Subsidy not approved, No space in the room, Other + note; "Keep pending" option
- [x] **Active → Withdrawn**: last day attended, reason, could she come back, note (shows on DSS page + history)
- [x] After withdraw: off attendance sheets, check-in, schedule, ratio counts and group texts; attendance up to last day still counts for billing and KinderConnect export — a last day still to come keeps them Active (and on check-in) until the nightly command passes it
- [x] **Withdrawn → Active** (Re-enroll): same LAN and family
- [x] **Withdrawn → Archived**: nothing deleted, read-only until restored
- [x] **Archived → Withdrawn** (Restore)
- [x] Every status change writes to Enrollment history

Checked again against Children.dc.html on 26 Oct 2026 (rendered with mock-up-like data): the list matches — banner, tabs with counts, room and Pay chips, the sortable columns, day dots, Pay pill, guardian and phone, alert and record chips, and the Withdrawn tab's Last room / Last day / Reason / Note / Could return / Re-enroll. One difference left as it is: the page opens on **All** (no `?status=`) where the mock-up opens on **Active**; "All" was the list's existing default and the sidebar links to it, so changing it is a choice for Jordan rather than a fix.

### 2.3 Child record — sections
- [x] **Overview**: days, drop-off / pick-up, hours a week (calculated from days + times, no separate expected hours), room, enrolled date, room history, DSS case no. + CIN, nickname; Edit / Save
- [x] **Move to another room**: room, starting date, why (optional); manual room stays until "Back to automatic"; shows age-based suggestion
- [x] **Family & pick-up**: one list with ticks per person — Guardian, Pick-up, Emergency, Pays, Texts; call order ↑/↓; Edit / Unlink
- [x] Add person: search existing people first (link sibling's parent), else create new; relationship list
- [x] Home address + custody note
- [x] Warnings: "No legal guardian on file", "No emergency contact"
- [x] **Health**: each alert is its own entry (type, severity, reaction, what to do, care plan date) — shown on roll, check-in and kitchen list; doctor; immunizations + next due; medical statement + renewal; medication
- [x] **Enrollment history**: every change (room move, schedule change, subsidy start, enroll / withdraw) — the same `child_status_changes` table, told apart by `event`
- [~] **Enrollment note**: one note, Edit / Save / Clear — always marked "On DSS page" (Phase 8 reads it); there is no switch to keep one off it
- [x] **Notes & comments**: teacher comments from check-in + office notes, newest first, Add, Show older
- [x] **To finish**: missing items with action (e.g. Upload signed form)
- [x] **Attendance · month**: days in, absent, average times, link to register — "absent" is the absent mark Director Attendance / the DSS page set, or a booked weekday gone by with no sign-in; a "not scheduled" mark takes the day out (26 Oct 2026)

Checked against ChildProfile.dc.html on 26 Oct 2026 (rendered with mock-up-like data): the record matches — header with status menu, % complete, chips and actions; the anchors; Overview with the room move; Family & pick-up with call order and ticks; Health alerts as entries; Enrollment history with the note marked On DSS page; Notes & comments with filters; To finish; Attendance; Documents; Record footer. The one remaining [~] is the enrollment note's "keep off the DSS page" switch, which the mock-up does not have either.
- [x] **Documents**: upload (or record the paper as on file); the medical statement renews a year from signing
- [x] **Record** footer: created date, last change by whom

Notes (26 Oct 2026): `App\Services\Enrollment` is the lifecycle; `ChildStatusController` (director only) and `ChildRecordController` (same door as the record) are the routes; `ChildDocument` and `ChildNote` are the two new tables; `pays_fees` / `gets_texts` are on `child_people`. "Immunizations due" is also raised when no date is on file. Demo data: `php artisan db:seed --class=ChildRecordDemoSeeder --force` fills blanks on the active roll (alerts with severity and action, a history line for every child, room / schedule / subsidy events, a child leaving with the last day ahead, an expired paper, teacher comments, a record with enough notes to fold) and makes the eight children the Withdrawn / Pending / Archived tabs show, including one cancelled before starting and one re-enrolled. `docs/phase2-manual-test.md` is the hand walk-through, with the LAN that shows each feature. Tests: ChildrenListPhaseTwoTest, ChildLifecycleTest, ChildRecordSectionsTest.

---

## Phase 3 — Director Attendance (MODIFY)

Weekly register. **Linked to Teacher Attendance** — reads today's in/out from check-ins; a fix here shows there.

- [x] Week navigation (‹ Oct 12–16 ›), This week, Print, Recent 10
- [x] Room filter chips with `in / total`
- [x] Summary: enrolled, expected, in, still to come, not in
- [x] Columns: LAN, Student, Classroom, DOB · Age, Hours, Mon–Fri
- [x] Tap a day cell to cycle: Not scheduled → Absent → Coming → back
- [x] Cell shows recorded times; marker for "not one of their days · still billable"; marker for "time fixed by hand"
- [x] Inline time edit: Enter saves, Esc cancels, ↑/↓ = 1 min, Shift+↑/↓ = 1 hr
- [x] "Changes save as you tap" + "Last change" line
- [x] "Open check-in →" link to Teacher Attendance
- [x] **Check:** a time fixed here appears on Teacher Attendance without changing how Teacher Attendance works (Phase 0 regression) — DirectorAttendanceWeekTest reads the check-in page after a fix

Notes (26 Oct 2026): `AttendanceController::week()` + `App\Services\AttendanceWeek` + `resources/views/attendance/week.blade.php` (styles in `attendance/partials/week-style`) is now `/attendance` (`attendance.index`). The register the page was before — cards and sheet modes, the projection, the room cover, the checklist, the recent drawer, past comments — is kept whole as the **classic register** at `/attendance/classic` (`attendance.classic`), reached from the ⋯ menu; its 41 test files now name that route. Cell states: *coming* = the schedule tick on; *absent* = the DSS page's `dss_day_marks` absent mark (the rows come off with amendments, as on the DSS page); *not scheduled* = tick off, times kept (billable). A tap is `POST /attendance/week/cell` with `{child_id, date, session, state, in?, out?}`; times go on the same `attendances` row Teacher Attendance reads, with an `AttendanceAmendment` for a past day, and never on a day to come. Tapping opens the week for the person if it is not open yet, as the classic register did. The "fixed by hand" dot is `AttendanceAmendment::mapForRange()`. Tests: DirectorAttendanceWeekTest.

Week control (Jordan, 26 Oct 2026): the ‹ label › and "This week" button are now the same pill Teacher Attendance has — a week back, the week with a calendar icon (press it for the calendar; any day opens its week, weeks ahead included), a week forward — and **Today** beside it, lit on this week and the way back otherwise. `attendance/partials/week-picker.blade.php` (Alpine `weekPicker`), used by the register only; Teacher Attendance's own `dayPicker` is untouched.

Fixed 26 Oct 2026 (found by the CRUD check): on a week already gone the ticks are frozen, so a tap to **Not scheduled** on a past day did nothing and the cell came back as Coming. It is now kept as the DSS page's "not scheduled" mark (`dss_day_marks` none), which the register reads ahead of the tick; times on the day stay, as before. Test: `a day in a week already gone can still be marked not scheduled`.

---

## Phase 4 — Dashboard + Floor plan (Dashboard MODIFY, Floor plan NEW)

Both are read-only views of Phases 0–3 data.

### 4.1 Dashboard
- [ ] Welcome line with date and time
- [ ] Cards: Scheduled today (of enrolled), Checked in, Checked out · returning, Checked out, Expected, Absent, Staff on the floor
- [ ] "Ratios right now" per room: status, children · staff, note + "Open the floor plan →"
- [ ] Today's attendance progress bar (% arrived) + "Take attendance →"
- [ ] Upcoming closures + "Manage holidays"

### 4.2 Floor plan
- [x] Live label + "updated" time
- [x] Top counters: Checked in, Checked out · returning, Checked out, Expected, Staff on the floor, Rooms below ratio
- [x] Building map with rooms + hallway, office, bathrooms, kitchen, front door
- [x] Each room: `in · cap`, child dots (in / booked not in / free place), staff dots (clocked in / late / moved here / due later)
- [x] Ratio check per room: staff needed vs staff in room; flag "Need another adult"
- [x] Tips per room (e.g. move a staff member, School Age returning at 3:00 needs 2 staff)
- [x] Morning / afternoon view toggle — afternoon is the day as booked at the hour School Age is back (`daycare.school_age_returns_at`)
- [x] Tap a room → details panel (checked in, expected, out, staff, places free, enrolled, teachers) + "Open <room> on the register →"

Notes (24 Oct 2026): `/floor-plan`, `App\Services\FloorPlan` over `DashboardSnapshot`. Capacity and age ranges are in `config/daycare.php` (`capacity`, `room_ages`) until Settings → Rooms & hours (1.2) owns them. A staff member is *late* when their shift has started and they are not clocked in, *moved* when the shift's room is not their home room, *due* when the shift is later. The page reloads itself every minute while on screen.

- [x] "Move X to Y until Z arrives" is a real move (27 Oct 2026, Jordan: "make it work … and dynamic"). Director only; a teacher sees "Ask the director to …" instead. `POST /floor-plan/move` → `App\Services\FloorCover::move()`: X's shift today is cut at this minute and the rest becomes a `FLOAT` shift in Y on today's roster, with `staff_shifts.covers_user_id` = Z. The page comes back with Y open and a green line ("LaQuora is in Toddler from 10:30 AM until Sultana clocks in."); the schedule, the timesheet and the ratio check all read the same roster, so they show it too. When Z clocks in (`TimeClock::punch` IN → `FloorCover::settle()`), the float ends at that minute and X gets the rest of the day back in their home room as a `STAFF` shift. A "Never assign to room" Must rule blocks the move and the page says so. Pressing the button twice changes nothing. Migration `2026_10_27_000002_floor_plan_cover`.

---

## Phase 5 — Staff (NEW)

Needed before Staff attendance and Schedule.

- [x] Staff list + Export + Add staff; "Not on the schedule" group (e.g. Director, Cook, Inactive) — `/staff`; the dot beside each name is in / worked earlier / late / due / off, from the clock and today's shift
- [x] **Details** tab: name shown in app, legal name, mobile, email, emergency contact / phone, getting to work
- [x] Employment: type (FT, PT, PT flex, Substitute, Inactive), role (Lead teacher, Assistant, Floater), home room, start date, ASPIRE ID, last evaluation — `PT_FLEX` and `INACTIVE` added to `StaffRule::EMPLOYMENT`; the home room is the first assigned classroom
- [x] Time clock: staff ID, card issued, 4-digit PIN, Reprint card, Reset PIN — the card and PIN routes this app already had, on the tab
- [x] Pay (director only): pay rate, direct deposit — dots until Show
- [x] AngelNest login: can sign in, last sign-in, send temporary password — on **Add staff** the "Email a temporary password" choice is parked (Jordan, 26 Oct 2026): the director types the first password; the block sits in an `@if(false)` in teachers/form.blade.php. The Staff page's "Send a new temporary password" button is unchanged.
- [x] **Rules** tab: Must / Prefer strength + director-only note; Add / Edit / Delete — the same `StaffRule` rows and routes as before; Edit loads the rule into the form
- [x] Rule types: Can't work with…, Must never be only staff, Can't work on a day, Only works between…, Can't start before…, Works exactly… (fixed shift), Shift must end at…, Must total hours (weekly / 2 weeks), No more than … hours a week, Half days a week, Give as many hours as possible, May open the building, Never assign to room…, Prefers room…, Prefers starting at…, Prefers a day off, Prefers full days a week — all sixteen were already in `StaffRule::FIELDS`; three still say "not enforced yet" on the page, as before
- [x] No rules = available 7:30 AM – 6:00 PM every weekday — the page says so from `daycare.default_earliest` and `daycare.close`
- [x] **Attendance** tab: date range, days worked, hours worked, scheduled hours, late arrivals, punches to fix; day table + Export — late is more than 5 minutes after the shift; Fix → opens the timesheet's day page
- [x] **Time off** tab: date, until (optional), reason, Paid (PTO / sick) / Unpaid, note; treated as a Must for that date — new `staff_time_off` table (`StaffTimeOff`: out all day / out until / leaving at / only available / extra day); `StaffSchedule::windowFor()` applies it after the rules
- [~] Time off shows schedule impact (e.g. room drops below ratio) — the impact line reads against the person's own shift (the one on the Schedule, else what their rules give); "room drops to 2 of 3 staff" waits on the Schedule's coverage (Phase 7)
- [x] Past entries fold away automatically the next day — Past · year with totals, Show / Hide

Notes (27 Oct 2026): `StaffController` + `resources/views/staff/index.blade.php` (styles in `staff/partials/style` over the shared `.ch` set). The older roster at `/teachers` is still there and still tested; the sidebar's Staff opens `/staff`. **Kiosks are not on the menu** — Jordan's call: the clock and the door screen stay in their own tab. What this page decides reaches the clock through the data: `StaffKiosk::screenFor()` now carries a `today` line ("Scheduled 9:00 AM – 6:00 PM · Transition — Out until 3:00 PM · appointment") that the clock shows the person after they identify. Demo data: `php artisan db:seed --class=StaffDemoSeeder --force`. Tests: StaffPageTest. Hand walk-through: `docs/phase5-manual-test.md`.

---

## Phase 6 — Staff attendance (NEW)

- [x] Live / History / Export
- [x] Cards: Clocked in now (of due), Late / not in, Due later today, Off today, Hours so far today
- [x] Filter: All, Clocked in, Not in, Due later, Off
- [x] Columns: Staff, Room now, Scheduled, In, Out, Status, Hours
- [x] History week grid (‹ week ›) with Week total; flags for late, forgot to clock in, no clock-out
- [x] **Correct a day**: show what the clock recorded; edit clock in / out / unpaid break; counts as Worked / Paid time off / Sick / Unpaid absence
- [x] Reason required (Forgot to clock in, Forgot to clock out, Clock wasn't working, Wrong time punched, Other) + note
- [x] Original punch kept; change logged with director's name; "Changes this week" list

Notes (26 Oct 2026): `StaffTimesheetController` + `resources/views/timesheets/week.blade.php` (styles in `timesheets/partials/attendance-style` over the shared `.ch` set), at `/staff-timesheets` — the sidebar's Staff attendance. Live is the default; `?view=hist`, a `from`/`to` week or `?open=user|date` opens History. The Correct a day panel is the old edit panel's endpoints (`timesheets.panel`) with three fields — in, out, unpaid break (the lunch pair) — plus **Counts as**, which writes a one-day `StaffTimeOff` (kind all; sick / vacation paid / other unpaid) and takes it off again on Worked; a multi-day stretch is left alone. Reasons are `TimePunch::REASONS` codes: `forgot_in` / `forgot_out` are new; "Clock wasn't working" and "Wrong time punched" keep their stored labels (Kiosk / device issue, Punched wrong time) so old records read the same. Late is more than `StaffController::LATE_GRACE` (5 min) after the shift, as on the Staff page; a scheduled past day with no punch is "Forgot to clock in" (`MISSING_IN`). "Changes this week" comes from `TimeClock::changes()`, shared with the panel's history. The old date-range picker is gone; the arrows step a week and `from`/`to` still take any range up to 31 days. Tests: StaffWeekGridTest, TimesheetEditPanelTest.

Checked again against the Staff attendance mock-up on 26 Oct 2026 (rendered with the mock-up's roster and week): four differences, all closed. The Live list is now in the mock-up's order — on shift earliest first, then done, then late, then due soonest first, then off — instead of by name; the Scheduled column uses the short form (7:15a – 3:45p); an Off row shows no room; and a director with no punch, shift or time off on the day (Live) or across the days shown (History) is no longer a row of dashes — a teacher always keeps their row. The History grid's staff column carries the name and ID only (the job is on the name's hover) and the cells are 96px, so the Week total fits beside Friday at a desktop width.

Scenario test added 27 Oct 2026 (Jordan: "add test to staff attendance add data"): `StaffAttendanceScenarioTest` — a week with an on-time day, a late one, a forgotten clock-out, a forgotten clock-in, a sick day as time off, this morning's late arrival and somebody due at noon; the Live board's order and cards, every cell's colour and words, the two fixes through the panel (the week's hours and "2 corrected"), the role filter, and the .csv with a row per day worked. One gap it found and closed: the file's Status column was blank for a corrected day and for a day with no clock-in (`StaffTimesheetExport::STATUS` now says Edited / Missing clock-in). Note: the panel's `hours` and the grid's Week total are the days done, not today so far.

Demo data (27 Oct 2026, Jordan: "make data in staff attendance in live and history"): `StaffAttendanceDemoSeeder` — last week's roster copied from this week's where missing, every day gone punched against it (clean days with a lunch, about one late a day, three missing clock-outs on yesterday, two days nobody punched, three supervisor-typed clock-outs under `forgot_out`, a sick day and an "out until 10:00" appointment as time off), and today punched against the clock only if nothing has punched it yet. Re-runnable: clears the two weeks' past punches and its own time off first. Listed in manual-test.md's setup.

---

## Phase 7 — Schedule (NEW)

- [x] Week view (‹ Week), Draft status (staff can't see until Publish), change counter
- [x] Generate, Print for staff, Publish
- [x] Views: By teacher / By room; Bars / Simple table; dotted = moves rooms that day
- [~] Drag a shift to move (whole, or from a time in the afternoon); drag from "Not working" list to add a shift — no resize handle yet: a shift is shortened from the time-off clash card ("Keep 12p–3:30p")
- [x] Coverage strip per room: staff vs need by time, peak children, ratio, capacity
- [x] "Every room at ratio" / "No staff in this room today" messages
- [x] Before saving, show effect on both rooms: short, more than needed, rules broken
- [x] **Must** rules block the change ("Blocked by a Must rule"); **Prefer** rules reported only
- [x] Time off clash warning (from Staff → Time off)
- [x] "Who could cover" list (free + no Must rule broken); else "call a substitute"
- [x] Add time off from a bar: Appointment, Sick, Personal, Vacation, Family
- [x] "How the checks work" help panel

Notes (26 Oct 2026): `StaffScheduleController` + `App\Services\ScheduleEditor` + `resources/views/staff-schedule/index.blade.php` (styles in `staff-schedule/partials/style`), at `/staff-schedule`. A week is a draft until POST `staff-schedule.publish`; `published_at` / `edits` on `staff_schedule_weeks` (migration `phase_seven_schedule_publish`). Teachers see nothing of a draft on the Schedule or My Schedule; a week with no row (shifts entered by hand) is shown as it is. Generate makes a new draft. By room: `GET staff-schedule.day` (day JSON), `POST staff-schedule.preview` / `apply` with `{kind: move|add|trim|remove, id|user_id, to, opt}` — the editor checks ROOM_FORBIDDEN, hard day/hours rules, time off and approved leave, NO_PAIR in one room, NEEDS_SUPERVISION alone, and one person in two rooms (Must, blocks); ROOM_PREFERENCE / PREFERRED_DAY_OFF / PREFERRED_START soft rules are Prefer (reported). Time off from a bar: `POST staff-schedule.time-off` writes the same `StaffTimeOff` the Staff page keeps. Print for staff: `staff-schedule.print` opens a plain sheet in a new tab. The children-under-staff week and the Students tab that were on this page before Phase 7 are gone; the children's week is on Room Schedules. Tests: SchedulePageTest, StaffScheduleTest, MyScheduleTest, LeaveScheduleTest.

Checked again against the Schedule mock-up on 26 Oct 2026 (rendered with the mock-up's fifteen staff, rules, time off and week): By room and By teacher · Bars match — the clash cards with Keep / Remove, the hatched time-off blocks, the striped conflict bar, the day pills, the coverage strips, the tray, Move someone and the help card. Three things closed: the Simple table's lines ran on in one row because the column layout was an inline `display:flex` under x-show (now the `.lines` class), which also pushed Friday and Hours off the card; the time-off line in a table cell reads "Out until 3:00 PM · Appointment" rather than the picker's longer sentence; and the room pill says **Short**, as the mock-up does, instead of Under ratio.

---

## Phase 8 — DSS attendance + Reports (DSS MODIFY, Reports NEW)

### 8.1 DSS attendance (private page)
- [x] Visible only to the allowed user
- [x] Month navigation; "Subsidy only" toggle with count
- [x] Class filter (multi-select) — grid, totals and export all follow it
- [x] Rows: child, agreed hours, CIN, withdrawn marker, enrollment note; columns: days, hours, agreed
- [x] Cell: IN / OUT; School Age split into AM and PM; "PM only"
- [x] Cell actions: Apply, Agreed hours (H), Absent (A), Not scheduled, Clear (Del)
- [x] Block select (Shift+click / Shift+arrows), click date = column, click child = row
- [x] Copy / Paste (Ctrl+C / Ctrl+V), Repeat day →, Undo
- [x] Weekly hours vs authorized hours; over-cap warning
- [x] Centre closed / not enrolled days greyed
- [x] **Export for KinderConnect**: past days only, range, include absences, Download .csv / .json; one row per visit (School Age AM + PM rows)

Notes (26 Oct 2026): `DssController` + `App\Services\DssAttendance` + `resources/views/dss/index.blade.php` (styles in `dss/partials/style`), at `/dss` behind the `see-dss` gate. The times are the register's own `attendances` rows (one per session; School Age AM + PM) — every change here writes them, with an `AttendanceAmendment` (added / retimed / removed) for a past day; times cannot be put on a day still to come. "Absent" and "Not scheduled" are the one thing the register had no column for: `dss_day_marks` (migration `phase_eight_dss_marks`). Authorized h/wk = the child's `expected_hours_per_week`; agreed stretches = drop-off / pick-up, split for School Age at `school_age_leaves_at` (08:30) / `school_age_returns_at`. The grid is JSON (`GET /dss/grid`), each change is `POST /dss/cells` with `ops: [{child_id, date, state}]` and the whole month comes back; undo re-sends the cells' previous states. Export: `GET /dss/export?format=csv|json&range=past|week&abs=1` (never cached) and `/dss/export/preview` for the checks (missing CIN, over cap, visits over 10 h). Tests: DssAttendanceTest.

### 8.2 Reports
- [x] Report picker; "Next: DSS attendance →" shortcut
- [x] Filters: Month, Room, Show (times / present-absent), Dates
- [x] Print / PDF + Download .csv
- [x] **Child attendance**: grouped by room; Present, Absent, Hours; A = absent, closed days marked; times from Teacher Attendance
- [x] **Staff attendance**: pay period; Hours, Breaks, Late, Edited, Over 40; late = more than 5 min after schedule; ✎ = fixed by director
- [x] **UPK-4 attendance sheet**: same layout as paper sheet (Month, School Site, Grade, ✓ / A, Total days absent, Notes, Grand Total); notes from child record
- [x] The sheet's day cells are live (27 Oct 2026, Jordan: "this sheet is static, make it dynamic"): a press cycles ✓ → A → blank → ✓ through `POST /reports/sheet/cell` (director only) → `DssAttendance::apply()` with `abs` / `none` / `pres` at the child's agreed times (7:30–4:30 when none), so the register, the DSS page and the child report all agree; the response is the rebuilt sheet (rows, totals, missing) and the page repaints the cells in place. Today and days ahead are not pressable.
- [x] Demo data for the sheet and the child report (Jordan, 27 Oct 2026: "make seeder for each students and room"): `ChildAttendanceDemoSeeder` — every active child, every room, first of last month to yesterday, register + door rows, booked days from the week schedule else the child's own days (the same rule the sheet uses), one day in eight an Absent mark on the DSS page, one register sign-in in eleven missed. `OctoberAttendanceSeeder` is now a thin subclass keeping its 1–17 Oct default.
- [x] Month filter is a stepper (Jordan, 27 Oct 2026: "not show all month button"): ‹ month › in one pill, › greyed on this month, "This month" chip lit on it and "↩ This month" as the way back otherwise. Any month opens (`?month=YYYY-MM`); the six-month list the controller still builds is no longer drawn.
- [~] Weekly sign-in sheet card parked (Jordan, 27 Oct 2026: "hide for now") — the card line sits in a comment in `reports/index.blade.php`; the sheet still opens for `?report=week` and the register's `date` / `classroom` links.
- [x] The sheet is drawn for any room (27 Oct 2026, Jordan: "UPK-4 sheet is a sample, dynamic for each classroom"): the card is "Attendance sheet", Room chips above it (it opens on Infant, the first room — Jordan, 27 Oct 2026), `?room=` on the page and the export, `Reports::upkSheet($year, $month, $room = 'UPK-4')`. The room is the Grade box (UPK-4 prints "UPK4" as the district form does) and the file's name (`Toddler-attendance-October-2026.csv` / `.pdf`). The monthly email panel shows on the UPK-4 sheet only, and `UpkEmail` still sends UPK-4.
- [x] **Monthly email**: recipients add / remove, send day + time, attach PDF and/or CSV, hold if UPK-4 has missing sign-ins, preview, Send test, Send now, Sent log

Notes (26 Oct 2026): `ReportController` + `App\Services\Reports` (the three sheets and their .csv) + `UpkSheetPdf` (FPDF, letter landscape) + `UpkEmail` (settings, send, scheduled run) + `resources/views/reports/index.blade.php` (styles in `reports/partials/style`). The weekly sign-in sheet the page was before is `reports/partials/week` and opens for `?report=week` or any address with `date` / `classroom`, so the register's links still work. Teachers get Child attendance and the weekly sheet for their rooms; Staff attendance and the UPK-4 sheet are the director's. Absent on the children's reports = an Absent mark on the DSS page, or a scheduled past day with nothing recorded; a Not-scheduled mark blanks it. Staff late = `StaffController::LATE_GRACE` after the shift (time off narrows it), ✎ = a supervisor punch, Over 40 is per Mon–Sun week. Export: `GET /reports/export?report=child|staff|upk` (never cached), `&format=pdf` for the UPK sheet. Monthly email: settings in `settings` under `upk_email.*`; `reports:upk-email` runs daily at the chosen hour (routes/console.php) and on the send day sends the month that just ended, or — with the hold on and sign-ins missing — emails the directors and waits for Send now; the Sent list is the last ten runs. Mail from `MAIL_FROM_ADDRESS`. Tests: ReportsPageTest, ReportSheetTest.

---

## Phase 9 — Messages (MODIFY)

- [x] Conversation list with unread state + "New message"
- [x] Three conversation types:
  - [x] **Room** — teachers in the child's room + director; sent from Classroom line
  - [x] **Office** — office only, teachers can't see; sent from Office line
  - [x] **Announcement** — one-way to all families; replies go to Office; delivered / skipped counts
- [x] Recipients come from the child record "Texts" tick
- [x] Auto-skip: opted out, court-restricted contacts, no photo consent (for photos) — consent is a warning, not a skip: the photo goes to the family whether or not their own child has consent on file, because it may not be of their child; the list marks who has none
- [x] ✨ Polish: fixes spelling, grammar and tone; "Use this" / "Keep mine"
- [x] Attach photo / video with consent warning
- [x] Signed-in role preview (teacher vs office visibility) — no toggle: the real signed-in role decides, and the subtitle says what it sees

Notes (26 Oct 2026): `MessageController` + `App\Services\Messaging` + `App\Services\Texter` + `resources/views/messages/index.blade.php` (styles in `messages/partials/style`). Tables `message_threads`, `messages`, `message_reads` and `people.texts_stopped_at` (migration `phase_nine_messages`). A thread is one child + one kind (room / office), or one announcement; a room send is one message in each family's own Room thread, tied by `broadcast_key`. Visibility is `MessageThread::visibleTo()`: director everything; teacher their rooms' Room threads + announcements. Recipients: `Messaging::recipientsFor()` — the `gets_texts` tick, skipped with a reason for Texts not ticked / restriction on the link / replied STOP / no mobile. Sending goes through `Texter` with `DAYCARE_SMS_DRIVER=log` (recorded, written to the log, nothing leaves) or `twilio` (`TWILIO_SID` / `TWILIO_TOKEN`); lines are `DAYCARE_CLASSROOM_LINE` / `DAYCARE_OFFICE_LINE`. Replies: point the provider at `POST /sms/inbound` (Twilio-signed when the driver is twilio; CSRF-exempt); the number picks the person, the line picks Room or Office; STOP / START are honoured. Photos go as picture messages and videos as a signed link (`messages.media`, 30 days), both stored on the local disk under `messages/media`. ✨ Polish asks Gemini when `GEMINI_API_KEY` is set and otherwise does the small fixes itself. "Message parents" on a child's record opens New message with that family chosen. The sidebar badge is `MessageThread::unreadFor()`, keyed to the last message id read. Tests: MessagesTest.

---

## Final cross-check (after Phase 9)

- [ ] Phase 0 regression checks all pass
- [ ] Withdrawn child disappears from check-in, schedule, ratios and group texts, but stays in billing / DSS export up to the last day
- [ ] Room change in Settings updates Floor plan and Schedule coverage
- [ ] Staff time off appears on Schedule and Staff attendance (Off today)
- [ ] Director time corrections show on Reports with ✎
- [x] Role access: Office messages hidden from teachers (MessagesTest); DSS and Pay director-only still to check
