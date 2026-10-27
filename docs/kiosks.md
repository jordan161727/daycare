# Kiosks — the front door clock and the room tablets

How the two kinds of screen on the wall work together, what each press does
to the records, and a hand-testable script for all of it. This is the build
of HANDOFF §5.1–5.3 (the owner's "StaffFlow" mock-up): one status per
teacher, one PIN on both tablets, ratios counted from where people actually
are.

The companion to [staff-walkthrough.md](staff-walkthrough.md), which covers
the roster and payday. That one is about who is *on*; this one is about
where they are *standing*.

## The idea in one paragraph

The **front door iPad** is the time clock it always was: it makes the
payroll punches. What is new is that a clock-in now asks *which room you are
going to*, and the tile you pick makes the punch and a **room check-in**
together. A **room tablet** hangs in each classroom; keying the same PIN
there says "I am in this room now". Ratios — children present ÷ the room's
ratio from Settings, against the people checked into that room — are counted
from these check-ins and from nothing else. A short room is **warned about
and flagged for the office, never blocked**: the system does not stand in the
way of a person moving.

## One status per teacher (HANDOFF §5.1)

At any moment a member of staff is in exactly one of four states:

| State | Meaning | Counts in a room's ratio? |
|---|---|---|
| **Not on the clock** (`off`) | No IN punch today, or clocked out | no |
| **Working, in a room** (`working_in_room`) | Clocked in, open check-in names room X | **yes, in X** |
| **Working, no room** (`working_no_room`) | Clocked in; the office, kitchen, a meeting | no |
| **On break** (`on_break`) | Clocked in, break punched, remembers the last room | no |

The clock state still comes from the punches (`App\Services\TimeClock`), so
timesheets and payroll see nothing new. The room comes from
`staff_room_checkins`: one open row per working person, closed when they
move, break, clock out, or the centre closes around them
(`App\Services\StaffPresence::status`).

Somebody on the clock by a route that never asked for a room — the app's own
clock, a punch a supervisor typed — is "working, no room" but **not placed**,
and the dashboard reads their room off the roster as it did before the
tablets. A centre without room tablets yet sees the dashboard it always had.

## What is where in the code

| Piece | Where |
|---|---|
| The four-state model, check-ins, ratios, warnings, the feed, the board, closing | `app/Services/StaffPresence.php` |
| The door's actions with rooms; the tablet's actions | `app/Services/StaffKiosk.php` |
| Front door screen | `/clock` · `StaffClockController` · `resources/views/clock/kiosk.blade.php` |
| Room tablet screen | `/room` · `RoomTabletController` · `resources/views/room/kiosk.blade.php` |
| Who's where + flags | `/staff/whos-where` · `StaffPresenceController` · `resources/views/staff/presence.blade.php` |
| Pairing shared by both screens | `app/Http/Controllers/Concerns/PairsStaffDevice.php` |
| Room tablet codes, tablets per room | Settings → Rooms & hours · `CentreSettings::tabletCode()` |
| Device kind and room | Settings → Devices · `staff_devices.kind`, `.classroom` |
| Check-ins | `staff_room_checkins` · `App\Models\StaffRoomCheckin` |
| Activity feed and flags | `kiosk_events` · `App\Models\KioskEvent` |
| Closing-time safety net | `php artisan staff:close-day`, scheduled 15 min after the centre closes |
| Tests | `tests/Feature/DoorClockRoomsTest.php`, `RoomTabletTest.php`, `StaffPresenceBoardTest.php` |

Punches made at a room tablet carry `source = room_tablet`
(`TimePunch::SOURCE_ROOM_TABLET`), so payroll can see which mornings started
at a tablet rather than the door.

## The front door iPad, press by press (HANDOFF §5.2)

1. **Keypad.** "Scan your card" (or "Enter your PIN" when the scanner is off).
   The same errors as before: not recognised, locked, ambiguous, expired,
   stale, unpaired.
2. **Staff card.** Name, a pill — *Not on the clock* / *Working* / *On
   break* — and one sentence that is always about *now*:
   - Working: "On the clock since 8:31 AM · in PreK" (or "· office / no room")
   - On break: "On break since 10:15 AM (15 min) · was in Infant"
   - Off: "Not on the clock today."

   Under it, today's shift and any time off from the Staff page, and in the
   foot "Worked so far". *The staging bug is fixed:* once somebody is off,
   nothing says "On the clock since".
3. **Buttons by state.** Each big button carries a small hint line:
   - Off → **Clock In** (green) · "Next: pick the room you're going to"
   - Working → **Start Break** (amber) · "Checks you out of PreK", and
     **Clock Out** (red) · "End of your day"
   - On break → **End Break** (green) · "Back to Infant", plus the link
     **"Going somewhere else?"**. **RULE:** no clock-out from a break.
4. **Clock In → the room picker.** "Which room are you going to?" Every room
   as a tile, the person's **scheduled room first** (the roster's room for
   this minute, else their home room) tagged *Your schedule*. Each tile says
   "8 children · 1 teacher, need 1" or "No children right now". Below them,
   **Office / no room** — "Admin, kitchen, meetings — not counted in ratios".
   Picking a tile makes the IN punch **and** the check-in in one request.
