# Leave tracking

*Accruing sick and vacation time, deciding on requests for it, and making an
approved absence show up everywhere it has to — the roster, the timesheet, and
the balance it came off.*

---

## The rule

**A balance is the sum of things that happened, never a number somebody keeps
up to date.**

Every hour on a staff member's card is a row in `leave_ledger_entries`: earned
by a pay period, spent on a request, restored by a revocation, or typed in by a
director with a reason attached. There is no `vacation_hours` column anywhere,
because the first time it disagreed with the ledger there would be no way to
tell which of the two was right.

The consequence is that "where did my other four hours go" is always answerable,
and the answer is on the teacher's own leave page rather than in a database.

**Approved leave is a fact about the week, not a note about a person.** The
moment a director approves it, three things move: the balance, the published
roster, and the timesheet. Each one is reported back on the screen, because a
director who has just granted a week off needs to know that Tuesday's Toddler
room lost a pair of hands.

---

## Where it lives

**My Leave** in the sidebar → `/leave`

Everybody, including the director. Balances, the request form, the decisions on
past requests, and the statement behind the balance.

**Leave Requests** in the sidebar → `/leave/requests`

Director only, with a count of what is waiting. Approve, deny, revoke.

**Balances & accrual** → `/leave/balances`

Director only. Everybody's balances side by side, the manual adjustment, and the
button that earns a pay period by hand.

---

## Asking

A request is a range, not a list of days: "the week of the 10th" is one decision
and one row. The days it costs are worked out from the range every time they are
needed, which is why a snow day declared in the middle of somebody's holiday
stops consuming their vacation without anybody editing anything.

- Weekends and closure days are never charged for.
- A half day is `hours_per_day = 4` over one date, not a second kind of request.
- Two live requests may not cover the same day — that is a slip, and two
  approvals over one Tuesday would spend the balance for it twice.
- Vacation may not start in the past. Sickness may, up to
  `daycare.leave.backdate_days.SICK` days, because a sick day is routinely filed
  the morning after.

The balance is **not** checked when asking. Asking in March for a holiday in
August that you have not earned yet is a normal thing to do, and refusing it at
the form is how people go back to asking by text message.

---

## Deciding

Approving settles what the balance covers:

| | |
|---|---|
| Balance covers the request | All of it is paid leave, all of it comes off the card |
| Balance covers part of it | The days it stretches to are paid, in date order; the rest is an **approved unpaid absence** |
| Balance covers nothing | Same rule — every day unpaid |

Whole days, always. A card with three and a half days on it pays three of them
and leaves the fourth unpaid rather than paying half of it, because a timesheet
day carries one leave code and a half-paid absence is a conversation nobody
wants to have with a payslip already in their hand. The unspent half-day stays
on the balance.

The shortfall has to be accepted explicitly — a tickbox on the approve form.
Refusing outright would be tidier and wrong: people do take unpaid days, and a
system that cannot record one sends them off the books entirely.

**Nobody approves their own leave.** A director who needs time off is asking the
centre for it like everybody else.

Denied and cancelled requests are kept. "I asked in April and was turned down"
is exactly the thing somebody comes back about, and a deleted row cannot answer
it.

---

## What approving does to the roster

**If the week has not been built yet**, the generator reads approved leave
before it does anything else:

- No shift is placed for that person on that day, and they are not available to
  cover another room either.
- Their weekly target drops by the leave hours — thirty-two, not forty.
- Their remaining hours spread over the days they can actually work, so four
  eight-hour days rather than five six-and-a-half-hour ones.
- The week carries a line saying who is away and by how much their target fell,
  so a ratio gap on Wednesday reads as a shift to cover rather than as a bug.

**If the week is already published**, the approval reaches into it: that
person's shifts on those dates are deleted, and the week keeps a warning saying
so and asking to be regenerated.

Their colleagues' shifts are left exactly as they are. Re-solving the whole week
would rewrite everybody's published hours over one person's day off, and people
have already arranged childcare around them. Regenerating stays the director's
decision.

Revoking does **not** put the shifts back. They were deleted, and re-inventing
them would be a guess at a week that has since moved on — the message says to
regenerate instead.

---

## What approving does to the timesheet

Each day of approved leave is written onto the timesheet under payroll's own
vocabulary, mapped in `daycare.leave.timesheet_codes`:

    VACATION → PTO      SICK → SICK      unpaid days → UNPAID

