# Phase 2 manual test: Children list and Child record

A walk-through to check the Phase 2 work by hand, in the order a director would meet it. Each step says what to do and what you should see. Tick the box when it matches; write what you saw next to any step that does not.

## Before you start

1. Run the migration and the demo seeder on the local database (both already ran once; running the seeder again is safe, it only fills blanks):

   ```
   php artisan migrate --force
   php artisan db:seed --class=ChildRecordDemoSeeder --force
   npm run build
   ```

2. Log in as the director: `admin@daycare.test`.
3. The seeder added eight children you will use below:

   | LAN | Name | Starts as | Used for |
   |---|---|---|---|
   | 10110 | Nia Brown | Pending, starts Nov 1 | Pending → Active |
   | 10107 | Iris Tan | Withdrawn | Re-enroll |
   | 10109 | Kai Morgan | Withdrawn | Archive and Restore |
   | 10113 | Leo Park | Cancelled before starting | Withdrawn tab, cancel reason |
   | 10114 | Zoe Hale | Active, re-enrolled | History of leaving and coming back |
   | 10111 | Sam Okoye | Archived | Archived tab |

4. It also filled real records on the active roll so every feature has a case to look at:

   | LAN | Name | Shows |
   |---|---|---|
   | 10003 | Naomi Merced Ayala | 80% complete, photo consent missing (the editing walk-through) |
   | 10005 | Naseliz Cruz | Complete record, used for the withdraw test |
   | 10013 | Pharaoh Lumpkins | Leaving: last day Nov 6 still ahead, amber banner, still on check-in |
   | 10002 | Maeve Adkins | Twelve notes, so "Show older" appears |
   | 10006 | Jaziel Cruz | Allergy alert with severity, reaction and action; subsidy line in history |
   | 10014 | Mikayla Macakanja | Court order alert |
   | 10023 | Brinton Bentley | A person who must not collect |
   | 10004 | Kasey Bolton | Room set by hand, immunizations due soon |
   | 10007 | (see list) | No immunization date on file |
   | 10031 | (see list) | No hours agreed |
   | 10018 | Sianna Radford | An expired sunscreen consent in Documents |
   | 10011 | (see list) | Custody note |
   | 10008 | (see list) | Medication on file |
   | 10022 | Rayjean Remir Robinson | A teacher's check-in comment in Notes & comments |

---

## A. Children list (`/children`)

**A1. Tabs and counts**
- [ ] Open Children. The Active tab is selected and every tab shows a count: Active, Pending 1, Withdrawn 4, All.
- [ ] Archived tab is only there if Settings › Children › "Show archived" is on. Turn it on, reload, and Archived 2 appears. (Leave it on for section E.)
- [ ] Press Pending. Only Nia Brown is listed, with a blue "Pending" pill and "Starts Nov 1" in the Record column.
- [ ] Press All. Active, pending and withdrawn children are all listed; archived are not.

**A2. Records to finish banner**
- [ ] Back on Active, a yellow banner above the table says how many records need finishing, with a button per gap that has anyone in it (immunizations, photo consent, hours; every real record has a guardian, so that button is not there).
- [ ] Press "photo consent". The list shrinks to only those children, and Naomi Merced Ayala is among them. The Record column on her row reads "Photo consent unsigned".
- [ ] Press the same button again. The filter clears and the full roll is back.

**A3. Room and pay chips**
- [ ] Press the Toddler chip. Only Toddler children are listed and the tab counts drop to match.
- [ ] With Toddler still on, press DSS. Only Toddler children on a subsidy are listed. Press Private: the other half.
- [ ] Press the lit chip again to clear it. Switching tabs keeps the room chip on.

**A4. The row**
- [ ] Each row shows: LAN, avatar with "Last, First" and nickname in brackets, room with colour square and icon, DOB, age, five day dots with the hours under them, a Pay pill (DSS or Private), guardian name and phone, alert pills, Record column (Complete, Starts date, or the first missing item with "+n").
- [ ] Hover the day dots: the tooltip spells the days out.
- [ ] Scroll the table sideways on a narrow window. LAN and Child stay frozen on the left and nothing overlaps.
- [ ] Type "Naomi" in the search box. The list filters as you type. Clear it.
- [ ] Click anywhere on Naomi's row. Her record opens.

**A5. Withdrawn tab**
- [ ] Press Withdrawn. The table changes columns: Last room, Last day, Reason, Note, Could return, and a Re-enroll button on each row.
- [ ] Iris Tan, Theo Marsh and Kai Morgan are listed with a reason and a could-return answer each.
- [ ] Leo Park is there too, with the cancel reason "Chose another provider": a child who never started still shows how he left.
- [ ] Zoe Hale is not on this tab. She is on Active, and her record's history reads Enrolled, Withdrawn, Re-enrolled.

**A6. Export**
- [ ] Press Export on the Active tab. The spreadsheet downloads and the last six columns are Withdrawal reason, Withdrawal note, Could return, Enrollment note, Health, Custody note.
- [ ] Export from the Withdrawn tab. Only the three withdrawn children are in it, and the Withdrawal reason column is filled.

