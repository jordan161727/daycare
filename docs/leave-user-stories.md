# User stories — leave

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
