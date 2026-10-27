# Manual test: Phase 1 to Phase 9

A walk-through to check every phase by hand, in the order a director meets it during a day. Each step says what to do and what you should see. Tick the box when it matches; write what you saw next to any step that does not.

The Phase 2 (Children) and Phase 5 (Staff) walk-throughs go deeper on their own pages: `docs/phase2-manual-test.md` and `docs/phase5-manual-test.md`. This file covers the whole flow once, end to end.

**Last run — 26 Oct 2026, headless against the local database.** Every step that can be driven without a mouse was run as the director and the teachers: 64 passed, none failed. Not covered by that run (needs a browser): drag and drop on the Schedule, the DSS keyboard and clipboard, inline time editing keys, file uploads, and ✨ Polish (the local `.env` has a Gemini key, so a real call was not made). Two things worth a decision: the dashboard's Quick actions still carry an **Import Children** tile although the sidebar link is parked, and teachers see **Director Attendance** in the sidebar because the register's route allows them. Leftovers of the run on the local data: Nia (10110) is now Active, Naseliz (10005) Withdrawn with Family choice, Iris (10107) Active, this week's schedule was generated and published, and a few test messages sit in Messages.

## Before you start

1. Migrate, seed and build on the local database. The seeders only fill blanks, so running them again is safe:

   ```
   php artisan migrate --force
   php artisan db:seed --class=ChildRecordDemoSeeder --force
   php artisan db:seed --class=StaffDemoSeeder --force
   php artisan db:seed --class=FloorPlanDemoSeeder --force
   php artisan db:seed --class=StaffAttendanceDemoSeeder --force
   php artisan db:seed --class=ChildAttendanceDemoSeeder --force
   npm run build
   ```

   The child one fills every child in every room from the first of last month to yesterday, on both records (register and door): about one booked day in eight is an Absent mark on the DSS page, the register misses one sign-in in eleven (what the monthly email's hold catches). Re-runnable; today is never touched. `SEED_FROM` / `SEED_TO` take another range.

   The last one fills Staff attendance: last week and this week's days gone, with one late a day or so, a few missing clock-outs, two forgotten clock-ins, three supervisor corrections, a sick day and a late start as time off. Today is punched against the clock only when nothing has punched it yet, so run it (or the floor-plan seeder) during the day for a Live picture; before 7 AM everyone is simply Due. To see Live with ins and outs at any hour, paint today as of an afternoon: `DEMO_NOW=15:30 php artisan db:seed --class=StaffAttendanceDemoSeeder --force` (openers done for the day with in and out, the long shifts and the afternoon starters on shift, one who never came in).

2. Logins (password `password` for all of them):

   | Who | Email | Used for |
   |---|---|---|
   | Director | `admin@daycare.test` | Everything |
   | Infant teacher | `infant.teacher@daycare.test` | The teacher's view of Schedule, Messages, Reports |
   | School Age teacher | `schoolage.teacher@daycare.test` | AM / PM split on the DSS page and reports |

3. `.env` for this walk-through:

   ```
   DAYCARE_DSS_USERS=admin@daycare.test
   DAYCARE_SMS_DRIVER=log
   MAIL_MAILER=log
   ```

   With the `log` drivers nothing leaves the machine; texts and emails land in `storage/logs/laravel.log`. Leave `GEMINI_API_KEY` empty unless you want Polish to use Gemini.

4. Children used below (from the Phase 2 seeder):

   | LAN | Name | Shows |
   |---|---|---|
   | 10003 | Naomi Merced Ayala | Record 80% complete, photo consent missing |
   | 10005 | Naseliz Cruz | Complete record, used for the withdraw test |
   | 10006 | Jaziel Cruz | Allergy alert; subsidy line in history |
   | 10013 | Pharaoh Lumpkins | Leaving with the last day still ahead |
   | 10107 | Iris Tan | Withdrawn, used for Re-enroll |
   | 10110 | Nia Brown | Pending, used for Start enrollment |

---

## Phase 1 · Sidebar and Settings → Rooms & hours

### 1.1 Sidebar

- [ ] Log in as the director. The sidebar shows exactly these links, in this order: Dashboard, Floor plan, Director Attendance, Teacher Attendance, Children · **Staff**: Staff, Staff attendance, Schedule · **Families**: Messages · **Only you**: DSS, Reports, Settings.
- [ ] No link carries a NEW badge (parked 26 Oct 2026). DSS carries Private. Settings carries Admin.
- [ ] There is no Office section, and no My Schedule, My Leave, Time Clock, Comments, Payroll or Import Children link. They are parked, not deleted: `/children/import` still opens by address.
- [ ] The user block at the bottom shows your name, Admin, and Log out.
- [ ] Collapse the sidebar with the toggle: only the toggle stays in the rail. Expand it again.
- [ ] Log in as the Infant teacher. No Staff attendance, DSS or Settings. Schedule, Messages and Reports (child attendance only) are there. Director Attendance is also shown to teachers today, because the register's route allows them — Jordan to say whether it should hide.

### 1.2 Settings → Rooms & hours

- [ ] Sidebar → Settings → the Rooms & hours tab. Tabs present: Children, Rooms & hours, Administrators, Devices, Login History. Company is parked (Jordan, 27 Oct 2026); `/settings/company` still answers.
- [ ] Rooms table has Room, Ages, Staff : children, Capacity, NY max group, Room open hours, Check. Every room has a line.
- [ ] Set Infant capacity to 12. The Check column flags that it is above the NY group limit of 8. Put it back to 8; the flag goes.
- [ ] Centre hours below the table: centre opens, centre closes, earliest start for everyone else, early drop-off room, School Age leave and return times, longest shift, most hours in a week.
- [ ] Set the centre close to 5:00 PM while a room still closes at 6:00 PM. A "Worth a look" warning names the mismatch. Put it back to 6:00 PM.
- [ ] Press Save. The page reloads with the values kept.
- [ ] Change the Toddler ratio to 1 : 4, Save, then open Floor plan: the Toddler room's "Staff needed" now follows 1 : 4. Put it back to 1 : 5.

---

## Phase 2 · Children list and Child record

### 2.1 Children list

- [ ] Sidebar → Children. The "Records to finish" banner shows grouped counts: No guardian, No emergency contact, Immunizations due, Photo consent unsigned, Hours not agreed.
- [ ] Click "Photo consent unsigned". The list shrinks to those children (`?gap=`), Naomi (10003) among them. Click it again to clear.
- [ ] Status tabs with counts: Active, Pending, Withdrawn, Archived, All. The page opens on All.
- [ ] Room chips with counts filter the list. Pay chips DSS / Private filter it too; a child with either DSS number counts as DSS.
- [ ] Columns: LAN, Child (last, first + nickname), Room, DOB, Age, Schedule (five day dots), Pay, Guardian + phone, Alerts, Record. Click LAN, Child, Room and Age headings: the sort flips each time.
- [ ] Pending tab: Nia Brown (10110) shows "Starts Nov 1".
- [ ] Withdrawn tab: columns become Last room, Last day, Reason, Note, Could return, and a Re-enroll button.
- [ ] Archived tab (if hidden, turn it on under Settings → Children): Sam Okoye with a Restore button.
- [ ] Export downloads a CSV of the current filter. "Add child" opens the add form.
- [ ] Click Naomi's row: her record opens.

### 2.2 Child record · header and status

- [ ] Header: initials, name + nickname, Active, "80% complete", LAN, age with DOB, enrolled date, alert chips, missing-item chips (Photo consent).
- [ ] Actions: Message parents, Sign in / out, and a ⋯ menu with Edit details, Move to another room, Withdraw. Archive and Re-enroll appear once the child is Withdrawn; Start enrollment while Pending.
- [ ] **Pending → Active**: open Nia (10110). ⋯ → Start enrollment, pick today. She becomes Active and a line lands in Enrollment history.
- [ ] **Active → Withdrawn**: open Naseliz (10005). ⋯ → Withdraw, last day = yesterday, reason "Family choice", could return = Yes, note "Moving in spring". (The withdraw reasons are Moved out of the area, Changed provider, Started school / aged out, Subsidy ended, Family choice, Non-payment, Other. "Family changed plans" and the rest of that list belong to cancelling a Pending child.) She shows Withdrawn, drops off Teacher Attendance and the Schedule coverage, and appears on the Withdrawn tab with the reason and note.
- [ ] **Last day ahead**: open Pharaoh (10013). The amber banner says the last day is still to come; he is still Active and still on Teacher Attendance.
- [ ] **Withdrawn → Active**: open Iris (10107). Re-enroll. Same LAN, same family, Active again, history line written.
- [ ] **Withdrawn → Archived → Withdrawn**: open Naseliz again. ⋯ → Archive: read-only. Archived tab → Restore: back to Withdrawn.

### 2.3 Child record · sections

- [ ] **Overview**: days, drop-off / pick-up, hours a week (worked out from days and times), room, enrolled date, room history, DSS case no. + CIN, nickname. Edit, change the nickname, Save: the header updates.
- [ ] **Move to another room**: pick Toddler, starting today, Save. The room shows "set by hand" with a "Back to automatic" option and the age-based suggestion beside it. Press Back to automatic.
- [ ] **Family & pick-up**: one list with ticks per person for Guardian, Pick-up, Emergency, Pays, Texts; ↑/↓ call order on the people ticked Emergency; Edit / Unlink. Untick Texts on one parent and re-tick it (used by Messages below).
- [ ] Add person: search finds an existing parent of a sibling; a name with no match offers Create new with a relationship list.
- [ ] Home address and the custody note show. A child with no guardian shows "No legal guardian on file".
- [ ] **Health**: open Jaziel (10006). The allergy is one entry with type, severity, reaction, what to do and care plan date. Doctor, immunizations with next due, medical statement with renewal, medication below.
- [ ] **Enrollment history**: enroll, room move, schedule change and subsidy start each have their own line, newest first.
- [ ] **Enrollment note**: write "Pays on the 1st", Save. It is marked On DSS page. (It appears on the DSS row in Phase 8.)
- [ ] **Notes & comments**: teacher comments from check-in and office notes together, newest first. Add a note. A child with many notes shows "Show older".
- [ ] **To finish**: Naomi lists Photo consent with an Upload action.
- [ ] **Attendance · month**: days in, absent, average times, link to the register.
- [ ] **Documents**: upload a PDF, or mark the paper as on file. A medical statement dated today renews a year from now.
- [ ] **Record** footer: created date, last change by whom.

---

## Phase 3 · Director Attendance

- [ ] Sidebar → Director Attendance opens the weekly register at `/attendance`. Header: the same pill as Teacher Attendance — ‹, the week with a calendar icon, › — then Today, Print, and the ⋯ menu (Month sheet, Classic register). Press the week: a calendar opens with the week lit; pick any day and its week opens. Step back a week: Today becomes the way back.
- [ ] Room chips show `in / total`. Click Infant: the rows shrink to Infant.
- [ ] Summary line: enrolled, expected, in, still to come, not in. "Showing N" matches the rows on screen.
- [ ] Columns: LAN, Student, Classroom, DOB · Age, Hours, Mon to Fri. The Age column reads "3 y 2 m", not a date.
- [ ] Tap a past day cell for a child with no times: it cycles Not scheduled → Absent → Coming → Not scheduled. "Changes save as you tap"; the Last change line updates after each tap.
- [ ] A cell with recorded times shows them. A day that is not one of the child's days but has times shows the "still billable" marker.
- [ ] Click a time to edit it inline. ↑ adds a minute, Shift+↑ adds an hour, Esc cancels, Enter saves. The cell gets the "Time fixed by hand" dot.
- [ ] Try to put a time on a day next week: refused with a message.
- [ ] Press "Open check-in →": Teacher Attendance opens. The time you fixed shows there with the child, and Teacher Attendance itself works exactly as before (sign in, sign out, back, health).
- [ ] Teacher Attendance: a child with a health alert shows the amber alert box on their card.
- [ ] Print opens the printable week. The ⋯ menu's Classic register opens `/attendance/classic` with the cards and sheet modes as they were.

---

## Phase 4 · Dashboard and Floor plan

### 4.1 Dashboard

- [ ] Sidebar → Dashboard. Today's attendance cards, the progress bar with "Take attendance →", "Ratios right now" per room with "Open the floor plan →", Upcoming closures with "Manage holidays".
- [ ] Each room's ratio line agrees with the Floor plan's "Staff needed" for the same room.
- [ ] Note in work.md: the dashboard card set (Scheduled today, Checked out · returning, Staff on the floor) is still listed as open. Record here what is on the page today so the gap is known.

### 4.2 Floor plan

- [ ] Sidebar → Floor plan. Live label with "updated" time; counters: Checked in, Checked out · returning, Checked out, Expected, Staff on the floor, Rooms below ratio.
- [ ] The map shows every room plus Hallway, Office, Kitchen, the bathrooms and Front door · fire panel.
- [ ] Each room: `in · cap`, child dots (in / booked not in / free place), staff dots (clocked in / late / moved here / due later). The legend under the map explains the dots.
- [ ] A room with fewer staff than needed says "Need another adult"; the seeder leaves one room short in the morning.
- [ ] Tips: the short room suggests moving someone, and School Age shows the "returning at 3:00 needs 2 staff" tip.
- [ ] Press "Move X to Y until Z arrives" (director only; a teacher sees "Ask the director to …"). The page comes back with Y open, a green line "X is in Y from <time> until Z clocks in.", Y reads Covered with X on a blue "moved" dot ("In … · from <home>"), X's old room drops one staff, and Rooms below ratio drops by one. Schedule → today shows X's shift cut at that minute with a float in Y after it.
- [ ] Press it again: nothing changes (X is already in Y).
- [ ] Clock Z in at the kiosk: X's float ends at that minute and X is back in their home room on the floor plan and on the Schedule for the rest of the day.
- [ ] Give X a "Never assign to room: Y" Must rule and press the button: the amber line "A Must rule says X is never assigned to Y." and no shift changes.
- [ ] Toggle Morning / Afternoon: the afternoon picture is the day as booked at the School Age return hour.
- [ ] Tap a room: the panel shows Checked in, Expected, Out, Staff, Places free, Enrolled in this room, Teachers, and "Open <room> on the register →" opens Director Attendance filtered to that room.
- [ ] Leave the page open a minute: it refreshes itself.

---

## Phase 5 · Staff

- [ ] Sidebar → Staff. List with a status dot per person (hover: In since / Worked earlier / Late / Due / Off). "Not on the schedule" holds the director and anyone Inactive or a Cook. Export and "+ Add staff" at the top.
- [ ] **Details**: contact block, Employment (type, role, home room, start date, ASPIRE ID, last evaluation), Time clock (Staff ID, card, PIN with Reprint / Reset), Pay behind Show (director only), AngelNest login with "Send a new temporary password".
- [ ] Change a mobile number, Save, see it in the header block. Put it back.
- [ ] **Rules**: Must pills black, Prefer pills blue, director-only note under each. Add "Cannot start before 12:00 PM on TUE" as a Must. Edit it to 1:00 PM. Delete it.
- [ ] A teacher with no rules shows the dashed "available every weekday" box with the centre's hours.
- [ ] **Attendance**: pick a range; Days worked, Hours worked, Scheduled hours, Late arrivals, Punches to fix. Fix on a row opens that day on Staff attendance.
- [ ] **Time off**: add "Out all day next Tuesday, Appointment, Paid". The impact line reads against their shift. Past entries fold under "Past · year" with totals.
- [ ] Log in as the Infant teacher and open `/staff`: Pay is not shown.

---

## Phase 6 · Staff attendance

- [ ] Sidebar → Staff attendance opens Live. Cards: Clocked in now (of due), Late / not in, Due later today, Off today, Hours so far today. "Today · live as of" shows the time.
- [ ] Filter chips All, Clocked in, Not in, Due later, Off change the rows. Columns: Staff, Room now, Scheduled, In, Out, Status, Hours.
- [ ] The person given time off in Phase 5 appears under Off on that day.
- [ ] Press History. A ‹ week › grid with one cell per weekday and a Week total per person. Cells are coloured for late, forgot to clock in, no clock-out. This week goes back to the current week.
- [ ] Click a flagged cell. "Correct a day" opens with "What the clock recorded" (the original punches), Clock in, Clock out, unpaid break, Counts as (Worked / Paid time off / Sick / Unpaid absence), a reason list (Forgot to clock in, Forgot to clock out, Clock wasn't working, Wrong time punched, Other) and a note.
- [ ] Save without a reason: refused. "Other" is refused without a note. Pick "Forgot to clock out", set the out time, Save. The cell updates, keeps the original punch underneath, and "Changes this week" lists the change with your name.
- [ ] Set Counts as = Sick on an empty past day, Save: the day reads Sick and the person shows under Off for that day on Staff → Time off. Set it back to Worked: the time-off entry goes.
- [ ] Export downloads the week as CSV.
- [ ] Log in as a teacher and open `/staff-timesheets`: refused.

---

## Phase 7 · Schedule

- [ ] Sidebar → Schedule. ‹ Week › and This week; a status line reading Not published (draft) with the change counter; Generate, Print for staff, Publish.
- [ ] Press Generate: a fresh draft for the week; teachers see nothing of it yet (check as the Infant teacher: Schedule and My Schedule say the week is not published).
- [ ] Views: By teacher / By room, Bars / Simple table. A dotted bar is someone who moves rooms that day.
- [ ] By room: the coverage strip under each room shows staff vs need by time, peak children, ratio and capacity. A fully covered room reads "Every room at ratio"; a room with nobody reads "No staff in this room today".
- [ ] Drag a bar from Infant to Toddler. Before it saves, the preview shows the effect on both rooms: short, more than needed, rules broken. Press Save.
- [ ] Drag a bar for someone with a "Never assign to room" Must rule into that room: "Blocked by a Must rule", nothing saved. A Prefer rule only reports.
- [ ] Drag a name from the "Not working" list onto a room to add a shift.
- [ ] The person with time off next Tuesday shows a clash card; "Keep 12p–3:30p" trims the shift, or Remove takes it off.
- [ ] "Who could cover" lists free staff with no Must rule broken; when nobody is free it says to call a substitute.
- [ ] Click a bar → Time off: Appointment, Sick, Personal, Vacation, Family. Add Sick for that day. The same entry appears on Staff → Time off and under Off on Staff attendance.
- [ ] "How the checks work" opens the help panel.
- [ ] Print for staff opens a plain sheet in a new tab.
- [ ] Press Publish. The status line names you and the time; log in as the Infant teacher: the week now shows on Schedule and My Schedule.
- [ ] Only staff with "May open the building" are given a start before the earliest start; everyone else begins at it. Known gap (work.md 1.2): the generator still reads that time from `config/daycare.php`, not from the value saved on Settings → Rooms & hours, so changing it there does not move the schedule yet.

---

## Phase 8 · DSS attendance and Reports

### 8.1 DSS attendance

- [ ] As the director (named in `DAYCARE_DSS_USERS`), Sidebar → DSS opens the month grid. Log in as a teacher and open `/dss`: refused.
- [ ] ‹ month ›; "Subsidy only · N" toggle; Class filter (multi-select); the grid, totals and export all follow it.
- [ ] Rows: child, agreed hours, CIN, a withdrawn marker for Naseliz (10005), and the enrollment note you wrote in Phase 2 on hover. Columns: the days, HOURS, AGREED.
- [ ] Cells show IN / OUT. A School Age child has AM and PM halves; an afternoon-only visit reads "PM only".
- [ ] Click a past cell, press H: filled with the agreed hours. Press A: Absent. Press N: Not sched. Press Del: cleared. Apply from the toolbar does the same as the keys.
- [ ] Shift+click a block of cells, Ctrl+C, select another block, Ctrl+V: the times copy. Click a date to take the column, click a child to take the row. "Repeat day →" fills the rest of the selection from the first day.
- [ ] Undo (Ctrl+Z) puts the last change back.
- [ ] A week over the authorized hours shows the over-cap warning in the week total.
- [ ] Centre-closed days and days before a child's start are greyed and cannot be edited. A day still to come refuses times.
- [ ] Open Director Attendance for the same child: the times and the Absent mark you set here show there too. The child record's Attendance · month counts the absence.
- [ ] Export for KinderConnect: Range (Month to yesterday / This week so far), Include absences, Download .csv / .json. The preview lists the checks (missing CIN, over cap, visits over 10 h). The file has one row per visit, School Age as AM and PM rows, and no day later than yesterday.

### 8.2 Reports

- [ ] Sidebar → Reports. Report picker (Child attendance, Staff attendance, Attendance sheet), "DSS attendance →" shortcut. Filters: Month, Room, Show (In / out times or Present / Absent) on the child report; Room on the sheet; Dates (pay period) on the staff report.
- [ ] Month is a stepper, not a row of months (Jordan, 27 Oct 2026): ‹ the month › with "This month" lit. Step back: the month turns blue, › steps forward again, and "↩ This month" is the way back. On this month › is greyed, since the reports are about days gone.
- [ ] **Child attendance**: grouped by room; Present, Absent, Hours per child; A on absent days, closed days marked. The times are the ones Teacher Attendance recorded.
- [ ] **Staff attendance**: pay period; Hours, Breaks, Late, Edited, Over 40. The day you corrected in Phase 6 carries the ✎ mark (hover: Edited by director). Late is more than 5 minutes after the shift.
- [ ] **Attendance sheet**: Month, School Site, Grade, ✓ / A per day, Total Days Absent, Notes (from the child record), Grand Total. "Open as PDF" downloads the letter-landscape sheet.
- [ ] Press a day cell on the sheet (a day already gone): ✓ turns to A, A to blank, blank to ✓, and the row's Total Days Absent and the Grand Total redraw without a reload. The DSS page shows the same day as Absent / Not scheduled / present at the agreed times. Today's column and the empty rows do nothing.
- [ ] Room chips above the sheet: it opens on Infant, the first room (Jordan, 27 Oct 2026); pick Toddler and the sheet redraws for that room, Grade reads "Toddler", the .csv and PDF are named for the room, and the monthly-email panel (school district, UPK-4 only) is not shown.
- [ ] Download .csv on each report gives the sheet as a file.
- [ ] **Monthly email**: add an address and press Enter; set the send day and time; tick Attach PDF and CSV; tick "hold if sign-ins are missing". Save. "Send a test to me" puts the mail in the log. Send now puts the month's sheet in the log and adds a line under Sent.
- [ ] Log in as the Infant teacher: only the Child attendance card; the Staff and UPK-4 exports are refused by address (`/reports/export?report=staff`).
- [ ] The Weekly sign-in sheet card is parked (Jordan, 27 Oct 2026): not in the picker, but `/reports?report=week` and the register's date / classroom links still open it.

---

## Phase 9 · Messages

- [ ] Sidebar → Messages. Conversation list with unread state, "New message".
- [ ] New message → Room. Type a child's name; the family is chosen. "Who can see this conversation" reads Teachers in the room + you; "Texts go to" the Classroom line. The recipients are the parents with the Texts tick; a parent without it shows under Skipped with the reason.
- [ ] Type "we are haveing a picnic friday pls bring sun screen" and press ✨ Polish. The fixed text appears with Use this / Keep mine. Use this replaces your draft.
- [ ] Send. The message shows in the thread; with the log driver the text is in `storage/logs/laravel.log` and nothing was sent.
- [ ] New message → Office for the same child. Send a line. Log in as the Infant teacher: the Room thread is visible, the Office thread is not.
- [ ] New message → Announcement. Everyone with the Texts tick is listed; Send shows delivered and skipped counts. Replies to an announcement go to the Office thread.
- [ ] Attach a photo to a Room message for Naomi (no photo consent on file): the warning names her, the send still goes, the list marks who has no consent. Attach a video: it goes as a signed link that works signed out and expires after 30 days.
- [ ] The sidebar's Messages badge shows the unread count; opening the thread clears it.
- [ ] "Message parents" on a child's record opens New message with that family chosen.
- [ ] Reply from a parent: `POST /sms/inbound` with the parent's number and "STOP" marks them opted out; the next send skips them; "START" lets them back in.

---

## Final cross-check

- [ ] Teacher Attendance (Phase 0) behaves as approved: sign in, sign out, back, health, comments, kid icons, no change in layout.
- [ ] Naseliz (withdrawn in Phase 2) is off Teacher Attendance, the Schedule's coverage counts, the Floor plan and the Announcement list, but her days up to the last day are still on the DSS export and the Child attendance report.
- [ ] The Phase 1 ratio change moved the Floor plan's Staff needed and the Schedule's coverage strip.
- [ ] The Phase 5 / Phase 7 time off shows on the Schedule and under Off today on Staff attendance.
- [ ] The Phase 6 correction shows on the Staff attendance report with ✎.
- [ ] As a teacher: no Office threads, no DSS page, no Pay block, no Staff attendance, no Reports beyond Child attendance.