---

## B. Child record, reading it (`/children/10003`, Naomi)

**B1. Header**
- [ ] Name with nickname, a green Active pill, "Record 80% complete" bar, LAN · Toddler · age with DOB · Enrolled date.
- [ ] A "Photo consent unsigned" chip under the name. Alert chips if she has any.
- [ ] Buttons: Message parents (opens Messages), Sign in / out (opens check-in on the Toddler room), ⋯ menu (Edit details, Move to another room, and the one lifecycle action that fits her status: Withdraw… for an active child).
- [ ] The row of anchor links under the header (Overview, Family, Health, History, Notes) jumps to each card.

**B2. Cards, left column**
- [ ] Overview: days, drop-off and pick-up, hours a week worked out from them, pay (DSS case and CIN or Private), room with "Move to another room".
- [ ] Family & pick-up: one table. Marangeli Ayala Torres is the guardian, Sheila Alequin and Yohan Caseres are friends. Columns Guardian / Pick-up / Emergency / Pays / Texts show ticks. Under the table: "Cleared to collect: …" names everyone with the pick-up tick.
- [ ] Health: each alert is its own line with severity, reaction, action. Then doctor, immunizations, medical statement, medication, custody note.
- [ ] Enrollment history: a timeline, newest first, with the enrollment note at the top.
- [ ] Notes & comments: teacher check-in comments and office notes mixed, newest first. Filters All / Teacher / Office / Health work.

**B2a. The same cards on other records**
- [ ] `/children/10002` (Maeve Adkins): Notes & comments shows eight and a "Show older (4)" button; press it and twelve are listed.
- [ ] `/children/10006` (Jaziel Cruz): Health lists an allergy with severity, reaction and action, and a care-plan date. Enrollment history has a "Subsidy · DSS case …" line.
- [ ] `/children/10014` (Mikayla Macakanja): a red court-order pill in the header and the alert line says what to do.
- [ ] `/children/10023` (Brinton Bentley): under the family table, "Must not collect: Rob Bentley".
- [ ] `/children/10004` (Kasey Bolton): Overview says "Set by hand from Sep 30, 2026" with a Back to automatic button, history has a room line, and To finish lists "Immunizations due".
- [ ] `/children/10007`: Health says no immunization date on file and To finish lists it.
- [ ] `/children/10031`: To finish lists "Hours not agreed" and the list row has no day dots.
- [ ] `/children/10018` (Sianna Radford): Documents shows the sunscreen consent as "Expired <date>" in red.
- [ ] `/children/10011`: a custody note under Health. `/children/10008`: medication listed.
- [ ] `/children/10022` (Rayjean Remir Robinson): a teacher's comment in Notes & comments with the Teacher filter keeping it and the Office filter hiding it.
- [ ] `/children/10114` (Zoe Hale): history reads, newest first, Re-enrolled, Withdrawn, Enrolled.
- [ ] `/children/10013` (Pharaoh Lumpkins): Active pill, an amber banner "last day Nov 6", history has the withdrawn line, and he is still on the PreK check-in roster.

**B3. Cards, right column**
- [ ] To finish: "1 item", Photo consent unsigned, with an "Upload signed form" button right there.
- [ ] Attendance this month: a calendar of the current month. Weekdays she was signed in are marked, absences marked, today outlined, closures greyed, future days faint. Average in and out times under it.
- [ ] Documents: the seeded documents listed with On file / Renews / Expires status. An Upload button.
- [ ] Record footer: created, last updated and by whom.

---

## C. Child record, editing it (still Naomi)

**C1. Overview**
- [ ] Press Edit on Overview. Change pick-up to 17:00 and untick Friday. Save.
- [ ] The card shows the new hours and four day dots. Enrollment history gained a "Schedule changed" entry.
- [ ] Back on `/children`, her row shows four dots.

**C2. Move room**
- [ ] Press "Move to another room". Pick PreK, from today, reason "Test move". Save.
- [ ] Header says PreK. Overview says "Set by hand from <today>" with a "Back to automatic" button. History gained "Toddler → PreK" with the reason.
- [ ] Press "Back to automatic". Room returns to Toddler and history gains another entry.

**C3. Family**
- [ ] Press "+ Add person". Search "Yohan" and the existing person is offered; instead type a new name "Test Aunt", relationship Aunt, tick Pick-up and Emergency. Save. The row appears, and "Cleared to collect" now names her.
- [ ] Press ↓ on the guardian row. The order changes. Press ↑ to put it back.
- [ ] Press Edit on Test Aunt's row, untick Pick-up, save. She leaves "Cleared to collect".
- [ ] Press Unlink on Test Aunt. The row goes; the person is still in People.

**C4. Health**
- [ ] Press "+ Add alert": type Allergy, "Peanuts", severity Severe, reaction, action "EpiPen in office". Add.
- [ ] A red allergy pill appears in the header and on her row in `/children`. The alert line shows the reaction and action.
- [ ] Press Remove on it. Gone from header and row.
- [ ] Press Edit on Health. Set immunizations due to a date 10 days from now. Save. To finish now lists "Immunizations due" and the completeness bar drops to 70%.
- [ ] Set it to a date next year. Save. It leaves To finish and the bar returns to 80%.

