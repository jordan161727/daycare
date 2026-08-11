# Walkthrough — a week in the life of the centre

A hand-testable script for the attendance sheet. Every step says what to do and
what you should see, so a wrong result is obvious rather than a matter of taste.

## Setting up

```bash
php artisan migrate:fresh --seed          # empty centre: users, teachers, rooms
php artisan db:seed --class=DemoScenarioSeeder
```

Log in as `admin@daycare.test` / `password`, then open **Attendance**.

Teacher logins exist too — `toddler.teacher@daycare.test` and friends, same
password. They see only their own room, which matters for story 9.

### The roster you get

13 children, chosen so that every state on the sheet appears somewhere:

| Child | Room | Usual days | Hours | Why they are here |
|---|---|---|---|---|
| Mia Alvarez | Infant | Mon–Fri | 45 | plain full-week child |
| **Noah Bennett** | Infant | Mon Wed Fri | **36** | contracted for four days, comes three — **9 h short** every week |
| Ava Cruz | Toddler | Mon–Fri | 45 | |
| Liam Diaz | Toddler | Tue Thu | 18 | |
| Ella Foster | Transition | Mon–Fri | 45 | |
| Owen Grant | PreK | Mon Wed Fri | 27 | |
| Sofia Hayes | PreK | Mon–Fri | 45 | |
| **Jack Ibarra** | UPK-4 | Tue Thu | **—** | no hours agreed: days reported, no target claimed |
| **Ruby Kim** | School Age | Mon–Fri | 45 | splits into **AM and PM** boxes |
| **Ethan Lopez** | School Age | Mon Wed Fri | 27 | AM/PM, part week |
| **Nora Patel** | Toddler | — | 45 | **starts Wednesday** — Mon/Tue are blank, and **hours with no pattern** behind them |
| **Caleb Reyes** | PreK | Mon–Fri | 45 | **leaves next Tuesday** |
| **Iris Tan** | Toddler | — | — | **inactive** — should never appear at all |

Three weeks are staged:

- **Last week** — finished, signed in, and therefore **locked**.
- **This week** — live. Copied forward from last week. Monday is part signed in.
- **Next week** — deliberately *not* opened. Story 3 is where you open it.

---

## The stories

### 1. Monday morning: sign the children in

Open **Attendance**. You are on this week, on the **Sign in** view.

- Monday shows **11 children scheduled**, of whom **5 are already signed in**
  (green, with an arrival time). Six are still pale indigo.
- Click a pale indigo box for Monday.

**Expect:** it turns green instantly with the current time, the *present* counter
at the top goes up by one, and the child appears at the top of *Recent sign-ins*.

### 2. A child turns up on a day they were not scheduled

Liam Diaz is Tue/Thu, so his Monday box is **gray**.

- Click Liam's gray Monday box.

**Expect:** it is accepted and turns **amber**, not green. Amber means *signed in
but not scheduled* — the day is still billable, and the colour is telling you it
was unplanned. This is the DSS rule: bill what happened, not what was planned.

### 3. Open next week and watch it copy forward

- Press **Next ›** at the top.

**Expect:** the week builds itself as a duplicate of this week — same children,
same ticked days, **no sign-ins carried over**. The blue strip reads *"Schedule
copied from … and independent since"*.

Two things to check while you are here:

- **Caleb Reyes** leaves on the Tuesday. His Wed/Thu/Fri boxes show **—**
  (not enrolled), not gray.
- **Nora Patel** started mid-week, so she now has a full row of boxes — all
  unticked. A new child is never silently scheduled; you tick her in by hand.

### 4. Set a child's week in one move

Switch to **Set schedule**.

- On Nora Patel's row, press **Full week**. Then press **T Th** on someone else.

**Expect:** the row fills or empties immediately, and the counter at the bottom
(*"N of M possible days ticked"*) moves with it. Nothing is saved on a button —
it saves as you go.

Also try **dragging** across several boxes, and pressing **Space** on a focused
box.

### 5. Set a whole day for everyone

- Still in **Set schedule**, click the **day name** at the top of a column.

**Expect:** the entire column ticks. Click again and it clears. This is one day
for every child in one move.

### 6. A snow day

- Click **Close day** under any column header. Give a reason — *Snow day*.

**Expect:** every box in that column goes gray at once, in every room, and the
column is stamped with the reason. Ticking anything in it is refused.

Then check two things:

- Go to the **Sign in** view. The closed column is marked, but a box there
  **still accepts a sign-in** — if a child turns up on a snow day, it is recorded.
- Press **Next ›**. The same weekday in the following week is **normal**. A
  closure belongs to one date, not to every Wednesday forever.

### 7. Rebuild a week from a better one

Suppose the week you are on was full of holidays and is not worth correcting
box by box.

- Press **Copy from another week**, pick a week, and leave **Add to this week**
  selected.

