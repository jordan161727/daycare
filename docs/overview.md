# The website, end to end

One document for the whole app as it stands today: what each screen is for, who
may open it, the rule behind it, a script you can follow by hand to see it work,
every button on it, and the order the whole thing runs in.

Parts three to six were separate files in `docs/` until they were folded in
here, and are reproduced whole — nothing was summarised away. `user-stories.md`,
`walkthrough.md` and `leave.md` are still their own files and go deeper on the
attendance sheet and on leave than Part one does.

Every story is written as **the rule**, then **why**. Part two walks the same
ground as numbered steps with what you should see; Part seven lists the controls
screen by screen; Part eight says what has to happen before what.

---

## What is in here

| | |
|---|---|
| [Setting up](#setting-up) | seeds and logins |
| [The shape of it](#the-shape-of-it) | the two roles, and what each may reach |
| **Part one** — [the rules, by area](#part-one--the-rules-by-area) | A–K, every screen as a rule and its reason |
| **Part two** — [walkthrough](#part-two--walkthrough) | 30 numbered steps you can follow by hand |
| **Part three** — [copying a week](#part-three--copying-a-week) | where a schedule comes from, and why a forecast never writes one |
| **Part four** — [leave, in detail](#part-four--leave-in-detail) | earning it, asking, deciding, and what it does downstream |
| **Part five** — [payroll preparation](#part-five--payroll-preparation) | clock, roster, corrections, and the CSV at the end |
| **Part six** — [the staff room walkthrough](#part-six--the-staff-room-the-roster-and-payday) | rules, roster and payday, step by step |
| **Part seven** — [user guide](#part-seven--user-guide-every-button-screen-by-screen) | every button on every screen, who sees it, what it does |
| **Part eight** — [workflows](#part-eight--workflows-what-has-to-happen-before-what) | the order things go in, and the orderings that go wrong quietly |
| [Where the rules live](#where-the-rules-actually-live) | behaviour → the class that enforces it |

## Setting up

```bash
php artisan migrate:fresh --seed                       # users, teachers, rooms
php artisan db:seed --class=DemoScenarioSeeder         # 13 children, one live week
php artisan db:seed --class=CanadianHolidaySeeder      # statutory closures
```

| Login | Password | Sees |
|---|---|---|
| `admin@daycare.test` | `password` | everything |
| `toddler.teacher@daycare.test` | `password` | their own room, their own week |

Other teacher logins follow the same pattern — `infant.teacher@`, `prek.teacher@`,
`schoolage.teacher@`, `transition.teacher@`, `upk4.teacher@`.

Optional extras: `LeaveDemoSeeder` (requests waiting on a decision),
`TimesheetDemoSeeder` (a pay period to approve), `SampleWeekSeeder`.

---

## The shape of it

Two roles, and the split is the whole security model.

```
                    ┌──────────────────────────────┐
                    │           DIRECTOR           │  role: admin
                    │  everything below, plus:     │
                    │  children · teachers · rules │
                    │  holidays · rooms · reports  │
                    │  leave decisions · balances  │
                    │  payroll prep · payroll      │
                    └──────────────┬───────────────┘
                                   │
                    ┌──────────────┴───────────────┐
                    │           TEACHER            │  role: teacher
                    │  dashboard                   │
                    │  class attendance (own room) │
                    │  children (own room)         │
                    │  week schedule (read only)   │
                    │  my schedule · my leave      │
                    │  time clock · reports        │
                    │  profile                     │
                    └──────────────────────────────┘
```

A teacher is never shown a control they may not use. The server enforces it
anyway — `role:admin` middleware on the route, not a hidden link.

---


# Part one — the rules, by area
## A. Getting in

### A1 — A new account must choose its own password

**The rule.** A director creates a teacher and types a starting password, which
is emailed. On first login that account can reach exactly four things: the
change-password form, its submit, logout, and the CSRF endpoint. Everything else
redirects back to the form.

**Why.** The password was typed by one person and sent over email. It should
stop guarding a payroll screen the moment the real owner arrives.

### A2 — Everyone keeps their own details current

**The rule.** **Profile** is open to every role: name, contact details, photo,
and password. Nobody edits anybody else's here.

---

## B. Dashboard

### B1 — The numbers are the ones you may see

**The rule.** Total children, present today, absent, rooms. A teacher's counts
cover their own rooms only; a director's cover the centre.

**Why.** The same screen for both roles, scoped by who is reading it, rather
than a second dashboard to keep in step.

### B2 — Upcoming closures, because a teacher cannot reach the holidays page

**The rule.** The next four closed days, nearest first, each linking to that
week on the attendance board. Anything inside seven days is coloured. Directors
also get a **Manage holidays** link; teachers do not, because it would 403.

**Why.** Closures are set by the director on a page teachers cannot open.
Without this, a teacher meets Labour Day as a greyed-out column on the morning
itself — too late to have arranged anything.

---

## C. Class attendance — the sheet the day runs on

Full detail in [user-stories.md](user-stories.md). The load-bearing rules:

### C1 — A week builds itself from the week before

**The rule.** Opening a week that does not exist copies the newest earlier
week's *pattern* forward, weekday by weekday. Only the plan travels — never who
actually attended.

**Why.** Otherwise one sick day quietly becomes a child's new schedule.

### C2 — A box has four states

Scheduled · signed in · signed in but unplanned · not scheduled. A grey box
still accepts a sign-in: if a child turns up, DSS bills the attendance that
happened, not the attendance that was planned.

### C3 — A finished week freezes

**The rule.** Once its Friday has passed, a week's pattern can no longer be
edited. Sign-ins, closures, room moves dated into it — all refused.

**Why.** A finished week is a record, and DSS bills against it.

### C4 — A teacher sees their own room

**The rule.** Roster, sheet and child records are all filtered to the rooms the
teacher is assigned. Moving a child between rooms is director-only, because it
changes who can see them.

### C5 — Only today can be signed in

Backdating attendance is not a thing this screen does.

---

## D. Children

### D1 — The record is readable by whoever may see the child

Director, and the teacher whose room they are in. The photograph is on the
private disk and served through the app, so the same people who may open the
record are the only ones who may see the face.

### D2 — Room follows date of birth, until it is overridden

**The rule.** Age puts a child in a room. A director may override it from the
schedule, dated from the day the move actually happens. An override never looks
like the automatic value, and holds until cleared.

**Why.** A child ready for Transition at 17½ months goes there now — but that is
a decision somebody made, and it should read as one.

### D3 — Two ways in

**Import Children** takes a spreadsheet. **Import from document** takes an
enrolment form, extracts the fields, and hands them to a review screen before
anything is written. Both director-only.

---

## E. Holidays — set once, felt everywhere

Set at **Holidays** (director only). See [the walkthrough](#walkthrough) part 3.

### E1 — A closure is a fact about the centre, not a room

**The rule.** One row closes the day for every room at once. The board greys the
column, nobody can be ticked onto it, no staff shift is generated for it, and
leave is not charged against it.

### E2 — A holiday set in March closes a September week that does not exist yet

**The rule.** Building a week reads the closures *before* it copies a pattern
forward, so a day closed in advance comes out closed whenever the week is
finally opened.

**Why.** This is the whole point of setting them ahead. A director enters the
year's holidays once, in one sitting.

### E3 — Closing a day is undoable

**The rule.** Closing records which ticks it cleared. Reopening puts back
exactly those — not a guess, and not an empty column. The dialog names the
children before the click, and marks any who cannot come back because they have
left or changed room since.

### E4 — Annual holidays are rules, not dates

**The rule.** Christmas is entered once as a rule and written out five years
ahead as ordinary closures. Four kinds are supported: a fixed date, the nth
weekday of a month (Labour Day), a weekday on or before a date (Victoria Day),
and an offset from Easter (Good Friday).

**Why.** Everything downstream reads a closure. Materialising the rule means the
board, the projection, the roster and leave need no idea annual holidays exist.

### E5 — A weekend holiday moves to the next working day

**The rule.** Christmas on a Saturday is kept on the Monday. If Boxing Day then
wants the same Monday, it takes the Tuesday.

**Why.** Skipping it would leave the centre with no Christmas closure at all
that year — silently wrong, in the direction nobody checks.

### E6 — A day reopened by hand stays reopened

**The rule.** Each rule remembers the last year it wrote. Re-running the seeder
or revisiting the page fills only the years after that, never revisits one.

### E7 — Editing tells rename from retime

**The rule.** Renaming a holiday moves no dates, so its days simply take the new
name and not a tick moves. Changing its date releases the old days — ticks
restored — and writes it out again. A moving holiday can only be renamed: Good
Friday is where Easter puts it.

### E8 — Past closures are left alone

Removing a rule reopens its future days and leaves the ones already past exactly
as they were recorded.

---

## F. Staff — rooms, rules and the roster

Full detail in [staff-walkthrough.md](staff-walkthrough.md).

### F1 — Room hours are standing facts

**Room Schedules** holds the hours each room runs and the children in it. Set on
one page because a room's hours change about never, and a create/edit/delete
cycle would be three screens for a table nobody adds to.

### F2 — A rule constrains a person

**The rule.** Scheduling rules are nested under the teacher they constrain.
Hard rules cannot be broken; soft ones bend and report. A rule that would
silently do nothing is refused at the form.

### F3 — The roster is generated, not typed

**The rule.** **Week Schedule** solves the week from the rules, the room demand,
approved leave and the closures. Only a director may generate it; every teacher
may read it.

**Why.** Knowing who else is on the floor at 3pm is the reason it exists.
Hiding it would send teachers back to asking.

### F4 — A closed day is left unstaffed

Nobody is rostered on a day the centre is shut, and a closure inside somebody's
holiday costs them nothing.

---

## G. My Schedule — a teacher's own week

### G1 — The next shift leads

**The rule.** The shift that has not finished yet is named at the top: today's
if it is still to come, otherwise the next one this week. It disappears once the
week is behind them.

**Why.** This screen is opened in a corridor on a phone to answer one question —
*when am I on?* A banner pointing at Monday on a Friday afternoon is worse than
no banner.

### G2 — Hours are shown against what is owed

The week's total, the contracted target, and a bar. A short week reads as a
short week rather than as a number with nothing to compare it to.

### G3 — Each shift is drawn to scale

A bar per shift positioned inside the operating day, so an early and a late are
two different pictures rather than two similar lines to compare.

### G4 — A closed day says why

**The rule.** The roster is never generated for a closed day, so the card names
the closure instead of reading "Not scheduled."

**Why.** A holiday and a day you were simply not needed are not the same news.

### G5 — Approved leave is said before the shifts

A day off you booked and a day nobody rostered you for look identical from an
empty card, and only one of them is yours.

### G6 — Rostered is not paid

Stated on the page: these are the hours you are rostered for. What you are paid
comes from the time clock, and if the two disagree the clock wins.

---

## H. Leave

Full detail in [leave-user-stories.md](leave-user-stories.md) and
[leave.md](leave.md).

### H1 — Asking and deciding are different screens

**The rule.** **My Leave** is every role's own balances and requests — directors
hold leave like anybody else. **Leave Requests** is where a director approves,
denies or revokes. Nobody signs off their own.

### H2 — Weekends and closures are not charged for

A closure inside a booked week costs the teacher nothing.

### H3 — Balances move by hand only with a reason

Director-only, recorded as a ledger entry rather than an edited number.

---

## I. Time clock and pay

Full detail in [payroll-prep.md](payroll-prep.md).

### I1 — You punch as yourself

**The rule.** **Time Clock** shows your own punches and your own hours. No rate,
no colleague, no correction.

**Why.** A punch somebody can quietly amend is not a record of anything.

### I2 — Corrections are a supervisor's act

Amending a punch happens under **Payroll Prep**, against the period and the
person, never from the employee's own screen.

### I3 — An approved period is frozen

**Payroll Prep** seeds a period from the clock, allows correction, then
approves. The CSV export is the deliverable. Reopening is deliberate and
recorded.

### I4 — Payroll is every employee's pay in one file

Director-only, never on the teacher-visible side. Slips are split from an
upload, matched to people, previewed, then sent. The PDF does not outlive the
record.

---

## J. Reports

### J1 — The week, printed the way the centre's sheet reads

Rooms in the centre's own order, School Age split AM/PM, the combined subtotals
under the rooms they belong to. A teacher's report covers their rooms; a
director's covers all.

### J2 — Print drops the chrome

Sidebar, navbar and controls disappear on print; the sheet lands landscape.

---

## K. Look and feel

### K1 — Two themes, two palettes

**The rule.** The toggle in the navbar sets `.dark` on `<html>` and remembers
it. Light is the Little Angels brand blue. Dark is a purple ramp — black page,
`#150050` cards, `#3F0071` inputs, `#610094` accents — applied by re-pointing
the slate scale inside `.dark` rather than by rewriting every view.

**Why.** The app already says `dark:bg-slate-900` in a hundred and fifty places.
One variable block repaints all of them.

### K2 — The logo carries the header

Expanded, the sidebar shows the centre's logo, centred, inverted to a white mark
in dark mode. Collapsed, the rail is eighty pixels and holds the toggle alone —
a mark beside it overflowed rather than fitted.

---


# Part two — walkthrough

Run these in order against a freshly seeded database. Each step says what to do
and what you should see, so a wrong result is obvious rather than a matter of
taste.

## Part 1 — as a teacher

Log in as `toddler.teacher@daycare.test`.

**1. The dashboard is scoped to you.**
Counts cover the Toddler room, not the centre. **Upcoming closures** lists the
next four closed days; there is no *Manage holidays* link.

**2. Sign the room in.**
**Class Attendance** → today's column. Click a child's box to sign them in; the
time stamps. Only your room is listed.

**3. A child turns up unplanned.**
Sign in a child whose box is grey. It is accepted and marked as unplanned —
amber, not green. The attendance that happened is what gets billed.

**4. Try yesterday.** There is nothing to click. Sign-ins are for today.

**5. Read your own week.**
**My Schedule**. The next shift leads the page in its room's colour; each day
card carries a bar showing where the shift sits in the operating day. A closed
day names the closure rather than reading "Not scheduled."

**6. Look at the whole floor.**
*Everyone's week* → **Week Schedule**. You can read it. There is no
*Generate* button.

**7. Punch in.**
**Time Clock** → *Clock in*. Your own punches only. Take a break and come back;
the first minutes are paid and the message says so.

**8. Book a day off.**
**My Leave** → request a day. It sits pending — you cannot approve it.

Log out.

## Part 2 — as the director

Log in as `admin@daycare.test`.

**9. The sidebar is longer.**
Teachers, Room Schedules, Holidays, Leave Requests, Payroll Prep, Payroll,
Import Children — all new, all `role:admin` on the server too.

**10. Decide the leave.**
**Leave Requests** → the teacher's request from step 8, with a badge in the
sidebar counting it. Approve it. Open **Week Schedule** for that week and
regenerate: they are no longer rostered that day, and their target for the week
has dropped by the hours booked.

**11. Move a child up a room.**
On the attendance sheet, set a child's classroom and date it from today. The
override shows as an override, never as the automatic value. If the child moves
in or out of School Age, their boxes reshape from one full day into AM and PM.

## Part 3 — holidays, and what they touch

**12. Close one day.**
**Holidays** → *Close a day* → pick a weekday later **this** week, reason "Staff
training". The message says how many scheduled days were cleared.

**13. See it land.**
**Class Attendance**: the column is grey and named. Try to tick a child onto it
— nothing happens. **My Schedule** for a teacher shows that card reading
*Centre closed*.

**14. Undo it.**
Back on **Holidays** → *Reopen*. The dialog names the children who come back,
and strikes through any who cannot. Confirm; the ticks return to the board.

**15. Close a whole break.**
*Close a day* with a **last day** a week later. Weekends are skipped, and the
message reports the range and the count.

**16. Add one that repeats.**
*Every year* → 25 December, "Christmas Day". It writes five years ahead at once.
The list shows it with the date it next falls on.

**17. Prove it reaches a week that does not exist.**
Navigate the attendance board forward to Christmas week of a future year. It is
not built, so the sheet offers **Open week** rather than filling itself in —
only the week you are standing in does that. Press it: the week builds from the
newest week before it, and the 25th comes out closed even though nobody had
opened that week when the holiday was set.

**18. Watch a weekend holiday move.**
In the list, find a year where Christmas falls on a Saturday. The closure is on
the following Monday, and Boxing Day on the Tuesday behind it.

**19. Rename versus retime.**
Edit the rule and change only the name: its days take the new name and no tick
moves. Change its date instead: the old days reopen — ticks restored — and the
new date closes.

**20. Seed the statutory calendar.**

```bash
php artisan db:seed --class=CanadianHolidaySeeder
```

Ten federal holidays appear. Set `DAYCARE_PROVINCE=ON` in `.env` and run it
again: Family Day and the August civic holiday join them, and nothing is
duplicated.

## Part 4 — the week, the roster and payday

**21. Open next week and watch it copy.**
**Class Attendance** → *Next week* → **Open week**. Building a week is a
decision, so merely looking at one never creates it. It copies the newest week
before it, weekday by weekday: the pattern travelled, the sign-ins did not.

**22. Rebuild from a better week.**
*Copy from another week* → pick a source, choose **Replace** or **Add**. A copy
that changes nothing says so outright rather than reporting a success the grid
does not show.

**23. Last week is closed.**
Navigate back. The checklist and every copy control are gone. It is a record now.

**24. Give a teacher a rule.**
**Teachers** → open one → add a `FIXED_SHIFT`. The form only offers the fields
that rule type uses.

**25. Generate the roster.**
**Week Schedule** → *Generate*. Read the chart: hard rules are honoured, soft
ones bend and are reported, gaps are warned about once each, and closed days are
left unstaffed.

**26. Prepare the pay.**
**Payroll Prep** → seed the period from the clock. Correct a punch against the
person and the day. Approve; the period freezes. Export the CSV — that is the
deliverable.

**27. Send the slips.**
**Payroll** → upload, split, match to people, preview, send one. Sends that
would go to the wrong person are refused.

**28. Print the week.**
**Reports** → pick a date → print. Sidebar and navbar drop away; rooms print in
the centre's own order with School Age split AM/PM.

## Part 5 — chrome

**29. Flip the theme.**
The navbar toggle. The page goes black, cards deep purple, the sidebar `#150050`
with its active link on `#610094`, and the logo inverts to a white mark. Reload
— the choice is remembered.

**30. Collapse the sidebar.**
The hamburger. The rail narrows to icons and the toggle centres in it; the logo
steps aside rather than being squeezed against it.

---


# Part three — copying a week

*How a week gets filled in, and the one rule the whole design turns on.*

---

## The rule

**A schedule is a plan somebody made. It is never a number a formula worked out.**

Everything below follows from that one sentence. A week is filled from a week
that actually happened, or by hand, box by box. The forecast on the page — the
sky-blue **Projected** strip, the **Projected** column, the sky rings — is read
beside the plan and has no path into it. There is no button that turns a
prediction into ticks, because a schedule that can rewrite itself from last
week's absences is not a schedule the centre agreed to.

So a child who was off sick on Tuesday is still scheduled for Tuesday. The
forecast will say the two disagree. That disagreement is the point: it is
information for the director, not an instruction to the system.

---

## Where it lives

**Class Attendance → Copy from another week**

The button is in the indigo strip under the week header. It appears only when
all three are true:

| Condition | Why |
|---|---|
| You are an **admin or teacher** | Parents read the sheet; they do not plan it. |
| The week **has ended** — no | A finished week is a record. DSS bills against it. |
| **Another week exists** to copy from | Nothing to offer otherwise. |

---

## What the dialog asks

### 1. Which week to copy from

Every other week in the system, up to twelve. Earlier weeks first, newest at the
top; later weeks after them, nearest first, for the occasional copy backwards.

Each one shows **how busy it was**:

```
Aug 10 – Aug 14, 2026            [ LAST WEEK ]
54 days ticked

Aug 3 – Aug 7, 2026
31 days ticked · 1 closed day
```

That line is there so a holiday week is obvious without opening it. "Copy from a
normal week" means being able to spot one, and a week thinned out by closures
reads as a low count and a red flag.

**Last week is pre-selected.** Copy almost always means "same as last week", so
it carries a badge and comes first.

Any week may be a **source**, including one that has ended — reading a finished
week does not change it.

### 2. Add, or Replace

This is the only genuinely reversible-or-not choice in the dialog, so it is worth
being sure.

| | What it does to a day ticked **here but not there** |
|---|---|
| **Add** *(default)* | **Keeps it.** Only ever turns days on. |
| **Replace** | **Clears it.** This week ends up an exact match of that week. |

Add is the default because it cannot lose work somebody did by hand. Replace is
the one to reach for when this week has drifted and you want it straightened out.

> **The confusing case, named up front:** if this week already holds every day
> the source has *plus* a few extra, **Add does nothing at all** — there is
> nothing left to turn on. That looks exactly like a broken button. The banner
> catches it and says so: *"It also has 3 day(s) that week does not — choose
> 'Replace this week' to match it exactly."*

### 3. Also copy the sign-ins — admin only

**Off by default, and it should usually stay off.**

Ticked, it reproduces the source week's arrivals on the matching weekdays, at the
same times. That means **writing attendance records for days nobody was actually
signed in** — inventing a record of children being present.

It exists to build sample data for a demo, and for nothing else. The checkbox is
amber, says so in as many words, and a teacher never sees it.

Two things it will not do, even when ticked:

- **Overwrite a real arrival time.** A day already signed in is left exactly as
  it is.
- **Sign in a child who was not enrolled** on the matching day.

---

## What travels, and what never does

| | Copies forward? |
|---|---|
| Which days are ticked | **Yes** — that is the whole job |
| AM / PM split for School Age | **Yes**, each session independently |
| Who actually attended | **No** — unless an admin explicitly ticks the box |
| Arrival times already recorded here | **Never overwritten** |
| Closed days | **Never inherit ticks** — a closure is a fact about *this* date and wins over the pattern |
| Children outside their enrolment dates | **No box at all** — that is the "—" state, not "unscheduled" |

Once a copy is done, **the two weeks are independent**. Editing the source later
does not reach forward.

---

## What it tells you afterwards

The banner names what moved, because a copy that did nothing looks identical to
one that failed.

| Outcome | Banner |
|---|---|
| Added days | ✓ *"Copied from Aug 10 – Aug 14. 12 day(s) added — 54 now ticked this week."* |
| Replaced | ✓ *"Copied from Aug 10 – Aug 14. This week now matches it — 54 day(s) ticked."* |
| Sign-ins came too | ✓ …*"44 sign-in(s) came across too."* |
| Source week is empty | ! *"Nothing to copy — the week of Aug 10 – Aug 14 has no days ticked. Set that week up first, or pick another one."* |
| Already identical | ! *"Nothing changed — this week already has every day the week of … does."* |
| Add hit the extras case | ! …*"choose 'Replace this week' to match it exactly."* |
| Target week has ended | ! *"That week has ended and can no longer be edited."* |

---

## The other two ways a week gets filled

Copying is one of three, and they are all deliberate acts:

1. **Opening a week.** The first time a week is opened it snapshots the pattern
   of the newest week before it. This is the automatic one — but it happens only
   once, per week, when someone presses **Open this week**. Merely looking at a
   future week does not build it.
2. **Copy from another week.** This dialog. Any source, any time, Add or Replace.
3. **By hand.** **Set schedule** → tick boxes, drag across a row, use a preset,
   or click a day header to set a whole column.

None of them is the forecast.

---

## In the code

| Piece | Where |
|---|---|
| The dialog | [`resources/views/attendance/index.blade.php`](../resources/views/attendance/index.blade.php) — `x-ref="copyWeek"` |
| The request | `ScheduleController::copy()` — validation, permissions, the banner wording |
| The work | `WeekSchedule::copyFrom()` — the transaction, Add vs Replace, closures |
| Sign-ins | `WeekSchedule::copySignIns()` — admin-only path |
| Copy on first open | `WeekSchedule::open()` → `fill()` |
| The forecast (read-only) | `AttendanceProjection::forWeek()` — computed on every load, stored nowhere, writes nothing |

**Tests** — `ScheduleEditingTest`:
`copying_a_week_from_the_page_redirects_back_with_the_new_pattern`,
`adding_a_week_keeps_the_days_already_ticked_here`,
`replacing_a_week_clears_days_the_source_does_not_have`,
`a_no_op_add_points_at_replace_when_this_week_holds_extra_days`,
`copying_with_sign_ins_reproduces_them_on_the_matching_weekday`,
`copying_leaves_sign_ins_alone_unless_asked`,
`a_teacher_cannot_copy_sign_ins`,
`a_finished_week_cannot_be_copied_into`,
`a_holiday_stays_gray_when_the_pattern_copies_forward`.

For the forecast having no way in:
`AttendanceProjectionTest::the_week_view_shows_the_forecast_but_offers_no_way_to_apply_it`.

---

## History

**Fill from projection** used to sit in this dialog — one button that ticked the
week from the forecast. It was removed. A prediction built from last week's
attendance now has no route into the schedule at all, which makes the boundary a
property of the system rather than a habit people have to keep.

---

# Part four — leave, in detail

Sick and vacation time: earning it, asking for it, deciding on it, and making an
approved absence show up on the roster and the timesheet. Each story carries the
acceptance criteria it is judged by and the test that holds it up, so "done" is
a thing you can check rather than a thing you are told.

Status is one of **Built** (working and covered by a test) or **Open**. Every
story below is Built; the gaps are named at the end.

---

## A. Earning it

### A1 — Hourly staff earn on the hours they actually worked

> **As** a teacher paid by the hour
> **I want** leave to build up from the shifts I actually worked
> **so that** a fortnight of long days is worth more than a fortnight of short ones.

- One hour of leave per *n* hours worked, per type, from
  `daycare.leave.accrual.per_hours_worked`.
- **Worked**, not paid: a day of PTO or a centre holiday earns nothing. Leave
  does not earn leave.
- Sixty hours worked earns 2.00h sick and 1.50h vacation under the shipped
  rates.

**Built** — `LeaveAccrualTest::hourly_staff_earn_against_the_hours_they_actually_worked`,
`::paid_leave_does_not_itself_earn_more_leave`

### A2 — Salaried staff earn a flat rate

> **As** a salaried lead
> **I want** the same accrual every period
> **so that** my balance does not jump about for reasons nobody can explain.

- Employment types listed in `daycare.leave.accrual.per_period` earn that flat
  figure whatever the clock says.
- The flat rate wins over the hourly one for those types.

**Built** — `LeaveAccrualTest::salaried_staff_earn_a_flat_rate_whatever_the_clock_says`

### A3 — Approving the pay period is what earns the leave

> **As** the director
> **I want** leave to be earned only from hours I have signed off
> **so that** nobody builds a balance on a shift a correction later removes.

- Accrual is posted when a pay period is approved on Payroll Prep.
- A **draft** period earns nothing, and says why rather than silently doing
  nothing.

**Built** — `LeaveAccrualTest::approving_a_pay_period_is_what_earns_the_leave`,
`::a_draft_period_earns_nothing_because_its_hours_can_still_change`

### A4 — Running accrual twice never pays it twice

> **As** the director
> **I want** to be able to press the button again without worrying
> **so that** reopening a period, or running the command from cron, cannot double anybody's balance.

- Posting is idempotent on (person, type, source, reference).
- The approve button, `php artisan leave:accrue` and the manual **Run accrual**
  button can all hit the same period; it is earned once.

**Built** — `LeaveAccrualTest::running_the_same_period_twice_earns_nothing_the_second_time`

### A5 — The cap holds, and names who hit it

> **As** the director
> **I want** accrual to stop at the cap and tell me
> **so that** three weeks of unused vacation is a fact I see rather than one the ledger absorbs.

- Accrual fills only the room left under `daycare.leave.cap`.
- The run reports the capped balance and how much was not earned.

**Built** — `LeaveAccrualTest::accrual_stops_at_the_cap_and_says_so`

### A6 — A balance can be opened, or corrected, by hand

> **As** the director
> **I want** to type somebody's starting balance in
> **so that** adopting this app does not mean everybody's paper card starts at zero.

- Adjustments post in either direction, positive or negative.
- The reason is **required** — unlike every other note in the app. A balance that
  moved by six hours for no recorded reason is what somebody argues about in
  November.

**Built** — `LeaveAccrualTest::a_director_can_open_a_balance_by_hand_but_must_say_why`,
`::an_adjustment_can_take_hours_away_again`

### A7 — Any balance can be taken apart

> **As** a teacher
> **I want** to see where every hour came from
> **so that** the number on my card is checkable rather than something I take on faith.

- A balance is the sum of a ledger, never a stored column.
- The leave page lists the movements behind it — earned, taken, restored,
  adjusted — newest first, each with its date, reason and signed hours.

**Built** — `LeaveAccrualTest::a_teacher_sees_their_own_balance_and_where_it_came_from`

---

## B. Asking for it

### B1 — A teacher asks for time off

> **As** a teacher
> **I want** to ask for days off from the same app I read my roster in
> **so that** it is not a note on the staffroom door that nobody can find in August.

- Type, first day, last day, hours per day, optional reason.
- The request lands as **pending** and appears on the director's queue.
- A range is one decision and one row — "the week of the 10th", not five rows.

**Built** — `LeaveRequestTest::a_teacher_asks_for_time_off_and_it_lands_as_pending`

### B2 — Weekends and closed days cost nothing

> **As** a teacher
> **I want** a request over a long weekend to charge me the working days only
> **so that** I am not spending vacation on a Sunday I was never rostered for.

- The days a request costs are the centre's operating days, less any closure day.
- The days are recomputed from the range each time, so a snow day declared
  *after* the request was filed stops consuming leave with nobody editing
  anything.
- A range that is nothing but weekends is refused with a reason rather than saved
  as a request for zero hours.

**Built** — `LeaveRequestTest::weekends_and_closure_days_are_not_charged_for`,
`::a_range_of_nothing_but_weekends_is_refused`

### B3 — Vacation is asked for before, sickness after

> **As** the director
> **I want** the two kinds of leave to be filed the way they actually happen
> **so that** a sick day the morning after is normal and a backdated holiday is not.

- Vacation may not start in the past.
- Sick leave may be backdated up to `daycare.leave.backdate_days.SICK` days.
- Anything older is refused and pointed at a payroll correction instead.

**Built** — `LeaveRequestTest::vacation_cannot_be_asked_for_after_the_fact`,
`::a_sick_day_can_be_filed_the_morning_after`

### B4 — One day, one request

> **As** the director
> **I want** overlapping requests refused at the form
> **so that** two approvals over one Tuesday cannot spend the balance for it twice.

- A live request (pending or approved) blocks another over the same days.
- The clash is named — dates and status — so it is obvious which one to withdraw.

**Built** — `LeaveRequestTest::two_requests_cannot_cover_the_same_day`

### B5 — You can ask for leave you have not earned yet

> **As** a teacher
> **I want** to ask in March for a holiday in August
> **so that** planning does not depend on my balance being big enough today.

- The balance is **not** checked when asking. What it covers is settled at the
  decision, where a director can see it and say so.
- The form shows the balance and the hours already pending next to it, so the ask
  is informed without being blocked.

**Built** — `LeaveRequestTest::a_request_the_balance_cannot_cover_is_held_until_the_shortfall_is_accepted`

### B6 — Withdrawing your own request

> **As** a teacher
> **I want** to take back a request nobody has decided on
> **so that** a change of plan is not a conversation.

- A **pending** request can be withdrawn by the person who made it.
- An approved one cannot — that is the director's revoke.
- Somebody else's request cannot be touched at all: 403.

**Built** — `LeaveRequestTest::a_pending_request_can_be_withdrawn_but_somebody_elses_cannot`

---

## C. Deciding on it

### C1 — Approving spends the balance

> **As** the director
> **I want** an approval to come off the card immediately
> **so that** the balance is never a promise the next approval does not know about.

- The paid hours are posted to the ledger the moment the decision is made.
- The request records what was paid and what was not.

**Built** — `LeaveRequestTest::approving_takes_the_hours_off_the_balance`

### C2 — A shortfall is approved as unpaid, on purpose

> **As** the director
> **I want** to be told when a request outruns the balance and to decide anyway
> **so that** an unpaid day is a decision on the record rather than a surprise on a payslip.

- Approving is refused first time with the arithmetic spelled out: what is held,
  what is asked, what would be paid and what would not.
- Ticking **approve the shortfall as unpaid** grants it: the days the balance
  stretches to are paid, in date order; the rest is an approved *unpaid* absence.
- Whole days only. A card covering three and a half days pays three and leaves
  the fourth unpaid; the half-day stays on the balance.

**Built** — `LeaveRequestTest::a_request_the_balance_cannot_cover_is_held_until_the_shortfall_is_accepted`

### C3 — Nobody approves their own leave

> **As** a centre
> **I want** self-approval refused
> **so that** a director asking for time off is asking somebody, like everybody else.

- A director may file a request; another director decides it.

**Built** — `LeaveRequestTest::nobody_approves_their_own_leave`

### C4 — A denial keeps the record and the reason

> **As** a teacher
> **I want** to see what was decided and why
> **so that** "I asked in April and was turned down" is answerable months later.

- Nothing is deleted. Denied and cancelled requests stay on the teacher's page.
- The decision note, the decider and the date are shown to the person who asked.

**Built** — `LeaveRequestTest::denying_keeps_the_request_and_the_reason`

### C5 — A decision is made once

> **As** the director
> **I want** a decided request to stay decided
> **so that** a double-click cannot spend the balance twice.

- Approve and deny both refuse on anything that is not pending.
- One ledger entry per approved request, whatever the browser does.

**Built** — `LeaveRequestTest::a_decided_request_cannot_be_decided_again`

### C6 — Revoking gives the hours back

> **As** the director
> **I want** to take back an approval
> **so that** a cancelled holiday does not cost somebody a week of vacation.

- The paid hours return to the balance as a **restored** ledger entry.
- The days come off the timesheet, in every period still open.
- The shifts are **not** restored, and the message says so — regenerating the
  week is the honest way to put them back.

**Built** — `LeaveRequestTest::revoking_an_approval_puts_the_hours_back`,
`LeaveTimesheetTest::revoking_takes_the_days_back_off_the_timesheet`

### C7 — The queue shows the ask against the balance

> **As** the director
> **I want** the balance next to the request
> **so that** deciding does not mean opening a second screen.

- Each row carries hours asked, days, hours held and the shortfall if there is one.
- Waiting requests sort to the top; the tabs filter by status.

**Built** — `LeaveRequestTest::the_queue_shows_what_is_asked_for_against_what_is_held`

---

## D. What it does to the roster

### D1 — Nobody is rostered on a day they were granted off

> **As** a teacher
> **I want** my approved day off to be a day the roster knows about
> **so that** a room is never planned around somebody who will not arrive.

- The generator places no shift for that person on that date.
- They are not available to cover another room that day either — cover is still
  a shift.

**Built** — `LeaveScheduleTest::nobody_is_rostered_on_a_day_they_were_granted_off`,
`::somebody_on_leave_is_not_pulled_in_to_cover_another_room_either`

### D2 — The week's target drops rather than the days getting longer

> **As** a teacher
> **I want** my remaining days to be normal days
> **so that** booking Wednesday off does not turn the rest of the week into ten-hour shifts.

- The weekly target falls by the approved leave hours: thirty-two, not forty.
- Those hours spread over the days that are actually workable — four eight-hour
  days, not five six-and-a-half-hour ones.

**Built** — `LeaveScheduleTest::a_week_with_leave_in_it_lowers_the_target_rather_than_squeezing_the_hours_in`

### D3 — The week says who is away

> **As** the director
> **I want** the absence named in the week's warnings
> **so that** a ratio gap on Wednesday reads as a shift to cover rather than a bug.

- One line per person: which days, and how much their target fell by.

**Built** — `LeaveScheduleTest::the_week_says_who_is_away_and_why_the_hours_are_lower`

### D4 — Leave granted after the week was published still reaches it

> **As** the director
> **I want** an approval to reach a roster I have already published
> **so that** the chart is not still showing somebody in the Toddler room on their day off.

- That person's shifts on those dates are removed.
- The week keeps a warning naming what was pulled and asking to be regenerated —
  a silently removed teacher would turn a visible shortfall into an invisible one.
- **Colleagues' shifts are untouched.** Re-solving the whole week would rewrite
  everybody's published hours over one person's day off, and people have already
  arranged childcare around them.

**Built** — `LeaveScheduleTest::approving_leave_takes_the_shifts_off_a_published_week`,
`::the_published_week_records_that_shifts_were_pulled_and_asks_to_be_rebuilt`,
`::a_colleagues_shifts_are_left_alone`

### D5 — Leave over a closed day costs nothing, and is not a gap

> **As** a teacher
> **I want** the centre's day off not to be mine
> **so that** a closure inside my holiday does not come off my balance.

- Closure days and weekends never appear in a request's working dates, so they
  neither cost hours nor produce an "on leave" warning on the roster.

**Built** — `LeaveScheduleTest::leave_over_a_closed_day_costs_nothing_and_is_not_reported_as_a_gap`

### D6 — Both roster screens show the absence rather than a blank

> **As** a teacher
> **I want** my booked day to look booked
> **so that** it cannot be mistaken for a day nobody got round to rostering me for.

- **My Schedule** shows a leave card on the day, naming the type and the hours.
- A week that is *nothing but* leave still renders — it does not fall back to
  "not scheduled for any shift this week".
- The full roster draws a marked band across that person's day, and the legend
  says what it means.

**Built** — `LeaveScheduleTest::the_teachers_own_week_shows_the_day_as_booked_off`,
`::a_week_that_is_nothing_but_leave_still_shows_the_leave`,
`::the_full_roster_marks_the_absence_rather_than_leaving_a_blank`

---

## E. What payroll receives

### E1 — The day arrives under a code payroll knows

> **As** the director
> **I want** approved leave on the timesheet automatically
> **so that** a vacation week is not five blank days paying nothing.

- `VACATION → PTO`, `SICK → SICK`, from `daycare.leave.timesheet_codes`.
- The day carries the leave minutes and no worked minutes.
- It arrives **confirmed**, attributed to the director who approved it — they
  already said what happened, and retyping it would be the rubber stamp the
  confirm step exists to avoid.

**Built** — `LeaveTimesheetTest::approving_puts_the_days_on_the_timesheet_under_the_payroll_code`,
`::a_sick_day_arrives_as_sick_rather_than_as_pto`

### E2 — Half a day is paid as half a day

> **As** a teacher
> **I want** a four-hour request to pay four hours
> **so that** a half day is one request rather than a rounding argument.

**Built** — `LeaveTimesheetTest::half_days_are_paid_at_half_a_day`

### E3 — The unpaid days arrive as unpaid

> **As** payroll
> **I want** the shortfall to reach me as an unpaid absence
> **so that** an approved day off the books is still on the books.

- Days beyond the balance are written under `UNPAID`.
- The period summary separates them: paid leave hours and unpaid leave hours are
  different columns, and only the first is paid.

**Built** — `LeaveTimesheetTest::the_days_the_balance_could_not_cover_arrive_as_unpaid`

### E4 — Seeding a period brings leave in with the roster

> **As** the director
> **I want** leave approved before the timesheet existed to appear when I seed it
> **so that** the order I did things in does not decide whether somebody gets paid.

**Built** — `LeaveTimesheetTest::seeding_a_period_brings_approved_leave_in_alongside_the_roster`

### E5 — Two things are never written over

> **As** the director
> **I want** leave to stop at somebody's own account of a day
> **so that** an approval cannot quietly overwrite a fact.

- **A punched or corrected day wins.** If a teacher clocked in on a day later
  granted as sick leave, the day is left alone and named in a warning for a human
  to reconcile.
- **An approved pay period is closed.** The leave still stands and still costs
  the balance; it simply cannot reach hours payroll has already paid on.

**Built** — `LeaveTimesheetTest::a_day_somebody_has_already_spoken_for_is_not_written_over`,
`::an_approved_pay_period_is_never_touched`

---

## F. Access

### F1 — Deciding is the director's, and only theirs

> **As** the centre
> **I want** the queue and the balances to be director-only
> **so that** nobody approves their own days off through a URL.

- A teacher gets a 403 on the queue, the balances page and every decision route.
- Those links are not in their sidebar at all.

**Built** — `LeaveRequestTest::a_teacher_cannot_reach_the_queue_or_decide_anything`,
`LeaveAccrualTest::the_balances_page_shows_every_staff_member`

### F2 — Nothing is readable signed out

**Built** — `LeaveRequestTest::signed_out_visitors_are_sent_to_the_login`

---

## G. The surfaces around it

### G1 — Approving a period prints what everybody earned

> **As** the director
> **I want** the accrual run to show its working on the page I was already on
> **so that** hours appearing on somebody's card is something I watched happen.

- The run's lines come back on the redirect and render above the grid, one per
  person per type, with the hours worked they were earned on.
- A link to every balance sits under them.

**Built** — `LeaveAccrualTest::approving_prints_what_everybody_earned`

### G2 — The sidebar counts what is waiting

> **As** the director
> **I want** the number of undecided requests in front of me
> **so that** somebody's Friday off is not sitting unanswered because nobody opened the page.

- A badge on **Leave Requests** carries the pending count, and disappears at
  zero rather than showing a nought.
- Teachers get **My Leave** and no queue link at all.

**Built** — `LayoutChromeTest::the_sidebar_counts_the_leave_requests_waiting_on_a_director`

### G3 — Accrual from the command line

> **As** whoever runs the server
> **I want** `leave:accrue` to be safe to put on a cron
> **so that** a centre that closes payroll on a schedule does not have to remember a button.

- `php artisan leave:accrue` takes the period that has **just ended**, not the
  one in progress — accruing the current period would pay for days not yet
  worked.
- A date argument picks any period.
- A period with no timesheet, or one still in draft, is reported and left alone.

**Built** — `LeaveAccrualTest::the_command_accrues_a_named_period`,
`::the_command_defaults_to_the_period_that_has_just_ended`,
`::the_command_says_so_when_the_period_has_no_timesheet`,
`::the_command_refuses_a_period_that_is_still_a_draft`

---

## Still open

| # | Story | Note |
|---|---|---|
| H1 | A teacher is told when their request is decided | The decision shows on their leave page next time they look. There is no email, and the mail plumbing already exists for the welcome email. |
| H2 | Year-end carry-over | Balances run continuously and stop at the cap. Nothing resets, expires or carries a limited number of hours into a new year. |
| H3 | A who's-off calendar | The roster shows absence a week at a time. There is no month view for planning, and no way to see "three people already want that week" before approving. |
| H4 | Blackout dates and concurrent-absence limits | Every request is judged on its own. Nothing stops a whole room being granted the same week. |
| H5 | Centre holidays as leave for everybody | `HOLIDAY` exists as a timesheet code but has to be entered per person. Declaring a paid holiday once, for all staff, is not built. |
| H6 | Leave on the payroll export | Paid and unpaid leave hours are already columns in the timesheet export. A per-request breakdown, or a balance statement to hand somebody, is not. |
| H7 | Part-day leave inside a worked day | A day is leave or work, not both. Leaving at noon on approved sick time has to be recorded as a timesheet correction. |

---

# Part five — payroll preparation

*Turning the time clock, the roster, and the corrections made to both into a
per-period hours summary ready to hand to payroll.*

---

## The rule

**The roster is what was meant to happen. The clock is what was recorded. The
timesheet is what is paid. Nothing crosses between them on its own.**

There are two ways a day gets onto the timesheet, and they are ranked.

**The clock beats the roster.** A teacher who punches in and out has given
their own account of the day, and it replaces whatever the roster guessed.

**A person beats both.** Somebody who opens the day, reads it and types what
happened overrides the clock and the roster alike — and their name goes next to
it.

Seeding copies the published roster in as a *draft* so nobody types a fortnight
of times from scratch, and every copied day is marked as the roster's word until
a person or a punch says otherwise. The page counts how many of those days are
still nobody's word, and refuses to approve the period while any remain.

That refusal is the feature. "We approved a fortnight of guesses" is the failure
this exists to prevent, and the only thing standing between a centre and it is a
count that will not go to zero by itself.

Correcting a Tuesday here never reaches back and edits the roster that was
published, either. The record of what the centre planned stays a record of what
it planned, not of what it wishes it had.

---

## Where it lives

**Payroll Prep** in the sidebar → `/timesheets`

Director only, the same as Payroll itself. A teacher who reaches the URL gets a
403; the link is not in their sidebar at all. This one screen holds every
employee's hours and pay rate.

**Time Clock** in the sidebar → `/time-clock`

Every teacher, and only ever as themselves. Four or five buttons, their own
punches, their own hours. No rate, no colleague, and no way to change anything
already recorded.

---

## The pay period

**Semi-monthly** — the 1st to the 15th, and the 16th to the last day of the
month. Twenty-four a year. Set in `config/daycare.php` under `timesheet.period`.

A semi-monthly period **never lines up with a week**, and that one fact drives
most of the design. A period boundary falls mid-week eleven times out of twelve,
so a week's work routinely belongs to two periods at once — see
[Overtime](#overtime-belongs-to-the-week-pay-belongs-to-the-period) below.

The period is arithmetic on a date rather than a record, so there is no version
of "the first half of August" that could be wrong and nothing to keep in step.

---

## The time clock

Teachers punch **in**, **out**, and either side of **lunch** and a **break**.
Each punch is a row that is written once and never edited, and each day is
rolled up into the timesheet entry above the moment anything about it changes.

Turn it off with `timesheet.clock.enabled` and the centre is back to correcting
the roster by hand, which is still a reasonable way to run a small site. The
punches already recorded are kept and still shown.

### Lunch is unpaid, a break is paid

That is the only reason they are separate buttons.

The FLSA counts a short rest break as hours worked and a genuine meal period as
not. One "away" button would either pay for lunch or dock the tea break, and
both are payroll errors rather than rounding ones.

A break is paid for its first `clock.paid_break_cap` minutes — twenty by
default — and unpaid beyond them, because at forty minutes it has stopped being
a rest break. Lunch is unpaid however short it is.

| Punched | Paid |
|---|---|
| 07:00 in, 15:30 out | 8.50 |
| …with a 30-minute lunch | 8.00 |
| …and a 15-minute break as well | **8.00** — the break is worked time |
| …with a 45-minute "break" instead | 7.58 — 20 paid, 25 not |

Clock out and back in later and the gap is unpaid, so a split shift is one day
with the middle taken out — the same shape a rostered split shift arrives in.

### The clock will not let you punch something impossible

The buttons offered are the only ones legal from where somebody stands: you
cannot start lunch before clocking in, or clock out from the middle of one. The
ordinary day is therefore correct by construction, and the exception queue stays
as short as the mistakes people actually make.

The time recorded is the minute the button was pressed. **Nothing is rounded**
— see [what this does not do](#things-this-deliberately-does-not-do).

### A day that does not add up is never guessed at

Somebody who forgot to clock out is worth **zero hours**, not hours-until-
midnight and not hours-until-their-rostered-end. Inventing hours out of a button
nobody pressed is worse than reporting a gap.

Such a day is raised as an exception: red **!** on the grid, and the period
**cannot be approved** while one stands. The exceptions are

| Flagged | Meaning |
|---|---|
| never clocked out | Still on the clock on a day that has ended |
| clocked in again without clocking out | Two ins in a row |
| clocked out while not on the clock | An out with no in before it |
| back from lunch without going on it | And the same for a break |
| longer than `max_day_hours` | Fourteen by default — a flag, not a cap. The hours stand; somebody just has to look |

Still being clocked in **today** is not an exception. It is three in the
afternoon.

---

## Correcting the clock

A supervisor's job, never the employee's own. A clock somebody can quietly amend
is not a record of anything.

Open a punched day from the **Clock** column on the day form, or by clicking the
red **!** on the grid → `/timesheets/{period}/staff/{user}/day/{date}`.

### Nothing is ever edited or deleted

Putting a punch right is **a void plus a replacement**. The original stays on
the day, struck through, with who voided it and why; the replacement points back
at what it replaced.

A punch that had simply been updated in place would be indistinguishable from
one nobody ever questioned, and the entire value of a time clock is that its
record can be shown to somebody who was not there.

Three things can be done, and all three want a **reason** — required, not
encouraged, because the question this answers is asked months later by somebody
who was not in the building:

| Act | What it writes |
|---|---|
| **Add a punch** | A new row, marked `supervisor`, with the reason. For the 5pm out somebody never pressed |
| **Move to this time** | Voids the original and writes a replacement pointing at it |
| **Void** | The original stops counting and never stops being visible |

Adding is deliberately **not** held to the state machine the employee's clock
enforces. By the time anybody notices the missing 5pm out, there are usually
punches on both sides of the gap.

### What the trail keeps

Every punch row carries, for good: the type and the minute, whether the employee
or a supervisor recorded it, who that was, the IP it came from, the reason if
there was one, and — once voided — who voided it, when, and why.

There is no separate audit table because there is nothing for one to hold. The
punch table itself cannot forget.

### The day is rebuilt, never adjusted

After any correction the day's hours are recomputed from the punches that still
count. Voiding a stray punch a fortnight later therefore produces exactly the
number the day would have had if the wrong button had never been pressed.
Nothing accumulates, and there is no drift to chase.

A supervisor correcting a punch is acting on that day deliberately, so the
rebuild **overrides** a day somebody had previously typed by hand — the one
place where the clock outranks a person, and only because a person put it there.

### What the teacher sees

Their own punches on `/time-clock`, today and the last fortnight, with a
correction shown as *moved from 7:00 pm by Director* and the reason next to it.
Somebody whose Tuesday was changed finds out there rather than on their payslip.

---

## The three acts

Each is deliberate, each says plainly what it will do, and they only make sense
in this order. A centre running the clock does far less of act 1 and act 2 —
punched days arrive on their own and confirm themselves — but the acts are the
same three either way.

### 1. Fill from the roster

Copies the published staff schedule into the period as a draft.

- **Only ever adds.** A day somebody has already confirmed is left exactly as
  they left it, so re-filling after a roster change brings in what is new
  without discarding an afternoon that was corrected by hand.
- **A split shift becomes one day with a break.** Rostered 9–12 and 1–5 arrives
  as `9:00–17:00`, break `60` — seven hours, which is both how it is worked and
  how it is paid.
- **Only days inside the period.** A shift on the 14th does not reach the period
  starting on the 16th.
- Every day it creates is marked `schedule`, and shows **amber** on the grid.

### 2. Confirm the days

Click a name to open that person's day-by-day form for the period: **in**,
**out**, **break**, **leave**, **leave hours**, **note** — one row per day,
weekends included, because staff work them. A **Clock** column shows what the
punches on that day came to, and links to them.

Saving is the act of somebody saying *this is what happened*. Every day on the
form becomes `manual`, stops being amber, and records who confirmed it and when.

**A punched day does not need confirming.** It is the employee's own account of
the day, not the guess this step exists to catch. Making a director retype a
fortnight of clean punches would turn confirming into exactly the rubber stamp
that [no "confirm all" button](#things-this-deliberately-does-not-do) refuses to
be.

When the **Worked** and **Clock** columns disagree, somebody has typed over the
day. That is allowed, and being able to see it is the point of showing both. To
change what a punched day is worth, correct the punches instead — that leaves a
trail, and typing over it does not.

Two things are refused rather than saved:

| Refused | Why |
|---|---|
| A day with an in time but no out (or the reverse) | Half a day is a slip, not an instruction. It names which half is missing. |
| A day that ends before it starts | Same. |

Everything else on the form saves, and the banner says which days were refused.
A day left completely blank stays blank — it does not become a confirmed nothing.

### 3. Approve

Freezes the period. From here the hours have gone to payroll and people are
being paid on them, so every input on every screen goes read-only.

Refused when:

- **The period has not finished.** Approving hours for days that have not
  happened is how a centre pays for a shift nobody worked.
- **Any day is still the roster's word.** It says how many.
- **Any day's punches do not add up.** It says how many of those too. A
  different refusal from the one above: that is *nobody has said yet*, this is
  *what was said cannot be true*. Approving over the top of one would send
  payroll a short week and call it finished.
- **There are no hours at all.**

**Reopen** exists for the correction that arrives too late. It says out loud
that payroll needs telling, because at that point they have the old numbers.

---

## Overtime belongs to the week, pay belongs to the period

Overtime is a property of the **workweek** (Monday–Sunday, matching the centre's
schedule). Pay is a property of the **period**. Semi-monthly periods cut weeks in
half, so the two have to be reconciled rather than assumed equal.

**How it is done:** hours are split into regular and overtime across the *whole*
workweek — including the days on the far side of the period boundary — and only
then collected into the period each day falls in.

Worked example. Somebody works Mon 10th to Fri 14th at 8 hours, then 6 hours on
Sunday the 16th:

| | Days | Worked | Regular | Overtime |
|---|---|---|---|---|
| Aug 1 – 15 | Mon 10 – Fri 14 | 40 | **40** | 0 |
| Aug 16 – 31 | Sun 16 | 6 | 0 | **6** |

The Sunday is entirely overtime — the forty were used up before it — and it is
paid by the period it falls in, not the one that earned it. Working the split out
*inside* each period instead would hand the first period 40 regular and the
second 6 regular, and the overtime would simply vanish.

**Paid leave is never hours worked.** Eight hours of PTO on Monday followed by
forty worked hours is 48 paid hours with **zero** overtime. The FLSA counts hours
worked, and a holiday does not make the rest of somebody's week overtime.

The threshold is `timesheet.overtime_after` in config, defaulting to 40.

---

## Leave

Four codes, in `config/daycare.php`:

| Code | Paid? | Meaning |
|---|---|---|
| `PTO` | yes | Paid time off |
| `SICK` | yes | Sick leave |
| `HOLIDAY` | yes | Centre holiday |
| `UNPAID` | **no** | Unpaid absence |

`UNPAID` exists so an absence can be recorded as a decision rather than left as a
blank nobody can tell apart from a day nobody has filled in yet. It carries hours
and is paid nothing — it appears in its own export column, never in the total.

A leave day with no length of its own is worth `default_leave_hours`, eight by
default. Half a day of PTO alongside half a day worked is fine: the two fields
are independent.

---

## Reading the grid

One row per employee, one column per day of the period, then the totals.

| On a day | Means |
|---|---|
| `8.00` in **bold** | Worked, and confirmed by a person |
| `8.00` **underlined** | Punched on the clock. Click it for the punches |
| `8.00` in **amber** | Worked, but still as the roster left it |
| `!` on **rose** | Punches that do not add up. Worth nothing, blocks approval, and links to where it gets sorted out |
| `PTO` / `SICK` / `HOL` on **sky** | Paid leave |
| `UNPA` on **gray** | Unpaid absence — recorded, worth nothing |
| `·` | Nothing on this day at all |

Somebody whose only days are broken ones has no payable hours at all, and is on
the grid anyway. They are precisely the person who must not be missed.

The right-hand columns are **Reg**, **OT**, **Leave**, **Paid**, **Gross**.
Overtime goes bold amber the moment it is not zero.

**"no rate"** in the Gross column means that employee has no `pay_rate` on their
record. Their hours are still correct — only the money is missing. It says *no
rate* rather than *$0.00* because zero is a different and much worse claim.

---

## What payroll receives

**Download CSV for payroll** — one row per employee with hours in it. People with
no hours in the period are left out entirely.

```
Employee,Legal name,Aspire ID,Employment,Period start,Period end,
Regular hours,Overtime hours,Paid leave hours,Unpaid leave hours,
Total paid hours,Pay rate,Estimated gross,Unconfirmed days,
Unresolved punch days
```

Regular and overtime are separate columns because they are paid at different
rates. Paid leave is its own again because it is neither.

**Estimated gross is a sanity check, not the payroll calculation.** It is
regular × rate, plus overtime × rate × 1.5, plus paid leave × rate. It knows
nothing about tax, deductions, benefits or salaried staff. Blank when no rate is
on file.

The **Unconfirmed days** and **Unresolved punch days** columns are deliberately
in the export. If a period was reopened, corrected and re-sent, the file itself
says how solid it is.

Somebody whose only days are broken punches has no payable hours and is in the
file anyway, at zero, with the last column saying why. Leaving them out would be
the file quietly agreeing they worked nothing.

---

## Trying it

```bash
php artisan db:seed --class=DemoScenarioSeeder   # children — the roster is sized against them
php artisan db:seed --class=StaffSeeder          # eight staff and their rules
php artisan db:seed --class=TimesheetDemoSeeder  # two pay periods
```

Builds two periods, both anchored to today so they are never stale:

- **The period just gone** — every day confirmed, approved, frozen. This is what
  finished looks like. Try the CSV, and try editing it.
- **The period running now** — half worked and deliberately messy: confirmed
  hours, amber days, all three kinds of leave, somebody over forty in a single
  week, and one person working the clock rather than being corrected onto the
  sheet. Their four days are one clean, one where a wrong clock-out was voided
  and replaced, one they walked out of without pressing anything, and one clean
  again. Approving it is refused twice over — for the amber days and for the
  red `!` — and both refusals are the point.

Click the `!` to reach the correction screen. `/time-clock`, signed in as a
teacher, is the same clock from the other side.

See [staff-walkthrough.md](staff-walkthrough.md) Part Four for the click-through,
and Part Five for the clock.

---

## In the code

| Piece | Where |
|---|---|
| Period arithmetic | `App\Services\PayPeriod` — semi-monthly boundaries, labels, the workweek of a date |
| Seeding, overtime, totals, export | `App\Services\Timesheet` |
| The clock: state machine, roll-up, exceptions | `App\Services\TimeClock` |
| One press of the clock | `App\Models\TimePunch` — written once, voided never deleted |
| The three acts | `App\Http\Controllers\TimesheetController` |
| Punching, as the employee | `App\Http\Controllers\TimeClockController` |
| Correcting, as the supervisor | `App\Http\Controllers\TimePunchController` |
| A period and its state | `App\Models\TimesheetPeriod` |
| One person, one day | `App\Models\TimesheetEntry` — worked and leave minutes held apart |
| The grid | `resources/views/timesheets/index.blade.php` |
| The day form | `resources/views/timesheets/edit.blade.php` |
| One day's punches and their history | `resources/views/timesheets/day.blade.php` |
| The clock itself | `resources/views/clock/index.blade.php` |
| Settings | `config/daycare.php` → `timesheet`, and `timesheet.clock` |

**Tests** — `TimesheetTest`:
`periods_run_from_the_first_to_the_fifteenth_and_the_sixteenth_to_month_end`,
`stepping_between_periods_crosses_the_year`,
`the_roster_fills_the_period_as_an_unconfirmed_draft`,
`a_split_shift_becomes_one_day_with_a_break`,
`re_filling_never_overwrites_a_day_somebody_confirmed`,
`the_roster_only_reaches_the_period_it_falls_in`,
`overtime_starts_after_forty_hours_in_a_week`,
`a_week_split_across_the_boundary_counts_overtime_across_the_whole_week`,
`paid_leave_does_not_create_overtime`,
`an_unpaid_absence_is_recorded_but_paid_nothing`,
`the_estimated_gross_pays_overtime_at_time_and_a_half`,
`a_missing_pay_rate_reports_no_estimate_rather_than_zero`,
`saving_a_day_confirms_it`,
`a_day_with_only_one_of_the_two_times_is_refused`,
`a_day_that_ends_before_it_starts_is_refused`,
`approving_is_refused_while_days_are_still_the_rosters_word`,
`approving_is_refused_before_the_period_has_finished`,
`a_confirmed_period_can_be_approved_and_is_then_frozen`,
`an_approved_period_can_be_reopened`,
`the_export_carries_the_hours_split_the_way_they_are_paid`,
`somebody_with_no_hours_is_left_out_of_the_export`,
`a_teacher_cannot_reach_payroll_preparation`.

**Tests** — `TimeClockTest`:
`a_teacher_punches_in_and_out_and_the_day_reaches_the_timesheet`,
`lunch_is_unpaid_and_a_short_break_is_paid`,
`a_break_that_overruns_is_paid_only_to_the_cap`,
`a_split_shift_on_the_clock_is_one_day_with_the_gap_as_break`,
`the_clock_offers_only_the_punches_legal_from_where_somebody_stands`,
`an_impossible_punch_is_refused_rather_than_recorded`,
`the_button_records_the_punch`,
`a_day_with_no_clock_out_is_worth_nothing_rather_than_a_guess`,
`being_clocked_in_today_is_not_an_exception`,
`clocking_in_twice_is_reported_rather_than_added_up`,
`an_impossibly_long_day_is_flagged_rather_than_paid_quietly`,
`a_day_that_does_not_add_up_stops_the_period_being_approved`,
`a_supervisor_fills_in_the_missing_punch_and_the_day_pays`,
`a_correction_voids_the_original_rather_than_editing_it`,
`voiding_a_punch_keeps_it_on_the_day`,
`a_correction_without_a_reason_is_refused`,
`voiding_every_punch_leaves_the_day_as_if_it_never_happened`,
`a_teacher_cannot_correct_their_own_punches`,
`the_clock_overwrites_the_rosters_word`,
`the_clock_does_not_overwrite_a_day_somebody_typed`,
`a_supervisor_correction_overrules_a_hand_typed_day`,
`a_punched_day_does_not_have_to_be_confirmed_before_approval`,
`punches_never_change_an_approved_period`,
`punched_hours_count_towards_overtime_like_any_other`,
`a_teacher_sees_their_own_clock`,
`a_teacher_cannot_reach_another_persons_punches`,
`the_grid_marks_the_day_that_does_not_add_up`,
`the_day_form_shows_the_clocks_own_account_of_each_day`,
`the_day_screen_shows_the_punches_and_why_they_were_changed`.

---

## Things this deliberately does not do

- **No rounding.** Not to the quarter hour, not the seven-minute rule, not at
  all. A punch is a fact about a minute, and rounding it is a decision about
  somebody's pay dressed up as tidiness. If a centre needs it, it belongs in
  one place — `TimeClock::walk` — and it belongs in writing.
- **No kiosk or PIN.** Punching is done signed in as yourself, so a shared
  tablet at the door means a shared login, and a shared login means punches
  nobody can be held to. A real kiosk needs a per-person PIN and a device the
  centre owns; until then this is honest about what it can prove.
- **No auto clock-out.** A forgotten punch is reported, never closed at
  midnight or at the rostered end. See [a day that does not add
  up](#a-day-that-does-not-add-up-is-never-guessed-at).
- **No self-correction.** An employee cannot amend their own punches, including
  the one they just made. A record its subject can revise is not one.
- **No geofence, photo or biometric.** The IP a punch came from is recorded and
  that is the whole of it. The rest is surveillance a daycare does not need and
  in several states cannot lawfully collect.
- **No "confirm all" button.** Confirming is somebody reading a fortnight of one
  person's days and saying yes. A button that did it for everybody at once would
  turn the one safeguard here into a formality.
- **No pay calculation.** Estimated gross is a cross-check. Tax, deductions,
  benefits and salaried staff belong to the payroll provider, and this hands
  them hours, not money.
- **It does not edit the roster.** A correction says what happened; it never
  rewrites what was planned.

---

## Before this goes live

- **Check the period type.** `timesheet.period` is semi-monthly. If the centre
  pays biweekly or weekly, that is a code change, not a config one — the
  boundaries live in `PayPeriod`.
- **Fill in the pay rates.** Employees without `pay_rate` export blank in the
  gross column. The hours are unaffected.
- **Agree what `UNPAID` means** with whoever runs payroll, so a recorded unpaid
  absence and a missing day are not read as the same thing at the other end.
- **Check the break rules against your state.** `clock.paid_break_cap` is the
  federal twenty minutes. Several states mandate a paid rest break of a set
  length per shift, and some require a meal period be *offered* at all — none of
  which this enforces. It records what happened; it does not police it.
- **Decide who counts as a supervisor.** Correcting punches is `role:admin`
  today, which at this size means the director. A centre with room leads who
  should correct their own room's punches needs a role between the two.
- **Tell staff what is recorded.** The minute, the device's IP, and every
  correction with its reason. Several states require notice before time-tracking
  data is collected, and it is a fair thing to say regardless.

---

# Part six — the staff room, the roster and payday

A hand-testable script for the four staff-facing screens: **Teachers & Rules**,
**Week Schedule**, **Payroll Prep** and **Payroll**. Every step says what to do
and what you should see, so a wrong result is obvious rather than a matter of
taste.

The companion to [walkthrough.md](walkthrough.md), which covers the children's
attendance sheet. That one is about who is *in*; this one is about who is *on*.

## Setting up

```bash
php artisan migrate:fresh --seed              # users, rooms, six placeholder logins
php artisan db:seed --class=DemoScenarioSeeder  # 13 children, three weeks booked
php artisan db:seed --class=StaffSeeder         # 8 staff with employment details and rules
php artisan db:seed --class=TimesheetDemoSeeder  # two pay periods of hours
php artisan payroll:demo                        # a fake combined payroll PDF to upload
```

Order matters. `StaffSeeder` needs the children — the ratio checks read the
children's booked week, so on an empty roster every room reads as needing nobody
and the coverage bar has nothing to say.

Log in as `admin@daycare.test` / `password`.

### The staff room you get

Eight people, chosen so every rule that changes the roster is exercised by
somebody, and so the interesting failures happen on their own rather than being
staged. All log in with `password`, at `firstname.lastname@daycare.test`.

| Staff | Type | Room | The rules that matter | Why they are here |
|---|---|---|---|---|
| Maria Santos | LEAD | Infant | `CAN_OPEN`, 40 h | keyholder — can start at 7:00 |
| Emily Carter | FT | Toddler | 40 h, prefers Fri off | a plain full-timer, and a **soft** rule to watch get broken |
| **Aisha Khan** | FT | UPK-4 | **`FIXED_SHIFT` 08:00–16:00** | contract hours the solver may not move |
| **Grace Lee** | PT | PreK | **window 07:00–12:00**, 20 h | mornings only — **leaves PreK uncovered after midday** |
| David Nguyen | FT | School Age | `FIXED_END` 18:00, 40 h | designated closer |
| **Sofia Reyes** | PT | Transition | **`NEEDS_SUPERVISION`**, after 09:00, `NO_PAIR` Olivia | new hire — **Transition opens two hours before she may start** |
| Hannah Brooks | FT | Toddler | `CAN_OPEN`, 40 h, prefers 07:00 | second keyholder |
| **Olivia Turner** | SUB | — | `MAX_HOURS`, **off Wednesdays** | the only substitute, **away on the day the gaps are widest** |

The six `*.teacher@daycare.test` logins from `DatabaseSeeder` are still there and
still work — `walkthrough.md` signs in as them to check room visibility. They are
benched with an `UNAVAILABLE_DAY / every day` rule so they never reach the
roster. Left schedulable they would double every room's cover and hide every
shortfall this seeder exists to show.

---

## Part one — Teachers & Rules

### 1. Read a staff record

Open **Teachers**, click **Grace Lee**.

**Expect:** the employment card — legal name *Grace Y. Lee*, PT, PreK, `$15.50/hr`,
direct deposit *No*. Below it, her two rules written as English, not codes:

> **HARD** — Only works 7:00 AM–12:00 PM every day
> *Classes in the afternoon.*

The note under each rule is the director's own words. Rules are entered once and
trusted for months; without the note, nobody knows six months later whether it
still applies.

### 2. The form only offers what the rule type uses

Still on Grace. In **Add a rule**, change **Rule type** through the list.

**Expect:** the inputs appear and disappear as you go. `CAN_OPEN` shows nothing
but priority. `REQUIRED_HOURS` shows a number and a WEEKLY/BIWEEKLY picker.
`NO_PAIR` shows a **dropdown of the other teachers** — never a free-text box.

### 3. Rules that would silently do nothing are refused

Try to save each of these:

| Do this | Expect |
|---|---|
| `AVAILABLE_WINDOW`, start `14:00`, end `09:00` | *The end time has to be after the start time.* |
| `AVAILABLE_WINDOW` with the end time blank | *This is required for a AVAILABLE_WINDOW rule.* |
| `REQUIRED_HOURS` — then edit the URL to post `MONTHLY` | *Choose WEEKLY or BIWEEKLY.* |

This is the point of the screen. A rule with a missing time is not a constraint,
it is a line in the list that a director believes is being enforced.

### 4. Switching a rule's type clears what it no longer uses

Add a `FIXED_SHIFT` (Mon, 08:00–16:00). Then edit it to `AVAILABLE_AFTER`.

**Expect:** the end time is gone from the row, not merely hidden. A stale
`16:00` left behind is invisible on screen and applies again the day somebody
switches the type back.

---

## Part two — Week Schedule

### 5. Generate the week

Open **Week Schedule**. It lands on the current week, empty.

- Press **Generate schedule**.

**Expect:** roughly 95 shifts, and a banner reading *"Schedule generated with 12
things to look at."* Both numbers move as you change rules — the shape is what
matters, not the exact count.

### 6. Read the hard rules off the chart

Stay in **By teacher**. Look down Monday.

- **Aisha Khan** — one bar, 8:00 to 4:00, every day, exactly. Her `FIXED_SHIFT`
  is the one thing the solver will not move.
- **Grace Lee** — her bar stops dead at **12:00**.
- **David Nguyen** — his bar ends at **6:00 PM**, every day.
- **Olivia Turner** — bars on four days, and **Wednesday is empty**.

Cover shifts are drawn with a **dashed** border (substitute) or a **dotted** one
(a regular teacher pulled off their own room). Hover any bar for the room, the
times and the hours.

### 7. Wednesday is where it falls apart

Switch to **By room**, then the **Wednesday** chip.

**Expect**, in the ratio bar under each room, red bands where cover ran out:

- **Transition, 7:00–9:00** — one child, nobody. Sofia may not start before 9:00,
  and Olivia, who covers this on every other day, is off on Wednesdays.
- **PreK, 12:00–3:00** — three children, nobody. Grace goes home at midday.

Click **Tuesday** and **Thursday**: the Transition and PreK gaps are gone,
because Olivia is in and patches them. **UPK-4, 7:00–8:00** is short on both,
because Aisha's contract starts at 8:00 and nobody else is free that early.

That is the tool doing its job: the same roster is fine four days a week and
breaks on the fifth, and you can see exactly why.

### 8. One gap is one warning

Scroll to the amber box.

**Expect** the PreK gap as a **single line covering the whole afternoon**:

> WED PreK, 12:00 PM–3:00 PM: 3 children need 1 staff, none scheduled — short by 1.

Not six lines, one per half hour. A room missing a teacher all afternoon is one
problem, and printing it per slice buries the other five things worth reading.

### 9. The overtime lines say *why*

Also in the amber box:

> Hannah Brooks: 53h against a 40h target — 17h of it covering other rooms.

Every full-timer shows something like it, and that is the correct answer, not a
bug. The centre is open eleven hours a day across six rooms; these eight people
are contracted for 264 hours a week against roughly 330 of floor time. Faced with
a gap, the solver books overtime rather than breach a ratio — a ratio breach
costs the licence, an hour of overtime costs money — and then tells you the
price. **The fix is another pair of hands, not a smaller roster.**

To watch that resolve: give Grace an afternoon by widening her window to 18:00,
regenerate, and the PreK line disappears along with a chunk of everyone's cover.

### 10. Soft rules bend, hard rules do not

Emily Carter *prefers* Friday off, and is scheduled on Friday anyway — her 40
hours have to come from somewhere. In the amber box:

> Emily Carter prefers FRI off but is scheduled then. Mark the rule HARD to enforce it.

That line is the entire practical difference between the two priorities. A soft
rule is honoured where it can be and **accounted for where it cannot**; one that
produced no output at all would be a preference the director believes was
considered.

Now open Emily, change that rule's priority to **HARD**, and regenerate.

**Expect:** her Friday bar is gone entirely, the warning about it disappears, and
the Friday shortfalls grow. Hard means the solver leaves a room short rather than
break it.

A broken preference is summarised **once per person**, not once per day — try
setting Hannah's preferred start to `06:00` and regenerating: one line saying
*every day*, not five.

### 10b. Three rules are recorded but not yet enforced

Add a `MAX_HOURS`, `HALF_DAYS` or `PREFERRED_FULL_DAYS` rule to anybody.

**Expect:** an amber note on the form as you pick the type, and another under the
saved rule: *"Recorded, but the scheduler does not act on this rule yet."*

They are in the vocabulary because the director's intent is worth capturing
before the solver can honour it. They are flagged because the one thing worse
than a missing feature is a rule somebody believes is being enforced. The list
lives in `StaffRule::NOT_YET_ENFORCED` — delete a type from it the moment
`StaffSchedule` starts reading it.

### 11. The rules that report rather than block

Two warnings you should find without changing anything:

- *"… Sofia Reyes and Olivia Turner overlap but are marked NO_PAIR."*
- Delete Maria's and Hannah's `CAN_OPEN` rules, regenerate, and every day reports
  *"no keyholder is scheduled at opening (7:00 AM)."*

### 12. A closed day is left alone

Go to **Attendance**, close a day with **Close day**. Come back and regenerate.

**Expect:** that column is empty of shifts, and reports no shortfall. Nobody is
short-staffed on a day the centre is shut.

### 13. A teacher can read it but not rebuild it

Sign in as `grace.lee@daycare.test`.

**Expect:** **Week Schedule** is in the sidebar and opens read-only — knowing who
else is on the floor at 3pm is the reason the roster exists. There is **no
Generate button**, and **Payroll is not in the sidebar at all**.

---

## Part three — Payroll

`php artisan payroll:demo` wrote a ten-page PDF to `storage/app/demo-payroll.pdf`
built from the seeded legal names. It deliberately contains two awkward cases.

### 14. Upload and split

Open **Payroll**. Upload `demo-payroll.pdf`, period label `Jul 9 - Jul 22, 2026`.

**Expect:** *"Payroll split into 9 payslips."* Ten pages, nine payslips — and the
review screen opens with a stepper down the left.

### 15. Two pages, one person

Click **Maria Santos** in the stepper.

**Expect:** `p6,7` beside her name, and both pages in the preview. Consecutive
pages for the same person are one payslip.

### 16. The page nobody owns

The last entry reads **Unmatched** with an amber dot, and a banner at the top
says *"1 page group could not be matched to a staff member."*

That page names *Jonathan P. Whitfield*, who left. The matcher will not guess:
a wrong match sends a teacher somebody else's pay, and an unmatched page sends
nothing and asks a human. Assign it to anyone with the dropdown, press
**Reassign**, and the dot turns grey.

### 17. Matching survives a middle initial

Every page prints a middle initial — *Maria G. Santos* — and every record holds
one. Open **Maria Santos**, change her legal name to plain `Maria Santos`,
re-upload the PDF.

**Expect:** she still matches. First and last name carry the signal; requiring
the initial would fail every payroll system that omits it.

### 18. Send one

Back on the review screen, pick anybody matched.

- Press **Send a copy to me**.

**Expect:** a success banner, and the slip stays **grey, not green**. A copy to
the director is a check, not a delivery — marking it sent would hide that the
teacher still has not had theirs.

- Now press **Email this teacher**.

**Expect:** green dot, *"Sent to …"*, and the stepper **advances to the next
person**. With `MAIL_MAILER=log` the mail lands in `storage/logs/laravel.log` —
grep for the subject line. Check the placeholders resolved: the subject should
read *"Your payslip - Jul 9 - Jul 22, 2026"* and the body should open *"Hi
Maria,"*.

### 19. The sends that are refused

| Do this | Expect |
|---|---|
| Open the unmatched slip | **Email this teacher** is greyed out — *"Assign this payslip to somebody first."* |
| Clear a teacher's email, then try to send theirs | *"… has no email address on their staff record."* |
| Reassign a slip you already sent | It drops back to **pending** and has to be sent again |

That last one matters: reassigning after a send would leave the sent-to address
pointing at the wrong person.

### 20. The PDF does not outlive the record

Note the batch's file, then **Delete** it from the payroll list.

**Expect:** the row and the file both go. A payroll PDF is every employee's pay
in one file; leaving orphans on disk after the record is gone is the one outcome
worth writing code to prevent.

---

## Part four — Payroll Prep

The hours that go *to* payroll, as opposed to the payslips that come back from
it. Open **Payroll Prep** in the sidebar. Full detail in
[payroll-prep.md](payroll-prep.md).

`TimesheetDemoSeeder` builds two periods, both anchored to today: **the one just
gone**, finished and approved, and **the one running now**, half worked. The
exact hours move with the roster, so read the shapes rather than the totals.

### 21. The finished period is a record

Step back with **‹ Previous** to the period just gone.

**Expect:** an **Approved** badge, and the editing controls gone — no *Fill from
the roster*, no *Approve*, only **Reopen** and the CSV. Every day is bold, never
amber. Click a name: the form is there, every input disabled, with a line saying
the hours have gone to payroll.

### 22. The CSV is the deliverable

Press **Download CSV for payroll** on that finished period.

**Expect:** one row per employee who has hours, with **Regular**, **Overtime**,
**Paid leave** and **Unpaid leave** as four separate columns — they are paid at
three different rates and one of them is not paid at all. Somebody has 8 paid
leave hours from a PTO day. Staff with no hours in the period are absent from the
file entirely rather than present as zeroes.

### 23. Amber is the roster's word, bold is somebody's

Come forward to the period running now.

**Expect:** one person — the last on the roster — is entirely **amber**. Nobody
has confirmed a single one of their days; they are exactly as *Fill from the
roster* left them. Everybody else is bold. The strip under the buttons counts
them: *"10 day(s) still as the roster left them."*

### 24. Approving is refused, twice, for different reasons

Press **Approve period**.

**Expect:** refused — *"The period of … has not finished yet. Approve it once the
last day is done."* The period is still running, and that check comes first.

Now step back to the finished period and imagine it were still draft: the second
refusal names the amber days instead. Both are in
`TimesheetTest::approving_is_refused_before_the_period_has_finished` and
`::approving_is_refused_while_days_are_still_the_rosters_word`.

### 25. Confirming a person clears their amber

Click the all-amber name. Change nothing. Press **Confirm these days**.

**Expect:** *"n day(s) confirmed."* Back on the grid their row is bold and the
counter has dropped by their days. Nothing about the hours changed — only who is
standing behind them, which is the entire distinction.

### 26. A half-entered day is refused rather than guessed

On anybody's form, put an **in** time on an empty day and leave **out** blank.
Save.

**Expect:** an amber banner naming that day — *"Thu 6 Aug has only an in time."*
The rest of the form saves normally. Try an out time earlier than the in time on
another day: *"… ends before it starts."*

### 27. Leave is three different facts, not one

Look along the rows for the coloured day cells.

**Expect:** `SICK` and `PTO` on **sky**, `UNPA` on **gray**. The sky ones add to
the **Leave** and **Paid** columns; the gray one adds to neither — it appears
only in the export's own *Unpaid leave hours* column. An unpaid absence is a
recorded decision, not a blank.

### 28. Overtime is worked out per week, not per period

Look at the **OT** column.

**Expect:** several people carry 10–14 hours. Open one and count their week: the
hours all sit inside a single Monday-to-Sunday, not spread across the fortnight.
Forty is a weekly threshold, so a fortnight of two 40-hour weeks is 80 regular
hours and no overtime at all — while 45 and 35 is five hours of overtime despite
totalling the same 80.

Paid leave never counts towards that forty. One person here has both a sick day
and overtime; the sick day contributed nothing to pushing them over, and eight
hours of PTO followed by a 40-hour week is 48 paid hours with **zero** overtime
(`TimesheetTest::paid_leave_does_not_create_overtime`).

### 29. Hours without a rate say so

Look at the **Gross** column.

**Expect:** any employee with no **Pay rate** on their record reads **no rate**
in amber, not `$0.00`. Their hours are still right; only the money is missing,
and zero would be a different and much worse claim. A note above the table counts
how many.

---

## Part five — the time clock

Punching in, and putting a punch right. Full detail in
[payroll-prep.md](payroll-prep.md#the-time-clock).

`TimesheetDemoSeeder` gives one rostered person four days on the clock in the
period running now: one clean, one with a clock-out that had to be corrected, one
they walked out of without pressing anything, and one clean again.

### 30. Punched days are underlined, not amber

On the period running now, find the person with **underlined** day cells.

**Expect:** their hours are neither bold nor amber. A punched day is the
employee's own account of it, so it never needed confirming — and it is not
somebody else's word either, so it is not bold. Click one: it opens the punches
behind it.

### 31. A break is paid and lunch is not

On that day screen, read the four tiles.

**Expect:** **Paid break** 15 min and **Unpaid break** 30 min, and **Punched
hours** is the whole span less the lunch only. That is the only reason lunch and
break are separate buttons — the FLSA counts a short rest break as hours worked
and a meal period as not.

### 32. A correction is a void plus a replacement

Scroll the punch list on that same person's corrected day.

**Expect:** two clock-outs, well over an hour apart. The later one is **struck
through**, marked **voided**, with *"Clocked out for the closing room by
mistake"* and the director's name under it. The earlier one is marked **replaces
{that time}** and carries its own reason. Nothing was edited and nothing was
deleted; both are permanent, and only one counts.

### 33. A forgotten punch is worth nothing, not a guess

Find the red **!** on the grid and click it.

**Expect:** *"This day does not add up: never clocked out."* The **Punched
hours** tile reads `—` and the day pays nothing. It was not closed at midnight
and not closed at their rostered end — inventing hours out of a button nobody
pressed is worse than reporting a gap.

### 34. It refuses to be approved for a second, different reason

Press **Approve period** with that `!` still standing.

**Expect:** *"n day(s) have punches that do not add up."* A different refusal
from the amber one: that is *nobody has said yet*, this is *what was said cannot
be true*.

### 35. Fixing it takes a reason

On the day screen, add a punch: **Clock out**, a time, and leave **Reason**
blank.

**Expect:** refused. Fill the reason in and add it.

**Expect:** the day rebuilds from its punches, the `!` on the grid becomes hours,
and the approval refusal drops away. The reason is required rather than
encouraged because the question it answers is asked months later by somebody who
was not in the building.

### 36. The teacher sees their own clock, and only that

Sign in as a teacher and open **Time Clock**.

**Expect:** the buttons legal from where they stand and no others — **Clock in**
alone at first; **Start lunch**, **Start break** and **Clock out** once in; only
**End lunch** while at lunch. Their last fortnight underneath, with any corrected
punch saying *moved from … by Director* and why. No pay rate, no colleague,
and no way to change anything already recorded — including their own.

---

## Things this deliberately does not do

- **No rounding on the clock.** Not to the quarter hour, not the seven-minute
  rule. A punch is a fact about a minute, and rounding it is a decision about
  somebody's pay dressed up as tidiness.
- **No auto clock-out, and no self-correction.** A forgotten punch is reported,
  never closed at midnight; and an employee cannot amend their own punches. A
  record its subject can revise is not one.
- **No kiosk, PIN, geofence or photo.** Punching is done signed in as yourself,
  and the IP is the whole of what else is recorded.
- **No "confirm all" button.** Confirming is somebody reading a fortnight of one
  person's days and saying yes. Doing it for everybody at once would turn the one
  safeguard in payroll prep into a formality.
- **No "send all" button.** One misclick would mail an entire payroll run built
  on a split nobody reviewed.
- **No guessing on an ambiguous page.** Two people equally well matched, or the
  same name reappearing non-consecutively, both arrive unassigned.
- **The staff roster never copies forward.** Unlike the children's week, a staff
  week is the answer to the rules as they stood when it was generated. Carrying
  it into next week would hide a rule change rather than apply it.

## Before this goes live

- **Check the ratios** in `config/daycare.php` against your state licensing
  table. PreK and UPK-4 are both set to 1:10, which was a judgement call.
- **Configure SMTP.** `MAIL_MAILER=log` means payslips go to the log file, not
  to anybody's inbox.

---


---

# Part seven — user guide: every button, screen by screen

What each control on each screen does, who can see it, and what happens when you
press it. The tables list the controls as they are labelled on screen.

**A** = director only · **A T** = director and teacher

---

## Sidebar and navbar

| Control | Who | What it does |
|---|---|---|
| The logo | A T | Back to the dashboard |
| ☰ (in the sidebar header) | A T | Collapses the sidebar to an icon rail, and back |
| ☰ (top left, small screens) | A T | Opens the sidebar as a drawer |
| × (in the drawer) | A T | Closes it |
| ☀ / ☾ | A T | Switches light and dark. Remembered on this device |
| 🔔 | A T | Notifications — placeholder, nothing behind it yet |
| Your name and photo | A T | Opens **Profile** |
| ⇥ (log out) | A T | Signs out |

Nav items appear by role: **Dashboard · Class Attendance · Children** for
everyone; **Reports · Week Schedule · My Schedule · My Leave · Time Clock** for
teachers and directors; and **Teachers · Room Schedules · Holidays · Leave
Requests · Payroll Prep · Payroll · Import Children** for directors alone.
**Leave Requests** carries a badge counting what is waiting.

---

## Dashboard

| Control | Who | What it does |
|---|---|---|
| **Take attendance →** | A T | Opens today's sheet |
| **View all** | A T | Recent sign-ins → the full sheet |
| **Manage holidays** | A | Opens **Holidays**. Not shown to teachers, because the page 403s for them |
| A closure tile | A T | Opens that week on the attendance board |
| Quick actions | A T | Import Children (A), Attendance, Reports (A), History |

---

## Class Attendance

### The week bar

| Control | Who | What it does |
|---|---|---|
| **‹** / **›** | A T | Previous / next week |
| **This week** | A T | Back to today's week |
| Date box → **Go to that week** | A T | Jumps to whatever week that date falls in |
| **Open this week** | A T | Builds a week that does not exist yet, copying the newest earlier week. Only appears when the week is unbuilt and not finished |
| **Copy from another week** | A T | Opens the copy dialog. Only when the week is built and not finished |

### The sheet

| Control | Who | What it does |
|---|---|---|
| **Sign in** / **Set schedule** | A T | Switches between recording arrivals and planning the week |
| Search children | A T | Filters the rows |
| **All** + room chips | A T | Filters by room. A teacher sees only their own rooms |
| **Student** | A T | Sorts by name |
| **? Key** | A T | Shows what the four box colours mean |
| A box, in **Sign in** | A T | Signs the child in for that session. Today only |
| A box, in **Set schedule** | A T | Ticks or unticks the planned day. Drag across several; **Space** toggles the focused one |
| A day heading, in **Set schedule** | A T | Sets or clears that whole column |
| **All · MWF · TTh · Clear** | A T | Quick-sets one child's week |
| **Done — back to sign-in** | A T | Leaves the schedule view |
| Closure toggle on a day heading | A T | Shuts the centre for that day, or opens it again. Asks for a reason |
| A child's name | A T | Opens their record |
| Room dropdown → **Save** | A | Moves a child to another room from a date. **Clear override** hands them back to the age rule |

### The copy dialog

| Control | Who | What it does |
|---|---|---|
| Week list | A T | Which week to copy. Last week is pre-selected; each row shows how many days it had ticked and any closures |
| **Add** | A T | Only turns days on. Keeps anything ticked here that the source does not have |
| **Replace this week** | A T | Makes this week an exact match of that one, clearing the extras |
| Also copy the sign-ins | A | Writes attendance for days nobody signed in. For demo data only — off by default |
| **Copy schedule** | A T | Runs it. The banner says what moved, including when nothing did |
| **Cancel** | A T | Closes without changing anything |

---

## Children

| Control | Who | What it does |
|---|---|---|
| **+ Add child** | A | New child form |
| **View** | A T | Opens the record |
| **Edit** | A | Opens the record for editing |
| Photograph | A T | Opens it full size. Directors get **Add a photo** when there is none |
| **← Roster** | A T | Back to the list |
| **Edit record** / **Edit details** | A | Same, from inside a record |

### Importing

| Control | Who | What it does |
|---|---|---|
| **Import children** | A | Reads a spreadsheet of children |
| **Import PDF / Image** | A | Reads an enrolment form and pulls the fields out |
| **Open in new tab ↗** | A | Shows the uploaded document beside the review |
| **← Back to child form** | A | Returns to the extracted fields |
| **← Import another form** | A | Starts again with a new document |
| **Remove** | A | Drops the chosen file before uploading |

---

## Holidays

| Control | Who | What it does |
|---|---|---|
| Date · Last day · Reason → **Close these days** | A | Shuts the centre. With a last day it closes the range, skipping weekends |
| Month · Day · Name → **Add annual holiday** | A | A holiday that repeats. Written five years ahead at once |
| **Edit** (on a closed day) | A | Change its reason, or move it to another date |
| **Reopen** | A | Opens the day again and puts back the ticks the closure cleared |
| **Edit** (on an annual holiday) | A | Rename it, or move it. A moving holiday like Good Friday can only be renamed |
| **Remove** (on an annual holiday) | A | Stops it recurring. Future days reopen; past ones stay |
| **View week** | A | That week on the attendance board |
| **Cancel** | A | Closes the dialog |

The reopen dialog names the children who come back before you confirm, and
strikes through any who cannot because they have left or changed room.

---

## Reports

| Control | Who | What it does |
|---|---|---|
| **Daily sheet** / **Class report** | A T | Switches which sheet you are looking at |
| Date → **View** | A T | Loads that week |
| **Print** | A T | Prints without the sidebar or navbar, landscape |

---

## Teachers *(director only)*

| Control | What it does |
|---|---|
| **+ Add Teacher** | Creates a staff login. The starting password must be changed on first sign-in |
| **Rules** | Opens their scheduling rules |
| **Edit** | Their employment details |
| **Delete** | Removes the account |
| **Add rule** | Adds a scheduling rule. The form shows only the fields that rule type uses |
| **Remove** (on a rule) | Deletes it |
| **Back to roster** | Back to the list |

---

## Room Schedules *(director only)*

| Control | What it does |
|---|---|
| Opens / Closes per room | The hours that room runs |
| **Save hours** | Saves every room at once |

---

## Week Schedule

| Control | Who | What it does |
|---|---|---|
| Date → **Go** | A T | Jumps to the week that date falls in. There is no previous/next pair here — the date box is the whole of the navigation |
| **By teacher** / **By room** | A T | Whose day, or which room's |
| Day chips | A T | Picks the day the room view draws its ratio bar for |
| **My schedule** | T | Your own week. Shown only to teachers — a director has no shifts of their own |
| **Generate schedule** | **A** | Solves the week from the rules, the ratios, approved leave and the closures. Teachers do not get this button |

---

## My Schedule

| Control | Who | What it does |
|---|---|---|
| **← Last week** / **This week** / **Next week →** | A T | Moves between weeks |
| **Everyone's week** | A T | The whole floor, read-only for teachers |

The rest of the page is read-only: the next shift, your hours against your
target, a card per day. Shifts are not editable here — that is the director's
roster.

---

## Time Clock

| Control | Who | What it does |
|---|---|---|
| **Clock in** | A T | Starts your day |
| **Start lunch** / **End lunch** | A T | Unpaid, however short |
| **Start break** / **End break** | A T | Paid for the first 20 minutes, unpaid past that |
| **Clock out** | A T | Ends your day |

Only the presses that are legal from where you stand are offered — you cannot
start lunch before clocking in. You cannot change a punch you have made; a
correction is a supervisor's act.

---

## My Leave

| Control | Who | What it does |
|---|---|---|
| Type · first day · last day · hours · reason → **Send the request** | A T | Files it as pending |
| **Withdraw** | A T | Takes back a request nobody has decided yet. Approved ones need the director to revoke |

Weekends and closed days are not charged for. You may ask for more than your
balance holds — that is settled at the decision, not the ask.

---

## Leave Requests *(director only)*

| Control | What it does |
|---|---|
| Status tabs | Filters the queue. Waiting sorts to the top |
| **Approve** | Grants it and takes the hours off the balance. Refused first time if it outruns the balance — tick the shortfall box to grant the rest unpaid |
| **Deny** | Refuses it. The reason is kept and shown to the person who asked |
| **Revoke** | Undoes an approval. The hours go back and the days come off the timesheet — the shifts do not come back, so regenerate the week |
| **Balances & accrual** | The balances page |

---

## Balances & accrual *(director only)*

| Control | What it does |
|---|---|
| **Run accrual** | Earns leave from the approved pay period. Safe to press twice — it is earned once |
| Hours · reason → **Post** | Moves a balance by hand. The reason is required |
| **See every balance →** | From the accrual result back to the list |
| **Requests** | Back to the queue |

---

## Payroll Prep *(director only)*

| Control | What it does |
|---|---|
| **‹ Previous** / **Next ›** | Moves between pay periods |
| Date → **Go** | Jumps to the period a date falls in |
| **Fill from the roster** | Copies the published roster in as a draft. Only ever adds — a day somebody confirmed is left alone |
| A person's name | Opens their day-by-day form |
| **Confirm these days** | Saves the form. Every day on it becomes somebody's word rather than the roster's |
| A red **!** on the grid | Opens the punches for the day that does not add up |
| An underlined figure | Opens the punches behind a clocked day |
| **Approve period** | Freezes it. Refused while the period is still running, while any day is still the roster's word, or while any punches do not add up |
| **Reopen** | Unfreezes an approved period. Payroll has the old numbers, so tell them |
| **Download CSV for payroll** | The deliverable. One row per employee with hours |

### One day's punches

| Control | What it does |
|---|---|
| **Add punch** | Records a press nobody made — the 5pm clock-out somebody forgot. Needs a reason |
| **Move to this time** | Voids the original and writes a replacement pointing at it. Needs a reason |
| **Void** | Stops a punch counting. It stays visible, struck through, for good |

Nothing here is ever edited or deleted, and every correction wants a reason —
the question it answers gets asked months later by somebody who was not there.

---

## Payroll *(director only)*

| Control | What it does |
|---|---|
| File · period label → **Upload and split** | Splits a combined PDF into one payslip per person |
| **Review & send** | Opens a batch |
| The stepper | Moves between payslips |
| Person dropdown → **Reassign** | Points a payslip at the right person. A slip already sent drops back to pending |
| **Email subject & body** | Edits what goes out |
| **Send a copy to me** | A check. Does **not** mark the slip sent |
| **Email this teacher** | Sends it, marks it green, and moves to the next person |
| **Delete** | Removes the batch and its PDF from disk |
| **All payroll runs** | Back to the list |

---

## Profile

| Control | Who | What it does |
|---|---|---|
| **Save Changes** | A T | Your name, contact details and photo |
| **Update Password** | A T | Needs your current one |
| **Remove photo** | A T | Back to initials |
| **Undo** | A T | Cancels a photo you picked but have not saved |

---

## Signing in

| Control | What it does |
|---|---|
| **Sign in** | Email and password |
| **Save password** | On the forced first-login change. Until it is done, this is the only page the account can reach |
| **Sign out** | From that page |

---

## Buttons that are deliberately absent

Worth knowing, because their absence is a decision rather than a gap.

| Not there | Why |
|---|---|
| Fill the schedule from the forecast | A prediction built from last week's absences must not become this week's plan |
| Confirm all, on Payroll Prep | Confirming is somebody reading a person's fortnight and saying yes. One button for everybody would make it a formality |
| Send all, on Payroll | One misclick would mail a whole payroll run built on a split nobody reviewed |
| Edit or delete a punch | A correction is a void plus a replacement, so the original never stops being visible |
| Correct your own punches | A record its subject can revise is not a record |
| Generate, for a teacher | They may read the roster; only a director rebuilds it |
| Anything on a finished week | Its Friday has passed. It is a record, and DSS bills against it |

---


---

# Part eight — workflows: what has to happen before what

The other parts say what each screen does. This one says the order things go in,
what each step is waiting on, and which orderings quietly produce a wrong number
rather than an error.

---

## Two systems, not one

The commonest confusion in this app, so it comes first: **children and staff are
tracked by two separate pipelines that never touch.**

```
CHILDREN                                 STAFF
   enrolment                                employment + rules
        ↓                                        ↓
   schedule slots      ← closures →          staff shifts
        ↓                                        ↓
   attendance                                time clock
   (sheet + door kiosk)                           ↓
        ↓                                     timesheet
   DSS billing · reports                          ↓
                                              payroll · leave accrual
```

They share exactly one thing: **a closure day closes both.** Nothing else
crosses. A child signing in at the door does not touch anybody's hours; a
teacher clocking in does not touch any child's attendance.

Say the words apart and most of it comes clear:

| | Children | Staff |
|---|---|---|
| Planned by | the schedule slots ticked on the sheet | the generated roster |
| Recorded by | a sign-in, from the sheet **or the door kiosk** | a punch on the time clock |
| Lives in | `attendances`, `child_attendance_punches` | `time_punches`, `timesheet_entries` |
| Ends up as | DSS billing and reports | a payroll CSV |

---

## Once, when a centre starts

```
1  Rooms          Room Schedules → hours per room
2  Holidays       Holidays → the year's closures, and the annual rules
3  Teachers       Teachers → account, employment type, pay rate
4  Rules          Teachers → …→ Rules → fixed shifts, windows, keyholders
5  Children       Children → enrolment dates, contracted hours, room
6  Guardians      PINs and the pickup list, if the door kiosk is on
```

**Order matters at step 4 → 5.** The roster is solved against the *ratios the
booked children need*. On an empty roll every room reads as needing nobody, so a
roster generated before the children are in comes out empty and correct-looking.
That is why `StaffSeeder` runs after `DemoScenarioSeeder`.

---

## Every day

```
   morning
      │
      ├─ a guardian at the door ──→ kiosk PIN ──→ tap child ──┐
      │                                                        ├─→ attendances
      └─ a teacher on the sheet ──→ tap the box ──────────────┘   (one row, either way)
                                                                       │
   during the day                                                      │
      └─ teachers punch the time clock ──→ time_punches ──┐            │
                                                          │            │
   afternoon                                              │            │
      └─ a guardian collects ──→ kiosk ──→ signed_out_at ─────────────┘
                                                          │
                                                          ↓
                                            rolled into timesheet_entries
```

**Only today can be signed in.** A missed day is not fixed on the sheet; it is
fixed by the director on the week it belongs to, or not at all.

**The door and the sheet write the same row.** A kiosk sign-in *is* a sheet
sign-in — same table, same keys, stamped at the minute the guardian pressed it.
Nobody transcribes anything, and the two can never disagree.

---

## Every week

```
   Mon  ── open the week ──→ copies the newest earlier week's pattern
             │                (only the plan travels, never the sign-ins)
             ↓
        adjust the ticks ──→ or copy from a better week (Add / Replace)
             │
             ↓
        closures already set greyed their columns and cannot be ticked
             │
             ↓
        Generate the roster  ← reads: rules · ratios from booked children
             │                        · approved leave · closures
             ↓
        teachers read it on Week Schedule / My Schedule
             │
   Fri  ── the week ends ──→ frozen. Pattern, closures and room moves all refuse.
```

**What blocks what**

| Step | Waits on | If you do it early |
|---|---|---|
| Open a week | nothing — but it copies the newest week *that exists* | it inherits from further back than you meant |
| Tick the sheet | the week being open | there is nothing to tick |
| Generate the roster | children booked, rules set, leave decided | a roster with no gaps because nobody needed staffing |
| Approve leave | — | see below: leave granted after generating needs a regenerate |

**Leave approved after the roster was published** pulls that person's shifts and
leaves a warning asking for a regenerate. Colleagues' shifts are deliberately
left alone — re-solving the week would rewrite everybody's published hours
around one person's day off, and people have arranged childcare around them.

---

## Every fortnight — the pay period

Semi-monthly: the 1st to the 15th, and the 16th to month end. **A period never
lines up with a week**, and most of the design below follows from that.

```
   the period runs
        │
        ├─ punches roll up per day as they happen
        │
   it ends
        │
   1 ── Fill from the roster ──→ days nobody punched arrive as a draft (amber)
        │                        never overwrites a confirmed day
        ↓
   2 ── Confirm the days ──────→ a person reads a fortnight and says yes (bold)
        │                        a punched day needs no confirming — it is
        │                        already the employee's own account
        ↓
        corrections: void + replacement on the punches, with a reason
        ↓
   3 ── Approve ───────────────→ frozen, and leave accrues in the same act
        │
        ↓
   4 ── Download CSV ──────────→ the deliverable
```

**Approve is refused three ways, and the three are different questions:**

| Refusal | Means |
|---|---|
| the period has not finished | you are approving days that have not happened |
| *n* days are still the roster's word | **nobody has said yet** what happened |
| *n* days have punches that do not add up | **what was said cannot be true** |

**Overtime is weekly; pay is per period.** The split is worked out across the
whole Monday–Sunday week — including the days on the far side of the period
boundary — and only then collected into the period each day falls in. Doing it
inside each period instead makes overtime vanish at every boundary.

**Approving is what earns leave.** Accrual posts in the same act, because leave
should be earned only from hours somebody has signed off — and posting is
idempotent, so reopening and re-approving does not pay it twice.

---

## Every year

```
   Holidays ──→ an annual rule writes itself five years ahead as ordinary
                closures, and tops the horizon up on every visit to the page
                     │
                     ├──→ greys the attendance column
                     ├──→ leaves the roster unstaffed that day
                     └──→ costs nobody any leave

   Leave ─────→ balances run continuously and stop at the cap.
                Nothing resets or carries over at year end — see the open list
                in Part four.
```

---

## Payroll — the one that runs backwards

Everything above pushes forward. Payroll pulls: a PDF comes *back* from the
provider and is split into payslips.

```
   Payroll Prep CSV ──→ [ your payroll provider ] ──→ combined PDF
                                                          │
                                          Payroll → upload and split
                                                          │
                                       match to people → preview → send
```

The two halves never talk. **Payroll Prep is the hours going out; Payroll is the
payslips coming back**, and nothing in the app carries a number between them.

---

## Orderings that go wrong quietly

These produce a plausible number rather than an error, which is what makes them
worth a list.

| Doing this | before this | gives you |
|---|---|---|
| Generate the roster | booking the children | an empty roster and no shortfalls — every room reads as needing nobody |
| Generate the roster | deciding leave | somebody rostered on a day they were granted off |
| Approve the period | correcting the punches | a short week sent to payroll as finished |
| Fill from the roster | the roster being generated | nothing copied, and no warning that the roster was blank |
| Set a closure | opening the week | nothing wrong — this one is safe, and is the point of setting them ahead |
| Re-run the holiday seeder | — | safe: a year already written is never revisited |

The last two are there on purpose. Closures and annual holidays are the two
places where doing it early is the *correct* order, and it is worth knowing
which side of the line they sit on.

---

## The whole thing, once

```
  ONCE          rooms · holidays · teachers · rules · children · guardians
                                        │
  ┌─────────────────────────────────────┴──────────────────────────────────┐
  │                                                                        │
  DAILY                                                          WEEKLY
  door kiosk ─┐                                          open week
  the sheet  ─┴──→ attendances ──→ reports · DSS              ↓
                                                          tick the pattern
  time clock ─────→ time_punches                              ↓
                         │                                generate roster
                         │                                    ↓
  ┌──────────────────────┴──────────────────┐           teachers read it
  │                                         │
  FORTNIGHTLY                          CONTINUOUS
  fill → confirm → approve → CSV       leave asked · decided · accrued
                    │                  holidays written years ahead
                    ↓
              payroll provider
                    │
                    ↓
              PDF back → split → match → send
```

---

## Where the rules actually live

| Behaviour | Enforced in |
|---|---|
| Week copies forward, freezes on Friday | `App\Services\WeekSchedule` |
| Expected attendance for a week | `App\Services\AttendanceProjection` |
| Ratio and cover per room | `App\Services\RoomDemand` |
| The generated roster | `App\Services\StaffSchedule` |
| Closures, and reopening them | `App\Models\ClosureDay`, `WeekSchedule::setClosure` |
| Annual holidays, written years ahead | `App\Services\HolidayCalendar`, `App\Models\HolidayRule` |
| Which room a child belongs to | `App\Services\ClassroomAssignment` |
| Role gates | `role:` middleware in `routes/web.php` |
| First-login password | `App\Http\Middleware\RequirePasswordChange` |

A note on reading the code: the comments in this project explain *why* a rule
exists, not what the line does. When a story here and a comment there disagree,
the comment is the one that was written next to the decision.
