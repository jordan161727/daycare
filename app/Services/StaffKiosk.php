<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\Setting;
use App\Models\StaffDevice;
use App\Models\StaffRoomCheckin;
use App\Http\Controllers\SettingController;
use App\Models\TimePunch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Turning a card under a scanner, or four digits on a screen, into a punch.
 *
 * The screen is in a lobby with nobody signed in at it, so it shows one person
 * at a time and only the things that person can actually do. Three states —
 * clocked out, clocked in, on a break — and one set of buttons each. A tile
 * that is impossible is not greyed out, it is absent, because a person at half
 * past seven with a coat on and a child on their hip reads what is on screen
 * and taps it.
 *
 * There is no path from a break straight to clocked out. A break ends back on
 * the clock, so leaving for the day is End Break then Clock Out — two presses
 * that are two true facts, rather than one the timesheet has to guess at.
 *
 * What it does not do is decide the arithmetic. The punch goes into the same
 * table the employee's own clock writes to, through the same TimeClock, so the
 * timesheet, the exceptions and payroll see a kiosk punch as one more punch.
 * A second way of recording hours would be a second set of numbers to
 * reconcile.
 */
class StaffKiosk
{
    public const METHOD_CARD = 'card';

    public const METHOD_PIN = 'pin';

    public function __construct(private TimeClock $clock, private StaffPresence $presence) {}

    /**
     * Who is standing there, or why the clock will not say.
     *
     * Four answers, and the difference between them is the design. "Not found"
     * and "locked" read almost alike on screen — somebody at a lobby screen
     * should not learn from the wording whether a number is in use — but the
     * caller is told apart so the lockout can be counted.
     *
     * @return array{status: string, user?: User, method?: string}
     */
    public function identify(?string $card, ?string $pin): array
    {
        $found = $this->look($card, $pin);

        // Written down whichever way it went. A card presented at a lobby
        // screen is somebody naming themselves, and the failures are the
        // reason the record is worth keeping — five wrong PINs against one
        // number is the thing a director wants to be able to see afterwards.
        $this->record($found, filled($card) ? self::METHOD_CARD : self::METHOD_PIN);

        return $found;
    }

    /** The lookup itself, with nothing recorded. */
    private function look(?string $card, ?string $pin): array
    {
        // Enforced here rather than only in the screen's wording: turning the
        // scanner off has to stop a card working, or a centre that switched it
        // off after losing one would still be letting that card in.
        if (filled($card)) {
            return Setting::bool('kiosk.scanner', true)
                ? $this->byCard($card)
                : ['status' => 'not_found'];
        }

        if (filled($pin)) {
            return $this->byPin($pin);
        }

        return ['status' => 'not_found'];
    }

    /**
     * Note who was recognised, or that somebody was not.
     *
     * The card and the PIN themselves never reach this — only which kind was
     * presented. A log that recorded the secret would be a list of every PIN
     * in the centre, which is worse than having no log.
     *
     * Guarded: a clock that would not take a punch because it could not write
     * a log line is a clock somebody has to work around.
     */
    private function record(array $found, string $method): void
    {
        rescue(fn () => LoginEvent::create([
            'user_id' => $found['user']->id ?? null,
            'outcome' => $found['status'] === 'ok' ? LoginEvent::KIOSK : LoginEvent::KIOSK_FAILED,
            'method' => $method,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
        ]), null, false);
    }

    /**
     * A scanned card.
     *
     * No lockout: the code is forty random characters, so guessing is not the
     * threat and counting failures would only let somebody lock a colleague
     * out by waving rubbish at the scanner.
     */
    private function byCard(string $card): array
    {
        $user = User::where('card_index', User::kioskIndexFor($card))->get()
            ->first(fn (User $candidate) => Hash::check($card, (string) $candidate->card_hash));

        if (! $user) {
            return ['status' => 'not_found'];
        }

        if ($user->kioskIsLocked()) {
            return ['status' => 'locked'];
        }

        return ['status' => 'ok', 'user' => $user, 'method' => self::METHOD_CARD];
    }