The days arrive **confirmed**, attributed to the director who approved them. A
director approving a request is a person saying what happened, which is what
confirming a day means; making them retype it on the timesheet as well would be
the rubber stamp that step exists to avoid.

Two things are never written over:

- **A day somebody has already spoken for.** If a teacher punched in on a day
  later granted as sick leave, the punch is a fact and the approval is a
  decision about a different day. The day is left alone and named in a warning.
- **A pay period that has been approved.** The hours have gone to payroll. The
  leave still stands and still costs the balance; it simply cannot reach a
  period people have already been paid on.

Seeding a period brings approved leave in alongside the roster, so a vacation
week does not arrive as five blank days paying nothing.

---

## Accrual

Leave is earned by **approving a pay period**, not by the calendar. Until a
period is approved its hours are still the centre's working notes, and accruing
off a draft would hand somebody sick time for a shift a correction later removed.

| Who | Earns |
|---|---|
| Hourly staff (LEAD, FT, PT, SUB) | One hour per *n* hours **actually worked** — `daycare.leave.accrual.per_hours_worked` |
| Salaried staff (FT\_SALARY) | A flat rate per period — `daycare.leave.accrual.per_period` |

Worked, not paid: leave does not earn leave and neither does a centre holiday.
Salaried staff earn flat because their worked hours are a formality, and
accruing off them would make a balance jump about for no reason anybody could
explain.

Accrual stops at `daycare.leave.cap` rather than overshooting it, and the run
says whose balance was capped — somebody sitting on three weeks of unused
vacation is a fact a director should see.

Posting is idempotent on (person, type, source, reference), so the approve
button, the `leave:accrue` command and an impatient director can all run the
same period in one afternoon and it is paid once. Reopening and re-approving a
period does not pay it twice either.

```bash
php artisan leave:accrue              # the period that has just ended
php artisan leave:accrue 2026-08-20   # any date inside the period you want
```

**The one figure to check before going live:** the sick accrual rate. "One hour
per thirty worked" follows the common statutory formula, but paid sick leave law
is a state matter and that number is not a house rule. It lives in
`config/daycare.php` so changing it is one edit and a code review.

---

## Adjustments

A director can move any balance by hand, and **must** give a reason — the note
is required, unlike everywhere else in the app that a note is offered. A balance
that moved by six hours for no recorded reason is the thing somebody will be
arguing about in November.

This is also how a centre adopting the app starts: staff already have three
weeks of vacation on a paper card, and there has to be a way to say so that is
not a database console.

---

## Trying it

```bash
php artisan db:seed --class=DemoScenarioSeeder   # children — the roster is sized against them
php artisan db:seed --class=StaffSeeder          # eight staff and their rules
php artisan db:seed --class=TimesheetDemoSeeder  # two pay periods, one approved
php artisan db:seed --class=LeaveDemoSeeder      # balances, requests, decisions
```

`TimesheetDemoSeeder` is optional but worth running first: leave is earned by
approving a pay period, so without one every balance is a number somebody typed
rather than one the app worked out. With it, the statement shows both.

Everything is anchored to today, so the future leave is always in the future.
It stages, in one go:

- **Balances that behave differently** — one comfortable, one nearly empty, and
  one hard against the cap, so the amber "at the cap" state and the accrual run
  that reports it are both visible.
- **An approved absence inside a week that is already published**, which is the
  hard half of this feature: the shifts are gone from the roster and the week is
  asking to be regenerated.
- **A request worth more than the balance behind it**, so the queue shows the
  shortfall and approving it is refused until the unpaid tickbox is used.
- **A backdated sick day**, filed the morning after, the way sick leave arrives.
- **A half day**, a denial with a reason, and a request withdrawn by the person
  who asked — all still on their page, because nothing is deleted.
- **A sick day already taken and paid**, on a date chosen rather than fixed: a
  day in a frozen period, or one somebody has already confirmed, is a day the
  timesheet is right to refuse.

Re-runnable. It clears the leave belonging to those staff first and says how
much it cleared, so a second run does not stack. It also reports any day an
approval could **not** reach the timesheet — a punched day and an approved pay
period both outrank a decision made about them afterwards, and a seeder that
stayed quiet about that would stage a demo where leave was granted and payroll
never heard.

---

## Starting over

Leave lives in two tables, and dropping them takes the feature out cleanly:

```bash
php artisan migrate:rollback --step=1
```

Requests and ledger entries both cascade off `users`, so deleting a staff member
takes their leave history with them.
