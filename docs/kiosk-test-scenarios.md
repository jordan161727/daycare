# Kiosk test scenarios

Hand test for the front door clock (`/clock`), the room tablet (`/room`) and
the Kiosks page (sidebar → **Kiosks**), as they stand on 29 Oct 2026. How the
screens work is in [kiosks.md](kiosks.md).

## Setup (once)

- `.env` has `DAYCARE_KIOSK=true`. Staging is seeded with `StagingDemoSeeder`:

  ```
  php artisan migrate --force
  php artisan db:seed --class=StagingDemoSeeder --force
  ```

- Logins (the seed sets them; the room teachers come from `ClassroomTeacherSeeder`, its last step):

  | Account | Email | Password |
  |---|---|---|
  | Director (sees pay) | `director@daycare.test` | `password` |
  | Office admin | `admin@daycare.test` | `password` |
  | Infant teacher | `infant.teacher@daycare.test` | `Infant#2026` |
  | Transition teacher | `transition.teacher@daycare.test` | `Transition#2026` |
  | Toddler teacher | `toddler.teacher@daycare.test` | `Toddler#2026` |
  | PreK teacher | `prek.teacher@daycare.test` | `PreK#2026` |
  | UPK-4 teacher | `upk4.teacher@daycare.test` | `UPK4#2026` |
  | School Age teacher | `schoolage.teacher@daycare.test` | `SchoolAge#2026` |

- As **director@daycare.test**, go to Settings → Rooms & hours and set these room codes:
  - **PreK 2104**
  - **UPK-4 2105**
  - **Toddler 2107**
- On the Staff page, give these PINs:
  - **Lana 333333** (home room PreK, rostered today)
  - **Sultana 444444** (not clocked in)
  - **Evana 555555**
- Make sure PreK has children signed in today, so the ratios have something to count.
- Use two browsers or devices:
  - **Tablet A:** the front door, `/clock`
  - **Tablet B:** the room tablet, `/room`
- Keep the **Kiosks** page open on a laptop to watch the results.

## 1. Front door clock (`/clock`): the screen

| # | Steps | Expected | Pass? |
|---|---|---|---|
| FD-1 | Open `/clock` signed out | Opens straight away, with no "not set up" message. Dark header: **Staff time clock** / screen name / time. **Good morning/afternoon/evening**, "Scan your card or key your 6-digit PIN", 6 empty dots, keypad. **No Enter button.** | |
| FD-2 | Press 1, 2 | Two dots fill. | |
| FD-3 | Press ⌫ | One dot left. | |
| FD-4 | Press Clear | All dots empty. | |
| FD-5 | Tap the screen on an iPad | The iPad's own keyboard does **not** pop up. | |

## 2. Front door: wrong or odd PINs