    /**
     * Four digits.
     *
     * Short enough to key in with a queue behind you, which is only defensible
     * because of the lockout — five wrong tries and that number stops being
     * answered for a while.
     */
    private function byPin(string $pin): array
    {
        $candidates = User::where('kiosk_pin_index', User::kioskIndexFor($pin))->get();

        // Every match locked is a lockout, not a miss: otherwise waiting out
        // one row's lock could be dodged by a second person sharing the PIN.
        if ($candidates->isNotEmpty() && $candidates->every(fn (User $user) => $user->kioskIsLocked())) {
            return ['status' => 'locked'];
        }

        $matches = $candidates
            ->reject(fn (User $user) => $user->kioskIsLocked())
            ->filter(fn (User $user) => Hash::check($pin, (string) $user->kiosk_pin_hash));

        if ($matches->isEmpty()) {
            foreach ($candidates as $user) {
                $user->noteKioskFailure();
            }

            return ['status' => 'not_found'];
        }

        // Two people chose the same four digits. Asked rather than guessed —
        // the wrong guess here is somebody else's day on somebody's timesheet.
        if ($matches->count() > 1) {
            return ['status' => 'ambiguous'];
        }

        return ['status' => 'ok', 'user' => $matches->first(), 'method' => self::METHOD_PIN];
    }

    /**
     * What this person may press, given where they stand.
     *
     * One action set per state, and the screen renders only these — an action
     * that is impossible is never on screen to be tapped by mistake, which is
     * the whole reason the states are drawn separately rather than as one
     * screen with buttons greyed out.
     *
     * There is deliberately no way from a break to clocked out. A break always
     * ends back on the clock, so somebody leaving for the day ends the break
     * and then clocks out — two presses that are two true facts, rather than
     * one press the timesheet has to guess the shape of.
     *
     * Lunch is not on the tiles below because nothing here starts one; it is
     * here because the app can, and a kiosk that showed no way out of a state
     * somebody is actually in would be a kiosk they are stuck at.
     *
     * @return list<array{action: string, label: string, tone: string}>
     */
    public function actionsFor(User $user, ?array $status = null): array
    {
        $status ??= $this->presence->status($user);
        $here = $status['room'] ?? null;
        $back = $status['last_room'] ?? null;

        return match ($this->clock->state($user, today()->toDateString())) {
            TimeClock::OFF => [
                // The room picker follows (HANDOFF §5.2 step 4): the punch and
                // the room check-in are made together by the tile they pick.
                ['action' => 'clock_in', 'label' => 'Clock In', 'tone' => 'go', 'hint' => 'Next: pick the room you’re going to', 'picks_room' => true],
            ],
            // Attendance Only: they have arrived and that is the whole of what
            // this centre records, so there is nothing left to press. Offering
            // a clock-out that nothing reads would invite somebody to press it
            // and believe their hours were being counted.
            TimeClock::WORKING => SettingController::tracksTime() ? [
                ['action' => 'break_start', 'label' => 'Start Break', 'tone' => 'hold', 'hint' => $here ? "Checks you out of {$here}" : 'Keeps your place in the office', 'picks_room' => false],
                ['action' => 'clock_out', 'label' => 'Clock Out', 'tone' => 'stop', 'hint' => 'End of your day', 'picks_room' => false],
            ] : [],
            // RULE: a break ends back on the clock, and back in the room it was
            // taken from. "Going somewhere else?" is the picker, not a button.
            TimeClock::BREAK => [
                ['action' => 'break_end', 'label' => 'End Break', 'tone' => 'go', 'hint' => 'Back to '.($back ?? 'the office'), 'picks_room' => false],
            ],
            TimeClock::LUNCH => [
                ['action' => 'lunch_end', 'label' => 'End Lunch', 'tone' => 'go', 'hint' => 'Back to '.($back ?? 'the office'), 'picks_room' => false],
            ],
            default => [],
        };
    }

    /** The punch each button makes. */
    private const PUNCH_FOR = [
        'clock_in' => TimePunch::IN,
        'clock_out' => TimePunch::OUT,
        // One break button, and it is the paid kind. TimeClock pays a rest
        // break up to the cap in config and stops paying beyond it, so a
        // twenty-minute tea break and an hour somebody forgot to come back
        // from are both recorded honestly without the person at the screen
        // having to classify their own break.
        'break_start' => TimePunch::BREAK_START,
        'break_end' => TimePunch::BREAK_END,
        'lunch_end' => TimePunch::LUNCH_END,
    ];

