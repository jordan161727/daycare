# The activity log

Ray, 28 Oct 2026: "Can you make everything logged? So every time someone
does something it is logged. For example, 11:00 AM = Farhana checked Saroya
in @ 11:00 AM; 11:05 AM = Patrick modified Evana check in time for Sept 15
from Nothing to 10:00 AM."

## Where it is

Sidebar → Staff → **Activity** (`/staff/activity`). Director only. One day
at a time, newest line first, with a person filter and a search box.

## How a line gets written

`App\Services\ActivityLog` listens to the `created`, `updated` and `deleted`
events of the records that matter and writes one sentence per change to
the `activity_log` table (`App\Models\Activity`). Because it hangs off the
models rather than the screens, a check-in from Teacher Attendance, the
director's week grid, the DSS sheet, the door kiosk or a console command
all leave the same kind of line. There is no way to save one of these
records without a line.

The actor is whoever is signed in. A kiosk has nobody signed in, so it is
named by the screen ("Front door", "Time clock", "Room tablet"); a
scheduled command is "System". The actor's name is frozen in the line, so
it still says Patrick after his account is renamed or removed.

A describer that cannot put a change into words writes nothing; one that
throws is reported and never stops the save it was describing.

## What is logged, and how it reads

| Record | Example line |
|---|---|
| Child attendance | Farhana Noor checked Saroya Khan in @ 11:00 AM |
| | Patrick Hale modified Saroya Khan's check-in time for Sep 15 from 9:00 AM to 10:00 AM |
| | Patrick Hale entered Saroya Khan's times for Sep 15: 8:00 AM–5:00 PM |
| | Patrick Hale removed Saroya Khan's attendance for Sep 15 (was 10:15 AM–4:00 PM) |
| Absent / not scheduled marks | Patrick Hale marked Saroya Khan absent for Sep 16 |
| Staff clock punches | Farhana Noor clocked in @ 7:58 AM |
| | Patrick Hale modified Farhana Noor's clock in from 11:00 AM to 10:50 AM — Forgot to punch |
| | Patrick Hale added a clock out for Farhana Noor @ 5:00 PM on Oct 27 — Forgot to clock out |
| Room check-ins | Lana Ortiz checked into PreK · Lana Ortiz left PreK for a break |
| Child records | Patrick Hale added Evana Roy (LAN 10112) |
| | Patrick Hale changed Saroya Khan's status from Active to Withdrawn |
| Child bookings (schedule) | Patrick Hale booked Saroya Khan for Nov 3 (AM) |
| Child notes | Patrick Hale added an office note on Saroya Khan |
| Staff records | Patrick Hale changed Farhana Noor's phone from Nothing to 555-0100, password |
| Staff shifts | Patrick Hale scheduled Farhana Noor on Nov 3 8:00 AM–4:00 PM in PreK |
| Time off and leave | Patrick Hale gave Farhana Noor time off Nov 3–Nov 5 (Out all day) · Patrick Hale approved Farhana Noor's leave Nov 3–Nov 5 |
| Settings | Patrick Hale changed rooms.PreK.ratio from 7 to 8 |
| Closures | Patrick Hale closed the centre on Nov 27 (Thanksgiving) |
| Messages | Patrick Hale sent a message in “PreK families” |
| Sign-ins | Patrick Hale signed in · Failed sign-in for someone@example.com |
| Kiosk flags and tablet set-up | Flag for the office: Sultana skipped the front door — office to confirm start time |

Passwords, PINs and tokens are named as changed, never with their values.
Housekeeping columns (timestamps, "updated by", the room override the
child record writes for itself) are not lines of their own.

Times read `10:00 AM`, dates `Sep 15` (with the year only when it is not
this year), an empty value is `Nothing` — Ray's own words.

## Tests

`tests/Feature/ActivityLogTest.php` — a check-in and check-out in the office
words; a fixed time says from what to what; punches and their corrections;
record and setting changes name the field and both values; nobody signed
in is still named; the page is the director's and reads a day at a time.
