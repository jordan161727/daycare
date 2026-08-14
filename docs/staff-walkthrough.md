# Walkthrough — the staff room, the roster and payday

A hand-testable script for the three staff-facing screens: **Teachers & Rules**,
**Week Schedule** and **Payroll**. Every step says what to do and what you should
see, so a wrong result is obvious rather than a matter of taste.

The companion to [walkthrough.md](walkthrough.md), which covers the children's
attendance sheet. That one is about who is *in*; this one is about who is *on*.

## Setting up

```bash
php artisan migrate:fresh --seed              # users, rooms, six placeholder logins
php artisan db:seed --class=DemoScenarioSeeder  # 13 children, three weeks booked
php artisan db:seed --class=StaffSeeder         # 8 staff with employment details and rules
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

## Things this deliberately does not do

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