**C5. Documents**
- [ ] In To finish, press "Upload signed form" beside Photo consent and choose any PDF or image.
- [ ] Photo consent leaves To finish, the chip under the name goes, the bar reads 90%, Documents lists "Photo consent, On file", and clicking it opens the file.
- [ ] Press Upload on Documents, choose Medical statement with today's signed date. It lists as "Renews <same date next year>".

**C6. Notes**
- [ ] Press "+ Add note", type "Office test note", kind Office. Save. It is at the top of Notes & comments with your name. The Teacher filter hides it, the Office filter shows it.
- [ ] Press Edit on the enrollment note, type "DSS paperwork received", save. It shows in Enrollment history with an "On DSS page" pill.

**C7. Record footer**
- [ ] "Last updated" now shows today and your name.

---

## D. Lifecycle

**D1. Pending → Active (Nia Brown, 10110)**
- [ ] Open `/children/10110`. Blue "Pending" banner: "Starts Nov 1". She is not on the check-in roster.
- [ ] Press the status pill. The menu offers Start enrollment and Cancel enrollment.
- [ ] Press Start enrollment, set first day to today, Save. Pill turns Active, banner goes, history reads "Started", and she appears on the Infant check-in roster.

**D2. Cancel a pending child**
- [ ] Add a child from `/children` with status Pending and a start date next month (use LAN 99901, "Test Cancel"). Open the record, status pill → Cancel enrollment, pick a reason, note "never started". Save.
- [ ] Pill reads Withdrawn, history reads "Cancelled" with the reason. On the Withdrawn tab the row shows the reason and note.

**D3. Withdraw with a future last day (Naseliz Cruz, 10005)**
- [ ] Status pill → Withdraw…. Last day = next Friday, reason Moved away, could return No, note "test". Save.
- [ ] Pill stays Active, an amber "Leaving" banner says the last day, history reads "Withdrawn, last day <date>". She is still on the Toddler check-in roster.
- [ ] Run `php artisan children:sync-enrollments`. It prints 0 started, 0 withdrawn: neither she nor Pharaoh Lumpkins (last day Nov 6) has passed their day, so both stay Active.
- [ ] To finish the test without waiting: Status pill → Withdraw… again with last day = yesterday. Pill turns Withdrawn, banner turns grey, she leaves check-in and appears on the Withdrawn tab.

**D4. Re-enroll (Iris Tan, 10107)**
- [ ] Open `/children/10107`. Grey Withdrawn banner with reason and could-return. Press Re-enroll (in the banner or the status menu). Pill turns Active, history reads "Re-enrolled", she is back on the Active tab and the Toddler roster. Same LAN, same family.
- [ ] Also re-enroll Naseliz from the Withdrawn tab's Re-enroll button so she is Active again.

**D5. Archive and Restore (Kai Morgan, 10109)**
- [ ] Open `/children/10109`. Status pill → Archive…, confirm. Pill reads Archived, banner says archived, he leaves the Withdrawn tab and appears on Archived.
- [ ] On the Archived tab press Restore on his row. He returns to Withdrawn, record shows Withdrawn again.
- [ ] A child that is Active has no Archive option on the status pill (only via Withdraw first).

**D6. Nightly command**
- [ ] `php artisan schedule:list` shows `children:sync-enrollments` at 00:10 daily.
- [ ] `php artisan children:sync-enrollments` prints how many started and how many withdrawn.

---

## E. Who may do what

Log out and log in as the Toddler teacher: `toddler.teacher@daycare.test`.

- [ ] `/children` lists only Toddler children. Tabs, chips and search still work. The Records to finish banner is not shown.
- [ ] Open a Toddler child. The status pill has no menu (no start / cancel / withdraw / archive) and there is no "Move to another room". Everything else (Overview edit, family, health, alerts, notes, documents) can be edited.
- [ ] Open a PreK child by typing `/children/10002` (Maeve Adkins). 403.
- [ ] Open `/children?status=Archived`. Nothing from other rooms leaks in.

Log back in as the director and undo anything above that you want reversed. There is no delete for a child: "Test Cancel" (LAN 99901) stays on the Withdrawn tab, so archive it from its record if you do not want to see it.

---

## F. Tidy-up checklist

After the run, the database should look like this. Tick what matches:

- [ ] Nia Brown is Active.
- [ ] Iris Tan and Naseliz Cruz are Active again.
- [ ] Kai Morgan and Leo Park are Withdrawn; Sam Okoye and Mila Novak are Archived; Zoe Hale is Active.
- [ ] Pharaoh Lumpkins is still Active with his last day ahead (he withdraws by himself the night after Nov 6).
- [ ] Naomi has 90% or more, a photo consent and a medical statement on file.
- [ ] Nothing committed yet; `git status` lists the Phase 2 files.

## Anything wrong?

Note the section letter and step, what you pressed, and what showed instead, and hand the list back.