**Expect:** a green banner — *"Copied from … N day(s) added — M now ticked this
week."* Days already ticked here survive; the source week's days join them.

Now do it again with **Replace this week** selected.

**Expect:** the week becomes an exact match of the source, and days ticked here
but not there are cleared.

Now try **Add** with *last week* as the source, straight after the demo is seeded.

**Expect:** an amber banner — *"Nothing changed — this week already has every day
the week of … does."* That is correct, not a failure: this week was **born as a
copy of last week** when it was first opened, so there is nothing left to add.
Untick a few days first, or use **Replace**, to see it move.

Copy an *empty* week deliberately.

**Expect:** a different amber banner — *"Nothing to copy — the week of … has no
days ticked."* A copy that changed nothing never claims success.

### 8. Last week is closed for edits

- Press **‹ Prev** twice, back to last week.

**Expect:** *"🔒 This week has ended — the schedule is locked."* The **Set
schedule** toggle and both copy buttons are gone. The sign-ins recorded that week
are still there, and still readable.

### 9. A teacher sees only their room

- Log out, log in as `toddler.teacher@daycare.test`.

**Expect:** Ava, Liam and Nora only. Iris Tan is absent — she is inactive, and
inactive children never reach the sheet for anyone.

### 10. Only today can be signed in

- As anyone, click a sign-in box on a day that is not today.

**Expect:** a pop-up in the centre of the screen: *"Only Monday, Aug 10 can be
signed in. Tuesday, Aug 11 is closed — use 'Copy from another week' to fill a
past week."* Nothing is recorded.

### 11. Moving a child up early

The room under each name is worked out from the date of birth. Ella Foster is 21
months old, which puts her in Transition. Say she is ready for Toddler now.

- As the admin, open **Set schedule** and click **Transition** under Ella's name.
- Pick **Toddler**, leave the date as today, **Save**.

**Expect:** the room reads **Toddler in violet with a ✎**, where every automatic
room is plain gray. Hovering says *"Set by hand, from <today>. Automatic:
Transition."* Switch to the sign-in view and it is violet there too.

- Reload the page.

**Expect:** still Toddler. Nothing recalculates over the top of it — that is the
point of an override.

- Open the room again and choose **Clear override**.

**Expect:** back to Transition, plain gray.

Two things worth trying while you are here:

- Set an override dated **next Monday**. The room does not change today; it
  changes when that date arrives.
- Try to date one **inside last week**. It is refused — that week is finished,
  and its room counts are the record of the ratio that had to be staffed.

### 12. What next week is going to look like

Every child on the demo roster carries **expected hours a week** — what the
family contracted for. Most match their pattern; two deliberately do not.

- As the admin, step to **next week** with **Next ›**.

**Expect:** a sky-blue **Projected** strip under the week header: a head count
per day, the projected hours, and *"against … h expected"* with the gap in amber
if the two disagree. The forecast is built from **last week's actual sign-ins**,
not from the ticks.

- Switch to **Set schedule** and read the **Projected** column beside **Days**.

**Expect:** Noah Bennett reads `3d · 27.0 h / 36.0 h` in amber — contracted for
four days, only ever here three. Ruby Kim reads `5d · 40.5 h / 45.0 h`: she is
School Age, so her days are half-day sessions and one of the ten is missing.
Jack Ibarra has no hours agreed, so his row shows days and hours and no target.
Nora Patel is amber for a different reason: hours on file and nothing behind them
yet, so the projection names her rather than guessing which days she will come.

Days of this week that **have not happened yet** are not counted as absences —
they fall back to what is ticked. Otherwise planning next week on a Tuesday would
forecast an empty Thursday for the whole centre.

- Look for boxes with a **sky ring**.

**Expect:** those are the days the forecast and the schedule disagree about —
expected but not ticked, or ticked but not expected. Hovering says which.

- Open **Copy from another week** and press **Fill from projection**.

**Expect:** the week is ticked from the forecast — Add keeps what was already
there, Replace makes it an exact match — and the banner says how many days moved.
Children with hours but no pattern are untouched and counted in the message.

- Now open a child's record and change **Expected hours a week**, then come back.

**Expect:** the strip and the column have followed it. Nothing is stored and
nothing is rebuilt; the forecast is worked out again on every load.

---

## The four box states, in one place

| Box | Meaning |
|---|---|
| **Indigo** | scheduled to attend, not yet signed in |
| **Gray** | not scheduled — but still clickable, and still billable |
| **—** (dashed) | not enrolled: not started yet, or no longer coming |
| **Green** | signed in, with the arrival time |
| **Amber** | signed in on a day they were *not* scheduled |

## Starting over

`php artisan migrate:fresh --seed && php artisan db:seed --class=DemoScenarioSeeder`
rebuilds the whole thing from scratch, at whatever date you run it.