    /**
     * Press one of the buttons the state offers.
     *
     * Checked here as well as drawn there: a screen renders the possible
     * actions, and this refuses anything else. Two people at one tablet, or a
     * stale screen somebody comes back to after a colleague used it, would
     * otherwise post an action that was true a minute ago.
     *
     * @return array{status: string, action?: string, at?: string, user?: User}
     */
    public function punch(User $user, string $action, string $method, ?StaffDevice $device, ?string $ip = null, ?string $room = null): array
    {
        $status = $this->presence->status($user);
        $allowed = collect($this->actionsFor($user, $status))->pluck('action')->all();

        if (! in_array($action, $allowed, true)) {
            // Not an error to apologise for: the screen was simply out of date.
            // The caller re-reads the state and draws it again.
            return ['status' => 'stale', 'user' => $user];
        }

        // The word the screen sent for the room: a room, 'office', or nothing.
        // Nothing on a clock-in means an older screen, and the person is on the
        // clock with no room until a tablet places them; nothing on End Break
        // means back where they were.
        [$valid, $to] = $this->presence->resolveRoom($room);

        if (! $valid) {
            return ['status' => 'stale', 'user' => $user];
        }

        $punch = $this->writePunch($user, self::PUNCH_FOR[$action], TimePunch::SOURCE_CLOCK, $method, $device, $ip);
        $this->presence->logPunch($user, $this->feedLine($user, $action, $status), $device);

        $warning = null;
        $place = null;

        switch ($action) {
            case 'clock_in':
                if ($room !== null) {
                    $place = $this->presence->checkIn($user, $to, StaffRoomCheckin::VIA_DOOR, $device);
                    $this->presence->logRoom($user, $to, $user->name.' → '.($to ?? 'Office / no room'), $device);
                }
                break;

            case 'break_start':
                $left = $this->presence->leave($user, StaffRoomCheckin::ENDED_BREAK, $device);
                if ($left !== null && ! $left->isOffice()) {
                    $this->presence->logRoom($user, $left->classroom, $user->name.' left '.$left->classroom, $device);
                }
                $warning = $this->presence->shortWarning($left, $user, $device);
                break;

            case 'break_end':
            case 'lunch_end':
                // Back where they were unless they said otherwise. A break taken
                // from the office goes back to the office.
                $to = $room === null ? $status['last_room'] : $to;
                $place = $this->presence->checkIn($user, $to, $room === null ? StaffRoomCheckin::VIA_RETURN : StaffRoomCheckin::VIA_DOOR, $device);
                $this->presence->logRoom($user, $to, $user->name.' → '.($to ? ($room === null ? 'back to '.$to : $to) : 'Office / no room'), $device);
                break;

            case 'clock_out':
                $left = $this->presence->leave($user, StaffRoomCheckin::ENDED_CLOCK_OUT, $device);
                if ($left !== null && ! $left->isOffice()) {
                    $this->presence->logRoom($user, $left->classroom, $user->name.' left '.$left->classroom, $device);
                }
                $warning = $this->presence->shortWarning($left, $user, $device);
                break;
        }

        $user->clearKioskFailures();
        $device?->touchSeen();

        return [
            'status' => 'ok',
            'action' => $action,
            'at' => $punch->punched_at->format('g:i A'),
            'user' => $user,
            'room' => $place?->classroom,
            'placed' => $place !== null,
            'warning' => $warning,
        ];
    }

    /**
     * The activity line for a punch at the front door (QA spec B6/B8, 28 Oct
     * 2026): a break that ends says how long it was — "Evana ended break
     * (16 min)" — and a clock-out says the day's hours — "Farhana clocked
     * out · 2h 33m". The rest say where they were made.
     */
    private function feedLine(User $user, string $action, array $status): string
    {
        $name = $user->name;

        return match ($action) {
            'break_end', 'lunch_end' => $name.' '.self::FEED[$action].' ('.($status['since'] ? max(0, (int) $status['since']->diffInMinutes(now())) : 0).' min)',
            'clock_out' => (function () use ($user, $name) {
                $minutes = (int) $this->clock->day($user->id, today()->toDateString())['so_far'];

                return $name.' clocked out · '.intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
            })(),
            default => $name.' '.self::FEED[$action].' (front door)',
        };
    }