| # | Steps | Expected | Pass? |
|---|---|---|---|
| FD-6 | Key 9999 (nobody's PIN) | "Not recognized"; the dots clear. | |
| FD-7 | Key the same wrong PIN 5 times | That number is locked. The message looks the same as for an unknown PIN, so it gives nothing away. | |
| FD-8 | Key Lana's PIN, then wait 45+ seconds before pressing a button | "That took too long — scan again"; nothing is recorded. | |
| FD-9 | Key three digits and wait 15 seconds | The dots clear on their own (QA K3, 16 Nov 2026). Two staff can no longer share a PIN (QA K2). | |
| FD-10 | (Card scanner) Scan a staff card | Accepted with no tap needed. | |

## 3. Front door: a full day

| # | Steps | Expected | Pass? |
|---|---|---|---|
| FD-11 | Lana (off) keys 333333 | Card: Lana · **Not on the clock** · "Not on the clock today." · today's shift · one green **Clock In** button ("Next: pick the room you're going to"). | |
| FD-12 | Tap Clock In | **Which room are you going to?** PreK is first, tagged *Your schedule*. Each tile shows "N children · N teacher, need N". **Office / no room** is at the bottom. | |
| FD-13 | Pick PreK | Done screen, then back to the keypad in about 6 seconds. Kiosks page: Lana under **PreK** "since [now]"; the feed shows "Lana clocked in (front door)" and "Lana → PreK". | |
| FD-14 | Lana keys 333333 again | Pill **Working** · "On the clock since … · in PreK". Buttons: **Start Break** ("Checks you out of PreK") and **Clock Out** ("End of your day"). No Clock In. | |
| FD-15 | Tap Start Break (with Lana the only teacher in PreK) | Done screen with an amber line: "PreK now has 0 teachers for N children (needs N). The office has been told." Kiosks page: Lana under **On break** "Since … · from PreK"; a red **For the office** line; PreK's pill turns red. | |
| FD-16 | Lana keys 333333 while on break | Pill **On break** · "On break since … (N min) · was in PreK". Only **End Break** ("Back to PreK") and the link **Going somewhere else?** **No Clock Out button.** | |
| FD-17 | Tap End Break | Back in **PreK** automatically, without being asked. | |
| FD-18 | Start break again → key 333333 → **Going somewhere else?** → pick Toddler | The picker reads "Where are you going after your break?". She ends up in Toddler, not PreK. | |
| FD-19 | Lana keys 333333 → **Clock Out** | Done screen with today's hours. Kiosks page: Lana gone from the rooms, now under Not clocked in ("Clocked out …"). | |
| FD-20 | Clock In → pick **Office / no room** | Kiosks page: Lana under **Office / no room**, and not counted in any room's ratio. | |

## 4. Room tablet (`/room`): set-up

| # | Steps | Expected | Pass? |
|---|---|---|---|
| RT-1 | Open `/room` in a new browser | Header: **Little Angels** / "Room tablet · **no room yet**" / time / ⚙. "Coming into this room?" plus an amber note telling you to tap ⚙ and key the room code. | |
| RT-2 | Tap ⚙ | **Set up this tablet** · "An administrator’s PIN first." · 6 dots · keypad · **Cancel**. A teacher's PIN: "Ask a director". A director's PIN: "Key the 4-digit room code. Right now this tablet is no room." (QA K4, 16 Nov 2026) | |
| RT-3 | Tap Cancel | Back to the keypad, nothing changed. | |
| RT-4 | ⚙ → director's PIN → key 0000 | "That's not a room code." | |
| RT-5 | ⚙ → director's PIN → key 2104 | "This tablet is now PreK". The header reads **Room tablet · PreK**, and the screen returns to **Coming into PreK?** with no Enter button. | |
| RT-6 | ⚙ → director's PIN → key 2105 | "This tablet is now UPK-4"; the Kiosks feed shows a grey line "Room tablet set to UPK-4 (was PreK)". Set it back to PreK with 2104. | |
| RT-7 | Reload the page / close and reopen the browser | Still PreK, with no setup needed. | |

## 5. Room tablet: people

| # | Steps | Expected | Pass? |
|---|---|---|---|
| RT-8 | Lana (working, in Toddler) keys 333333 | Big green **Check into PreK** ("Checks you out of Toddler · …"), plus other room tiles. **No Office tile, no Clock Out.** | |
| RT-9 | Tap Check into PreK | She moves to PreK on the Kiosks page. **No new punch** in Staff attendance; payroll is untouched. | |
| RT-10 | Lana keys 333333 again | "You're already checked into PreK", plus the other rooms. | |
| RT-11 | Lana keys 333333 → **Taking a break** | The break starts; the done screen says it ends "at the front door or by tapping into any room". | |
| RT-12 | Lana (on break) keys 333333 | Banner: "You're on a break. Picking a room **ends your break**." Picking PreK ends the break and checks her in. | |
| RT-13 | Sultana (**not clocked in**) keys 444444 | **Clocked in for you** · "…we clocked you in at [time] and checked you into PreK. Next time, start at the front door." Kiosks page: a red line "Sultana skipped the front door — office to confirm start time". Staff attendance shows her IN punch from the room tablet. | |
| RT-14 | Wrong PIN, and 5 wrong tries | Same messages and lockout as the front door. | |

## 6. Kiosks page (watch while testing)

| # | Steps | Expected | Pass? |
|---|---|---|---|
| WW-1 | Sidebar → **Kiosks** (Director) | Four counts across the top: Children in rooms, Teachers in rooms, On break, Rooms open "N of 6". The room tablet is framed on the left; Who's where is on the right. | |
| WW-2 | Do any punch on Tablet A or B | The counts and Who's where update within about 5 seconds, without reloading the page. | |
| WW-3 | Key 2 digits on the **framed** tablet, then wait 10 seconds | The digits are still there; the refresh doesn't touch the tablet. | |
| WW-4 | Click **Seen to** on a red office line | The line goes from For the office, and the feed shows it struck through. | |
| WW-5 | Click **Full screen ↗** | `/room` opens in its own tab. | |
| WW-6 | Sign in as a teacher (e.g. `prek.teacher@daycare.test` / `PreK#2026`) | No Kiosks button; `/staff/whos-where` is refused. | |

## 7. Sign-in page and set-up

| # | Steps | Expected | Pass? |
|---|---|---|---|
| SE-1 | Login page | One button: **Staff time clock**. No Room tablet button. | |
| SE-2 | Settings | No **Devices** tab; `/settings/devices` and `/devices` show "page not found". | |
| SE-3 | Open `/clock` and `/room` in the **same** browser, then set the room on `/room` | Both keep working; the clock isn't affected by the room. | |
| SE-4 | Settings → Rooms & hours: give two rooms the same code | Refused: "That code is already PreK’s. Each room needs its own code." (Codes are 4 digits and PINs 6, so a code can never be a PIN.) | |
| SE-5 | Set the same code on two rooms | Refused. | |
| SE-6 | Turn `DAYCARE_KIOSK` off | `/clock` and `/room` show "page not found", and the login page has no Staff time clock button. | |

## 8. Closing time

| # | Steps | Expected | Pass? |
|---|---|---|---|
| CT-1 | Leave Lana clocked in, then run `php artisan staff:close-day` after closing time | A red line "Lana still on the clock at closing — office to fix the day". She's taken out of her room. **No clock-out punch is made**; the director fixes the day in Staff attendance. | |