5. **Start Break.** BREAK_START punch; the person leaves their room. If that
   leaves the room short, the done screen adds an amber line — "PreK now has
   0 teachers for 8 children (needs 1). The office has been told." — and a
   red flag goes on the board. Never blocked.
6. **End Break.** BREAK_END punch, and they go **back to the room the break
   was taken from** automatically (a break taken from the office goes back to
   the office). "Going somewhere else?" opens the picker titled "Where are
   you going after your break?" instead.
7. **Clock Out.** OUT punch; they leave their room; the done screen says the
   hours today and the same short-room warning if it applies.
8. **Done screen.** A tick, a title, a sentence, back to the keypad in six
   seconds.

Everything the screen can be told to do is checked again on the server.
A room that is not one of the centre's is refused as *stale*. A clock-in
that arrives with no room (an older screen) still clocks in — the punch is
the payroll fact and is never held hostage to the room — but places nobody.

## The room tablet, press by press (HANDOFF §5.3)

The header says **Room tablet · PreK** on every screen, so a tablet in the
wrong room is obvious from the door.

1. **Keypad.** "Coming into PreK?" · "Same PIN as the front door".
2. **After the PIN:**
   - **Working elsewhere, or in the office** → a big green **Check into
     PreK** ("Checks you out of Toddler · 8 children · 1 teacher, need 1"),
     then *Or another room* tiles. A tap makes the check-in; the previous
     room ends as a move. A move is **no punch** — payroll is untouched.
   - **Already in this room** → "You're already checked into PreK", plus
     the other rooms.
   - **On a break** → the banner "You're on a break. Picking a room **ends
     your break**." **RULE:** the tap writes the BREAK_END punch (marked
     `room_tablet`) and checks them in.
   - **Not clocked in** → **RULE (the owner's "auto clock-in"):** they are
     clocked in there and then (IN punch, `source = room_tablet`), checked
     into this room, and the screen stops at "Clocked in for you · You
     weren't clocked in, so we clocked you in at 10:32 AM and checked you
     into PreK. Next time, start at the front door." A red flag goes to the
     office: "Sultana skipped the front door — office to confirm start time".
   - **Taking a break** (amber) is Start Break at the door under another
     name. Its done screen says the break ends "at the front door or by
     tapping into any room".
   - There is **no Office / no room tile** — that is done at the door.
   - "Clock out at the front door." **RULE:** a tablet cannot clock anyone
     out; the action is refused as stale.
3. **The gear (⚙) → Set up this tablet.** "Key the 4-digit room code. Right
   now this tablet is PreK." A correct code switches the room at once —
   "This tablet is now UPK-4" — and writes a grey line in the feed, "Room
   tablet set to UPK-4 (was PreK)". A wrong code says "That's not a room
   code".

   **RULES:** codes are four digits, one room per code, set in Settings →
   Rooms & hours (directors only), and **never equal to a staff PIN** — the
   save refuses it. The tablet has to be a paired device; a random browser
   at `/room` is told to see an administrator. The code only chooses the
   room; it records nothing.

## Who's where (HANDOFF §5.3.1)

`/staff/whos-where` (director only; also linked from the Devices page) is the
right-hand column of the mock-up:

- **Room by room:** who is checked in and since when, and the pill
  "1 teacher · need 2 · 7 kids" — red when short, green when covered, grey
  when there are no children.
- **On break:** who, since when, from which room. **Office / no room.**
- **Not clocked in:** why — "Due 10:45 AM"; "Late · due 9:00 AM" (red, more
  than five minutes past the start); "Time off until 3:00 PM" or "Off today"
  (blue — **RULE:** time off is never late); "Not scheduled"; "Clocked out
  4:02 PM".
- **For the office:** the open flags, each with a *Seen to* button. A flag
  seen to stays in the feed, struck through.
- **Activity**, newest first: punches (blue), room moves (the room's colour),
  flags (red), tablet set-ups (grey).

The dashboard's ratio cards read the same service, so they now count only
people checked into a room (the office counts nowhere), with the roster as
the fallback for anybody never placed.

## The closing-time safety net (HANDOFF §5.3.2)

`staff:close-day` runs fifteen minutes after the centre's closing time from
Settings. Anyone still on the clock is flagged — "Lana still on the clock at
closing — office to fix the day" — and taken out of their room, so tomorrow's
ratios do not open with tonight's people in them. **No clock-out punch is
invented.** The day stays open for the director to fix in Staff attendance,
exactly as a forgotten clock-out always has.

---

## Hand test

### Setting up

```bash
php artisan migrate
php artisan db:seed --class=KioskStaffDemoSeeder  # Lana 3333 (PreK), Sultana 4444 (Toddler), Evana 5555 (Infant), Lana's shift today
npm run build
```

`DAYCARE_KIOSK=true` in `.env`. If a `public/hot` file is left over from an
`npm run dev` that is no longer running, delete it: while it is there every
page asks `localhost:5173` for its CSS and JavaScript, and the kiosks load
as dead screens with nothing to press.

(This line used to say `StaffDemoSeeder`. That one fills in staff details,
rules and time off, and sets no PINs, so the test could not start.)

Log in as a director and:

1. **Settings → Rooms & hours.** Key a *Room tablet code* for PreK (`2104`)
   and UPK-4 (`2105`). Save.
   - [ ] Trying a code that is somebody's PIN is refused: "A room code cannot
     be the same as a staff PIN."
   - [ ] Trying the same code on two rooms is refused.
   - [ ] Each room shows "No tablet yet".
2. **Settings → Devices.** Add *Front desk* as a **Front door clock**. Add
   *PreK iPad* as a **Room tablet** in PreK. Open each pairing link in its own
   browser profile (or two browsers): the clock link lands on `/clock`, the
   tablet link on `/room` with **Room tablet · PreK** in the header.
   - [ ] Rooms & hours now says "1 tablet · never used" against PreK.
3. **Staff.** The seeder above made Lana (3333, home room PreK), Sultana
   (4444, Toddler) and Evana (5555, Infant), and gave Lana a shift today in
   PreK so *Your schedule* comes first. If it warned that a PIN was already
   somebody else's, give that teacher another on the Staff page.
4. **Director Attendance / Teacher Attendance.** Sign in eight PreK children
   and four Toddler children, so the rooms have ratios to be short of.

### The door

5. On the clock, key **3333**.
   - [ ] Pill *Not on the clock*; line "Not on the clock today."; one green
     button **Clock In** with "Next: pick the room you're going to".
6. Tap **Clock In**.
   - [ ] "Which room are you going to?" PreK is first with *Your schedule*;
     its tile says "8 children · 0 teachers, need 2" (at 1:7).
   - [ ] An **Office / no room** tile sits under the rooms.
7. Tap **PreK**.
   - [ ] "You're clocked in" · "Checked into PreK at …". Back to the keypad
     on its own.
   - [ ] `/staff/whos-where`: PreK shows Lana since now, pill green
     "1 teacher · need 2 · 8 kids" is **red** (one short). Activity: two lines,
     a blue punch and a green room move.
8. Key **3333** again.
   - [ ] Line "On the clock since … · in PreK"; **Start Break** says
     "Checks you out of PreK"; **Clock Out** says "End of your day".
9. Tap **Start Break**.
   - [ ] "Enjoy your break", and under it the amber line "PreK now has 0
     teachers for 8 children (needs 2). The office has been told."
   - [ ] Who's where: Lana under **On break** "since … · from PreK"; a red
     flag "Ratio: PreK short after Lana … left" under *For the office*.
10. Key **3333** again.
    - [ ] Only **End Break** (green, "Back to PreK") and the link
      **Going somewhere else?** — no Clock Out.
11. Tap **End Break**.
    - [ ] "Welcome back · Break ended. Checked into PreK at …". Who's where
      puts Lana back in PreK.
12. Key **3333**, **Start Break**, key **3333**, tap **Going somewhere
    else?**, pick **Toddler**.
    - [ ] Picker is titled "Where are you going after your break?". Lana is
      now in Toddler.
13. Key **3333**, **Clock Out**.
    - [ ] "See you tomorrow · Clocked out at … · Nh NNm today", with the
      Toddler short-room warning.
    - [ ] Key **3333** once more: the card says "Not on the clock today." and
      nothing about "since". Clock in again into Infant: the line reads
      "On the clock since … · in Infant".

### The room tablet

14. On the PreK tablet, key **4444** (Sultana, not clocked in).
    - [ ] "Clocked in for you · You weren't clocked in, so we clocked you in
      at … and checked you into PreK. Next time, start at the front door."
    - [ ] Who's where: Sultana in PreK; a red flag "Sultana … skipped the
      front door — office to confirm start time"; the punch line reads
      "clocked in (auto, PreK tablet)".
    - [ ] Employee Timesheets: Sultana's IN punch is there, source
      *room_tablet*.
15. At the door, clock Evana (**5555**) into Infant, then **Start Break**.
    On the tablet key **5555**.
    - [ ] "Hi Evana", the amber banner "You're on a break. Picking a room
      **ends your break**.", the line "On break since … · was in Infant",
      the big button says "Ends your break · …", and **Taking a break** is
      not offered.
16. Tap **Check into PreK**.
    - [ ] "You're in PreK · Break ended. Checked into PreK at …". Who's where:
      Evana in PreK, a blue "ended break (N min) · PreK tablet" line.
17. Key **5555** again.
    - [ ] "You're already checked into PreK." PreK is not among the tiles;
      the other rooms are. Tap **Toddler**: "Checked out of PreK. Checked
      into Toddler at …" — and no new punch on her timesheet.
18. Key **5555**, tap **Taking a break**.
    - [ ] "Enjoy your break · Break started at … End it at the front door or
      by tapping into any room." plus the Toddler warning if it is short.
19. Look for a way to clock out from the tablet.
    - [ ] There is none; the foot says "Clock out at the front door." There
      is no Office tile.
20. Tap **⚙**. Key **0000**.
    - [ ] "That's not a room code." Key **2105**.
    - [ ] "This tablet is now UPK-4"; the header says **Room tablet · UPK-4**;
      the keypad reads "Coming into UPK-4?". Who's where has a grey line
      "Room tablet set to UPK-4 (was PreK)". Rooms & hours now counts the
      tablet against UPK-4.
21. Open `/room` in a browser that was never paired.
    - [ ] "This tablet is not set up yet"; a PIN and a code are both
      refused.

### Closing

22. Leave somebody on the clock and run
    `php artisan staff:close-day`.
    - [ ] "1 still on the clock at closing — flagged for the office." Who's
      where: a red flag for them, nobody in any room; Employee Timesheets
      still shows the day open with no OUT punch.
23. **Seen to.** Press it on a flag.
    - [ ] It leaves *For the office* and stays in Activity, struck through.

### The automated version

```bash
php artisan test --filter "KioskWalkthroughTest|DoorClockRoomsTest|RoomTabletTest|StaffPresenceBoardTest|StaffClockKioskTest"
```

`KioskWalkthroughTest` is this hand test, step for step, driven through the
real routes against a fresh database and printing the tick list as it goes.
The three suites beside it test each **RULE** on its own; the clock's own
twenty-three tests still pass.

**In a real browser — 28 Oct 2026.** The same steps were also pressed
through the actual screens in headless Chrome, a front door and a PreK
tablet as two separate browsers, against a throwaway database set up with
`KioskStaffDemoSeeder`. Everything held, with no JavaScript errors once two
were fixed: the clock's problem banner read `problem.kind` while there was
no problem, which threw on every load, and both headers showed the
*device's* time instead of the centre's. An untouched card goes back to
the keypad after 45 seconds, as the ticket does.

**Last run — 28 Oct 2026, headless.** All 23 steps passed. The run caught
one thing the unit tests had encoded wrongly: after a clock-out and a second
clock-in, the card said "On the clock since" the *morning's* punch. It now
reads the latest clock-in, which is what §5.2's "always the current shift"
asks for.

## Assumptions made (HANDOFF §13 defaults, and a few of our own)

- **Auto clock-in** at a tablet (default 4), with the flag.
- **Never block** a break or a clock-out for a short room (default 5).
- Ratios are computed from **children signed in and not yet out** on the
  register, against the room's ratio from Settings; `required =
  ceil(children ÷ ratio)`, zero for an empty room.
- A room tablet cannot put somebody in the **office**; the door can.
- "Late" is more than **five minutes** past the shift's start.
- The closing job runs **fifteen minutes after** closing, so a 6:00 PM
  clock-out is not flagged by a 6:00 PM job.
- Room codes are stored **plain** in Settings (directors read them back to
  hand to whoever moves a tablet); they choose a room and nothing else.
- The pairing cookie is shared by both screens: a tablet paired once is
  paired for whichever page its kind sends it to.
- **Kiosk-mode exit with a director passcode** (§5.3, last bullet) is not
  built: the tablets are plain web pages, and locking the browser into them
  is the tablet's own guided-access setting.
- The flags are on the Who's where page. Putting them on the dashboard, and
  the floor plan reading check-ins, is Phase 5 (Live views).