    /** The punch itself, with where and how it was made written on it. */
    private function writePunch(User $user, string $type, string $source, string $method, ?StaffDevice $device, ?string $ip): TimePunch
    {
        $punch = $this->clock->punch(
            user: $user,
            type: $type,
            at: now(),
            source: $source,
            by: null,
            reason: null,
            ip: $ip,
        );

        // Where and how, which `source` alone cannot say. Written after the
        // fact rather than threaded through TimeClock::punch, so the signature
        // every other caller uses is left alone.
        $punch->forceFill([
            'staff_device_id' => $device?->id,
            'method' => $method,
        ])->save();

        return $punch;
    }

    /* ------------------------------------------------------ room tablets */

    /** What a room tablet may offer (HANDOFF §5.3). Never a clock-out. */
    public const TABLET_CHECK_IN = 'check_in';

    public const TABLET_BREAK = 'break_start';

    /**
     * A PIN keyed at a room tablet.
     *
     * Somebody not on the clock is clocked in there and then — the owner's
     * choice over sending them back to the door — checked into the tablet's
     * room, and flagged so the office confirms the start time. Everybody
     * else gets the room screen.
     *
     * @return array{status: string, done?: array}
     */
    public function tabletArrival(User $user, StaffDevice $device, string $method, ?string $ip = null): array
    {
        $room = $device->classroom;

        if ($room === null) {
            return ['status' => 'no_room'];
        }

        if ($this->clock->state($user, today()->toDateString()) !== TimeClock::OFF) {
            return ['status' => 'ok'];
        }

        $punch = $this->writePunch($user, TimePunch::IN, TimePunch::SOURCE_ROOM_TABLET, $method, $device, $ip);
        $this->presence->checkIn($user, $room, StaffRoomCheckin::VIA_AUTO, $device);

        $this->presence->logPunch($user, "{$user->name} clocked in (auto, {$room} tablet)", $device);
        $this->presence->logRoom($user, $room, "{$user->name} → {$room}", $device);
        $this->presence->flag($user, "{$user->name} skipped the front door — office to confirm start time", $room, $device);

        $user->clearKioskFailures();
        $device->touchSeen();

        $at = $punch->punched_at->format('g:i A');

        return [
            'status' => 'auto_clock_in',
            'done' => [
                'tone' => 'ok',
                'title' => 'Clocked in for you',
                'sub' => "You weren’t clocked in, so we clocked you in at {$at} and checked you into {$room}. Next time, start at the front door.",
                'warning' => null,
            ],
        ];
    }

