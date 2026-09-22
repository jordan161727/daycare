<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\Setting;
use App\Models\StaffDevice;
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

    public function __construct(private TimeClock $clock) {}

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
    public function actionsFor(User $user): array
    {
        return match ($this->clock->state($user, today()->toDateString())) {
            TimeClock::OFF => [
                ['action' => 'clock_in', 'label' => 'Clock In', 'tone' => 'go'],
            ],
            // Attendance Only: they have arrived and that is the whole of what
            // this centre records, so there is nothing left to press. Offering
            // a clock-out that nothing reads would invite somebody to press it
            // and believe their hours were being counted.
            TimeClock::WORKING => SettingController::tracksTime() ? [
                ['action' => 'break_start', 'label' => 'Start Break', 'tone' => 'hold'],
                ['action' => 'clock_out', 'label' => 'Clock Out', 'tone' => 'stop'],
            ] : [],
            TimeClock::BREAK => [
                ['action' => 'break_end', 'label' => 'End Break', 'tone' => 'hold'],
            ],
            TimeClock::LUNCH => [
                ['action' => 'lunch_end', 'label' => 'End Lunch', 'tone' => 'hold'],
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
    public function punch(User $user, string $action, string $method, ?StaffDevice $device, ?string $ip = null): array
    {
        $allowed = collect($this->actionsFor($user))->pluck('action')->all();

        if (! in_array($action, $allowed, true)) {
            // Not an error to apologise for: the screen was simply out of date.
            // The caller re-reads the state and draws it again.
            return ['status' => 'stale', 'user' => $user];
        }

        $punch = $this->clock->punch(
            user: $user,
            type: self::PUNCH_FOR[$action],
            at: now(),
            source: TimePunch::SOURCE_CLOCK,
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

        $user->clearKioskFailures();
        $device?->touchSeen();

        return [
            'status' => 'ok',
            'action' => $action,
            'at' => $punch->punched_at->format('g:i A'),
            'user' => $user,
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

    /**
     * The screen for one person: who they are, where they stand, what they may
     * press, and the two facts underneath — what they last did and how much
     * the period has come to.
     */
    public function screenFor(User $user): array
    {
        $today = today()->toDateString();
        $day = $this->clock->day($user->id, $today);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'staff_id' => $user->staffId(),
            'initials' => $user->initials,
            'state' => $day['state'],
            'state_label' => match ($day['state']) {
                TimeClock::WORKING => 'Clocked in',
                TimeClock::BREAK => 'On break',
                TimeClock::LUNCH => 'At lunch',
                default => 'Not clocked in',
            },
            // so_far already counts the completed stretches and adds the one
            // still running; 'worked' is the closed total. Adding them would
            // show a morning twice.
            'worked_minutes' => $day['so_far'],
            'since' => $this->sinceLine($day),
            'last_activity' => $this->lastActivity($user),
            'actions' => $this->actionsFor($user),
        ];
    }

    /** "On the clock since 7:54 AM", or nothing when they are not. */
    private function sinceLine(array $day): ?string
    {
        if ($day['first_in'] === null) {
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
