# Copy the schedule from another week

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