    /**
     * One of the room tablet's two buttons, pressed.
     *
     * A room tap from a break ends the break (the punch is the tablet's);
     * from a room it is a move; from the office it is a check-in. The break
     * button is Start Break at the door under another name.
     */
    public function tabletPunch(User $user, string $action, ?string $room, string $method, StaffDevice $device, ?string $ip = null): array
    {
        $status = $this->presence->status($user);

        if ($status['state'] === StaffPresence::OFF) {
            return ['status' => 'stale', 'user' => $user];
        }

        $tablet = $device->classroom;

        if ($action === self::TABLET_CHECK_IN) {
            [$valid, $to] = $this->presence->resolveRoom($room ?? $tablet);

            if (! $valid || $to === null) {
                // RULE: no "Office / no room" at a tablet — that is done at the door.
                return ['status' => 'stale', 'user' => $user];
            }

            $ending = $status['state'] === StaffPresence::ON_BREAK;
            $from = $status['room'];
            $lines = [];

            if ($ending) {
                $type = $this->clock->state($user, today()->toDateString()) === TimeClock::LUNCH ? TimePunch::LUNCH_END : TimePunch::BREAK_END;
                $this->writePunch($user, $type, TimePunch::SOURCE_ROOM_TABLET, $method, $device, $ip);
                $minutes = $status['since'] ? (int) $status['since']->diffInMinutes(now()) : 0;
                $this->presence->logPunch($user, "{$user->name} ended break ({$minutes} min) · {$tablet} tablet", $device);
                $lines[] = 'Break ended.';
            } elseif ($from !== null && $from === $to) {
                return ['status' => 'already_here', 'user' => $user];
            } elseif ($from !== null) {
                $this->presence->logRoom($user, $from, "{$user->name} left {$from}", $device);
                $lines[] = "Checked out of {$from}.";
            }

            $left = $this->presence->openCheckin($user);
            $this->presence->checkIn($user, $to, StaffRoomCheckin::VIA_TABLET, $device);
            $this->presence->logRoom($user, $to, "{$user->name} → {$to} ({$tablet} tablet)", $device);

            // The room they walked out of may be short now. Checked after the
            // move so the person is counted where they are, not where they were.
            $warning = ($left !== null && $left->classroom !== $to) ? $this->presence->shortWarning($left, $user, $device) : null;

            $user->clearKioskFailures();
            $device->touchSeen();

            return [
                'status' => 'ok',
                'action' => $action,
                'done' => [
                    'tone' => 'ok',
                    'title' => "You’re in {$to}",
                    'sub' => trim(implode(' ', $lines)." Checked into {$to} at ".now()->format('g:i A').'.'),
                    'warning' => $warning,
                ],
            ];
        }

        if ($action === self::TABLET_BREAK) {
            if ($status['state'] === StaffPresence::ON_BREAK || ! SettingController::tracksTime()) {
                return ['status' => 'stale', 'user' => $user];
            }

            $this->writePunch($user, TimePunch::BREAK_START, TimePunch::SOURCE_ROOM_TABLET, $method, $device, $ip);
            $this->presence->logPunch($user, "{$user->name} started break ({$tablet} tablet)", $device);

            $left = $this->presence->leave($user, StaffRoomCheckin::ENDED_BREAK, $device);
            if ($left !== null && ! $left->isOffice()) {
                $this->presence->logRoom($user, $left->classroom, "{$user->name} left {$left->classroom}", $device);
            }

            $user->clearKioskFailures();
            $device->touchSeen();

            return [
                'status' => 'ok',
                'action' => $action,
                'done' => [
                    'tone' => 'brk',
                    'title' => 'Enjoy your break',
                    'sub' => 'Break started at '.now()->format('g:i A').'. End it at the front door or by tapping into any room.',
                    'warning' => $this->presence->shortWarning($left, $user, $device),
                ],
            ];
        }

        return ['status' => 'stale', 'user' => $user];
    }

