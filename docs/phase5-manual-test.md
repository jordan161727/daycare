# Phase 5 manual test: Staff

A walk-through to check the Staff page by hand. Each step says what to do and what you should see. Tick the box when it matches; write what you saw next to any step that does not.

## Before you start

1. Migrate and seed (both already ran locally; the seeder only fills blanks):

   ```
   php artisan migrate --force
   php artisan db:seed --class=StaffDemoSeeder --force
   npm run build
   ```

2. Log in as the director: `admin@daycare.test`.
3. The seeder filled the teachers alphabetically. The first six carry the Time off entries:

   | Teacher (by name order) | Has |
   |---|---|
   | 1st (Amanda Cole) | Out until 3:00 PM next Tuesday (appointment), plus a sick day, an appointment and a two-day vacation in the past |
   | 2nd (Beverly Ng) | Out all day next Tuesday (personal) |
   | 3rd (Denise Hall) | Out all day next Wednesday (vacation, paid) |
   | 4th (Evana Rahman) | Leaving at 12:00 PM next Thursday (family) |
   | 5th (Farhana Begum) | Extra day next Wednesday 9:00 – 3:00 |
   | 6th (Frenci Lopez) | A two-day vacation two months ago |

   Every teacher with no rules got some: the 1st can open and must never work with the 2nd; the 2nd has a fixed Wednesday and closes; the 3rd must never be alone; the 4th cannot work Monday or Friday; and so on.

---

## A. The list

- [ ] Sidebar → Staff opens `/staff` with the first teacher selected. There is no Kiosks link anywhere on the menu, and no "MOCKUP" badge on the page.
- [ ] Left card: every teacher with initials, "FT · Room" under the name, and a dot on the right. Hover a dot: it says In since…, Worked earlier today, Late · not in yet, Due…, or Off today.
- [ ] Under the list, "Not on the schedule" names the director (Administrator · Admin) and anyone Inactive or a Cook.
- [ ] Type "zam" in Search staff. Only Zameera stays. Clear it.
- [ ] Press Export at the top. A CSV downloads with one row per person and a Status today column.
- [ ] Press "+ Add staff". The existing add form opens.
- [ ] Click a name. The right card changes to that person; the URL is `/staff/{id}`.

## B. Details tab

- [ ] Header: big initials, name, "Lead Teacher · FT · Transition", a status pill on the right.
- [ ] Contact: name shown in the app, legal name, mobile, email, emergency contact, emergency phone, getting to work.
- [ ] Employment: type (FT / PT / PT flex / Substitute / Inactive…), role, home room, start date, ASPIRE ID, last evaluation.
- [ ] Change the mobile to 555-0100 and the role to Assistant. Save changes. The green banner says saved, the header reads "Assistant · …", and the mobile shows 555-0100. Put them back.
- [ ] Time clock card: Staff ID, Card (issued date or Not issued), 4-digit PIN (Set / Not set).
- [ ] Press Issue card (or Reprint card). Confirm. The QR appears with Download PNG and Print, and a warning that it cannot be shown again. Reload: the image is gone, the Card line shows today's date.
- [ ] Press Set PIN (or Reset PIN). Two boxes appear. Type 2468 twice, Save PIN. "PIN set" banner; the line reads Set.
- [ ] Pay card says "Director only" with dots. Press Show: the rate and direct deposit become editable. Press Hide.
- [ ] AngelNest login: Can sign in Yes · teacher, Last sign-in (Never, Yesterday, or a date). "Send a new temporary password" asks to confirm; after it the banner says an email went (or shows the password if mail is not set up locally).

## C. Rules tab

