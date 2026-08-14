# Payroll preparation

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