    /**
     * The room tablet's screen for one person: where they are, the big
     * button for this room, the other rooms, and whether a break is on offer.
     */
    public function tabletScreenFor(User $user, StaffDevice $device): array
    {
        $status = $this->presence->status($user);
        $tablet = $device->classroom;
        $here = $status['state'] === StaffPresence::IN_ROOM && $status['room'] === $tablet;
        $tiles = $tablet ? $this->presence->tilesFor($user, except: $tablet) : $this->presence->tilesFor($user);
        $summary = $tablet ? $this->presence->roomSummary($tablet) : null;

        $now = match ($status['state']) {
            StaffPresence::ON_BREAK => 'On break since '.($status['since']?->format('g:i A') ?? '—').' · was in '.($status['last_room'] ?? 'the office'),
            StaffPresence::IN_ROOM => 'Now: '.$status['room'].' since '.($status['since']?->format('g:i A') ?? '—'),
            StaffPresence::NO_ROOM => 'Now: office / no room'.($status['since'] ? ' since '.$status['since']->format('g:i A') : ''),
            default => 'Not on the clock.',
        };

        $hereSub = match (true) {
            $status['state'] === StaffPresence::ON_BREAK => 'Ends your break',
            $status['room'] !== null => 'Checks you out of '.$status['room'],
            default => 'From the office',
        };

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->firstName(),
            'state' => $status['state'],
            'on_break' => $status['state'] === StaffPresence::ON_BREAK,
            'now' => $now,
            'is_here' => $here,
            'can_here' => $tablet !== null && ! $here,
            'here_sub' => $summary ? $hereSub.' · '.$summary['sub'] : $hereSub,
            'rooms' => array_map(fn (array $tile) => $tile + [
                'is_here' => $status['state'] === StaffPresence::IN_ROOM && $status['room'] === $tile['room'],
            ], $tiles),
            'can_break' => $status['state'] !== StaffPresence::ON_BREAK && SettingController::tracksTime(),
        ];
    }

    /** What the confirmation says, in the words of the button just pressed. */
    public const CONFIRMATIONS = [
        'clock_in' => 'Clocked in',
        'clock_out' => 'Clocked out',
        'break_start' => 'Break started',
        'break_end' => 'Back on the clock',
        'lunch_end' => 'Back on the clock',
    ];

    /** The same press as the activity feed tells it. */
    public const FEED = [
        'clock_in' => 'clocked in',
        'clock_out' => 'clocked out',
        'break_start' => 'started break',
        'break_end' => 'ended break',
        'lunch_end' => 'ended lunch',
    ];

    /**
     * The screen for one person: who they are, where they stand, what they may
     * press, and the two facts underneath — what they last did and how much
     * the period has come to.
     */
    public function screenFor(User $user): array
    {
        $today = today()->toDateString();
        $day = $this->clock->day($user->id, $today);
        $status = $this->presence->status($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->firstName(),
            'staff_id' => $user->staffId(),
            'initials' => $user->initials,
            'state' => $day['state'],
            'state_label' => match ($day['state']) {
                TimeClock::WORKING => 'Clocked in',
                TimeClock::BREAK => 'On break',
                TimeClock::LUNCH => 'At lunch',
                default => 'Not clocked in',
            },
            // Where they stand in the four-state model (HANDOFF §5.1), the
            // pill that names it, and the one sentence under the name.
            'presence' => $status['state'],
            'presence_label' => StaffPresence::LABELS[$status['state']],
            'room' => $status['room'],
            'last_room' => $status['last_room'],
            'line' => $this->presence->line($user, $status, $day),
            // so_far already counts the completed stretches and adds the one
            // still running; 'worked' is the closed total. Adding them would
            // show a morning twice.
            'worked_minutes' => $day['so_far'],
            'since' => $this->sinceLine($day),
            'last_activity' => $this->lastActivity($user),
            'actions' => $this->actionsFor($user, $status),
            // The room picker's tiles, scheduled room first. Sent with the
            // card so picking a room is one round trip, not two.
            'rooms' => $this->presence->tilesFor($user),
            // What the office has planned for them today (Phase 5): the shift
            // they were given and any change the Staff page holds for the
            // date, so the note reaches the person at the clock, not only
            // the director who wrote it.
            'today' => $this->todayLine($user, $today),
        ];
    }

    /**
     * "Scheduled 9:00 AM – 6:00 PM · Transition", with the office's change
     * for the day after it ("Out until 3:00 PM · appointment"), or null when
     * nothing is planned.
     */
    private function todayLine(User $user, string $today): ?string
    {
        $shift = \App\Models\StaffShift::where('user_id', $user->id)->whereDate('shift_date', $today)->orderBy('starts_at')->first();
        $change = \App\Models\StaffTimeOff::where('user_id', $user->id)->onDate($today)->orderByDesc('id')->first();

        $parts = [];

        if ($shift) {
            $parts[] = 'Scheduled '.$shift->label().($shift->classroom ? ' · '.$shift->classroom : '');
        }

        if ($change) {
            $parts[] = $change->describe().' · '.strtolower($change->reasonLabel());
        }

        return $parts === [] ? null : implode(' — ', $parts);
    }

    /** "On the clock since 7:54 AM", or nothing when they are not. */
    private function sinceLine(array $day): ?string
    {
        // The bug from staging (HANDOFF §5.2): "On the clock since 9:24 AM"
        // under somebody who had clocked out. The first IN of the day is a
        // fact about the morning, not about now; once they are off it is
        // not said.
        if ($day['first_in'] === null || $day['state'] === TimeClock::OFF) {
            return null;
        }

        return 'On the clock since '.Carbon::today()->addMinutes($day['first_in'])->format('g:i A');
    }

    /** The last thing they did, whichever day it was on. */
    private function lastActivity(User $user): ?string
    {
        $last = TimePunch::live()
            ->where('user_id', $user->id)
            ->orderByDesc('punched_at')
            ->first();

        if ($last === null) {
            return null;
        }

        $when = $last->punched_at->isToday()
            ? $last->punched_at->format('g:i A').' today'
            : $last->punched_at->format('g:i A, D j M');

        return (TimePunch::LABELS[$last->type] ?? $last->type).' — '.$when;
    }
}