- [ ] The tab reads "Rules · N". Each rule has a black Must or blue Prefer pill, the sentence, and the director-only note under it. "Give as many hours as possible", "Half days" and "Prefers full days" say the schedule does not act on them yet.
- [ ] Add a rule: pick "Cannot start before a time", day TUE, From 12:00 PM, Must, note "Class on Tuesday mornings". Add rule. It appears in the list and the tab count goes up by one.
- [ ] Press Edit on that rule. The form title changes to "Edit the rule" with its values loaded. Change the time to 1:00 PM, Save rule. The sentence updates.
- [ ] Press Delete on it, confirm. Gone; count goes down.
- [ ] Open a teacher with no rules: the dashed box says they are treated as available 7:00 AM – 6:00 PM every weekday.

## D. Attendance tab

- [ ] Four range buttons (Last week, This week, Last 2 weeks, This month) plus a from / to pair with Show, and Export.
- [ ] Cards: Days worked, Hours worked, Scheduled hours, Late arrivals (amber), Punches to fix (red).
- [ ] The table has one row per weekday: Day, Room, Scheduled, In, Out, Hours, Status. Days with no shift read "Not scheduled" and Off. Future days with a shift read Tomorrow or Upcoming.
- [ ] A past day with a shift but no punch reads "Forgot to clock in" with Fix →. A day clocked in but never out reads "No clock-out" with Fix →. Fix → opens that day on the timesheet.
- [ ] Clock a teacher in at the wall clock (`/clock`, their PIN) and look at their row today: "Today · on shift" with the In time.
- [ ] Press Export. The CSV has the same rows.

## E. Time off tab

- [ ] Open Amanda Cole (1st). Tab reads "Time off · 1". Under Coming up: a calendar leaf with the date, "Out until 3:00 PM · available after", an amber Appointment pill, Unpaid, a bar with the morning striped out, a red impact line ("… needs cover 7:00 AM – 3:00 PM"), the note, and "Added by Administrator · date".
- [ ] Press the five type chips in turn. The From / To boxes appear or hide to match (Out all day: none; Out until: Available from; Leaving at: Leaves at; Only available: both; Extra day: both and the reason locks to Extra day).
- [ ] Pick Out all day, next Monday. The preview line turns red: "Scheduled … Saving removes the shift and the Schedule shows who could cover."
- [ ] Pick Out until, 3:00 PM. The preview says only the afternoon can stay and the room needs cover for the morning. Set 6:00 AM: "Already starts after that — no change."
- [ ] Pick a date they do not work (Saturday). Preview: "Not on the schedule that day — nothing to fix."
- [ ] Save an Out until for next Monday with reason Appointment and a note. It appears under Coming up with the same impact line; the tab count goes up.
- [ ] Press Edit on it. The form loads it with "Edit the change"; set Paid, Save. The pill reads Paid (PTO / sick).
- [ ] Press Remove, confirm. Gone.
- [ ] Past · 2026 shows pills like "1 sick day", "1 appointment", "2 vacation days" and a "Show 3" button. Press it: the folded rows appear with date, what, reason, pay. Press Hide.
- [ ] Leave the form empty and Save: red errors at the top of the page say what is missing. Set a window 3:00 PM – 12:00 PM: "The end has to be after the start."

## F. The schedule honours it

- [ ] Open Schedule and generate next week. The 2nd teacher has no shift on Tuesday (out all day); the 1st starts at or after 3:00 PM on Tuesday; the 5th has a Wednesday shift 9:00 – 3:00 although that is a day their rules shut.
- [ ] Remove the 2nd teacher's Tuesday entry and generate again: the shift is back.

## G. The clock on the wall

- [ ] On a day a teacher has a shift and a change (e.g. set "Leaving at 12:00 PM" for today on anyone with a shift today), open `/clock` and identify as them. Under "Not started today" a blue line reads "Scheduled 9:00 AM – 6:00 PM · Room — Leaving at 12:00 PM · family". No change to the buttons; clocking in works as before.
- [ ] Identify as somebody with nothing planned today: no blue line.

## H. Nothing older moved

- [ ] `/teachers` (the old roster) still opens with its filters.
- [ ] Settings › Devices, the door kiosk and the staff clock behave as before.

## Anything wrong?

Note the section letter and step, what you pressed, and what showed instead, and hand the list back.
