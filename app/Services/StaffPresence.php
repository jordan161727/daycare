<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\KioskEvent;
use App\Models\StaffDevice;
use App\Models\StaffRoomCheckin;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\TimePunch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One status per member of staff, and which room it puts them in.
 *
 * HANDOFF §5.1, the rule everything on the kiosks rests on: at any moment a
 * person is in exactly one of four states —
 *
 *   off               not clocked in                     counts nowhere
 *   working_in_room   clocked in and checked into room X counts in X
 *   working_no_room   clocked in; office, kitchen, …     counts nowhere
 *   on_break          clocked in, on a break             counts nowhere,
 *                                                        remembers last room
 *
 * The clock state comes from the punches, as it always has — this service
 * never decides whether somebody is on the clock. What it adds is the room:
 * an open StaffRoomCheckin for whoever is working, closed when they move,
 * break, leave, or the centre closes around them.
 *
 * Ratios read from here and from nowhere else: children present in a room
 * divided by the room's ratio from Settings, against the people whose open
 * check-in names that room. A short room is warned about and flagged for
 * the office; it is never a reason to refuse a break or a clock-out
 * (§5.3.2 — people moving is what keeps children safe, so the system does
 * not stand in the way of a person moving).
 */
class StaffPresence
{
    public const OFF = 'off';

    public const IN_ROOM = 'working_in_room';

    public const NO_ROOM = 'working_no_room';

    public const ON_BREAK = 'on_break';

    /** What the status pill says, per state. */
    public const LABELS = [
        self::OFF => 'Not on the clock',
        self::IN_ROOM => 'Working',
        self::NO_ROOM => 'Working',
        self::ON_BREAK => 'On break',
    ];

    /** The word the room picker uses for "no room", on every screen. */
    public const OFFICE = 'office';

    /** Minutes past a shift's start before "Due" becomes "Late". */
    public const LATE_AFTER_MINUTES = 5;

    public function __construct(private TimeClock $clock, private CentreSettings $centre) {}

    /* ---------------------------------------------------------------- status */

    /**
     * Where one person stands right now.
     *
     * @return array{state: string, room: ?string, last_room: ?string, last_was_office: bool, since: ?Carbon, placed: bool}
     */
    public function status(User $user): array
    {
        $today = today()->toDateString();
        $clock = $this->clock->state($user, $today);

        if ($clock === TimeClock::OFF) {
            return $this->shape(self::OFF);
        }

        if ($clock === TimeClock::BREAK || $clock === TimeClock::LUNCH) {
            $last = $this->lastRoomBeforeBreak($user);

            return $this->shape(self::ON_BREAK, null, $last?->classroom, $last !== null && $last->isOffice(), $this->breakStartedAt($user, $today));
        }

        $open = $this->openCheckin($user);

        if ($open === null) {
            // On the clock by some route that never asked for a room — the
            // app's own clock, or a punch a supervisor typed. Not placed, so
            // the dashboard may fall back to the roster for where they are.
            return $this->shape(self::NO_ROOM, placed: false);
        }

        return $this->shape($open->isOffice() ? self::NO_ROOM : self::IN_ROOM, $open->classroom, since: $open->started_at, placed: true);
    }

    private function shape(string $state, ?string $room = null, ?string $lastRoom = null, bool $lastWasOffice = false, ?Carbon $since = null, bool $placed = false): array
    {
        return [
            'state' => $state,
            'room' => $room,
            'last_room' => $lastRoom,
            'last_was_office' => $lastWasOffice,
            'since' => $since,
            'placed' => $placed,
        ];
    }

    /** The one sentence under the name on the staff card (HANDOFF §5.2 step 2). */
    public function line(User $user, array $status, array $day): string
    {
        $fmt = fn (?Carbon $at) => $at?->format('g:i A');

        return match ($status['state']) {
            self::OFF => 'Not on the clock today.',
            self::ON_BREAK => 'On break since '.$fmt($status['since']).' ('.$this->minutesSince($status['since']).' min) · was in '.($status['last_room'] ?? 'the office'),
            // The current stretch, not the morning's: somebody who clocked
            // out at lunch and in again at two is on the clock since two.
            default => 'On the clock since '.($this->latestInAt($user)?->format('g:i A') ?? '—')
                .' · '.($status['room'] !== null ? 'in '.$status['room'] : 'office / no room'),
        };
    }

    /** The IN punch that started the stretch they are on now. */
    private function latestInAt(User $user): ?Carbon
    {
        return TimePunch::live()
            ->where('user_id', $user->id)
            ->whereDate('work_date', today())
            ->where('type', TimePunch::IN)
            ->orderByDesc('punched_at')
            ->value('punched_at')
            ?->copy();
    }

    /**
     * Today's open check-in. One left open from an earlier day — the closing
     * job did not run — is not where anybody is standing today (28 Oct 2026).
     */
    public function openCheckin(User $user): ?StaffRoomCheckin
    {
        return StaffRoomCheckin::open()->where('user_id', $user->id)
            ->where('started_at', '>=', today())
            ->orderByDesc('started_at')->orderByDesc('id')->first();
    }

    /** Close every open check-in this person holds, today's or older. */
    private function closeAllOpen(User $user, string $reason, ?StaffDevice $device): void
    {
        StaffRoomCheckin::open()->where('user_id', $user->id)->get()
            ->each(fn (StaffRoomCheckin $row) => $row->close($reason, $device));
    }

    /** The room a break was taken from, so End Break can put them back. */
    public function lastRoomBeforeBreak(User $user): ?StaffRoomCheckin
    {
        return StaffRoomCheckin::where('user_id', $user->id)
            ->where('ended_reason', StaffRoomCheckin::ENDED_BREAK)
            ->whereDate('ended_at', today())
            ->orderByDesc('ended_at')->orderByDesc('id')
            ->first();
    }

    private function breakStartedAt(User $user, string $today): ?Carbon
    {
        return TimePunch::live()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $today)
            ->whereIn('type', [TimePunch::BREAK_START, TimePunch::LUNCH_START])
            ->orderByDesc('punched_at')
            ->value('punched_at')
            ?->copy();
    }

    private function minutesSince(?Carbon $at): int
    {
        return $at === null ? 0 : max(0, (int) $at->diffInMinutes(now()));
    }

    /* -------------------------------------------------------------- moving */

    /**
     * Put somebody in a room, or in the office (null).
     *
     * Whatever they were in closes first as a move, so there is never more
     * than one open row per person — the invariant the ratios count on.
     */
    public function checkIn(User $user, ?string $room, string $via, ?StaffDevice $device = null): StaffRoomCheckin
    {
        $this->closeAllOpen($user, StaffRoomCheckin::ENDED_MOVE, $device);

        return StaffRoomCheckin::create([
            'user_id' => $user->id,
            'classroom' => $room,
            'started_at' => now(),
            'started_via' => $via,
            'started_device_id' => $device?->id,
        ]);
    }

    /**
     * Take somebody out of wherever they are, saying why.
     *
     * Returns the row that closed so the caller can ask whether the room they
     * left is now short. Null when they were nowhere.
     */
    public function leave(User $user, string $reason, ?StaffDevice $device = null): ?StaffRoomCheckin
    {
        $today = $this->openCheckin($user)?->close($reason, $device);
        // Anything older still open goes too, quietly: it was never today's.
        $this->closeAllOpen($user, StaffRoomCheckin::ENDED_CLOSING, $device);

        return $today;
    }

    /**
     * Turn the word a screen sends into a room or the office.
     *
     * 'office' and '' are the office; a room has to be one of the centre's;
     * anything else is refused as null-with-false so a typo does not land
     * somebody in a room that does not exist.
     *
     * @return array{0: bool, 1: ?string}  [valid, room-or-null]
     */
    public function resolveRoom(?string $word): array
    {
        if ($word === null || $word === '' || $word === self::OFFICE) {
            return [true, null];
        }

        return in_array($word, ClassroomAssignment::rooms(), true) ? [true, $word] : [false, null];
    }

    /* --------------------------------------------------------------- rooms */

    /**
     * Everyone standing in this room: an open check-in from today, held by
     * somebody working right now.
     *
     * RULE (28 Oct 2026): the check-in alone is not enough. A director who
     * adds a forgotten clock-out in Staff attendance writes a punch, not a
     * room move, and that person is off — so they must stop counting at
     * once, whoever wrote the punch. The same holds for a break typed in by
     * hand, and for a check-in left open from an earlier day.
     */
    public function teachersIn(string $room): Collection
    {
        $today = today()->toDateString();

        return StaffRoomCheckin::open()->where('classroom', $room)
            ->where('started_at', '>=', today())
            ->with('user')->orderBy('started_at')->get()
            ->filter(fn (StaffRoomCheckin $row) => $row->user !== null
                && $this->clock->state($row->user, $today) === TimeClock::WORKING)
            ->values();
    }

    /**
     * Children in each room right now: signed in today and not yet out.
     *
     * @return array<string, int>
     */
    public function childrenByRoom(): array
    {
        $counts = array_fill_keys(ClassroomAssignment::rooms(), 0);

        Attendance::whereDate('attendance_date', today())
            ->whereNotNull('signed_in_at')
            ->whereNull('signed_out_at')
            ->with('child:id,classroom')
            ->get()
            ->each(function (Attendance $row) use (&$counts) {
                $room = $row->child?->classroom;
                if ($room !== null && array_key_exists($room, $counts)) {
                    $counts[$room]++;
                }
            });

        return $counts;
    }

    /** RULE: required = ceil(children ÷ ratio); an empty room needs nobody. */
    public function needed(string $room, int $children): int
    {
        $ratio = $this->centre->ratio($room);

        return ($children > 0 && $ratio > 0) ? (int) ceil($children / $ratio) : 0;
    }

    /**
     * One room's figures, for a tile or a panel row.
     *
     * @return array{room: string, colour: string, children: int, teachers: int, needed: int, short: bool, sub: string, label: string, tone: string}
     */
    public function roomSummary(string $room, ?array $children = null): array
    {
        $kids = ($children ?? $this->childrenByRoom())[$room] ?? 0;
        $here = $this->teachersIn($room)->count();
        $need = $this->needed($room, $kids);
        $plural = fn (int $n) => $n === 1 ? 'teacher' : 'teachers';

        return [
            'room' => $room,
            'colour' => ScheduleEditor::COLOURS[$room][1] ?? '#9aa3b5',
            'children' => $kids,
            'teachers' => $here,
            'needed' => $need,
            'short' => $here < $need,
            // The line under a room's name on a tile (HANDOFF §5.2 step 4).
            'sub' => $kids > 0 ? "{$kids} children · {$here} {$plural($here)}, need {$need}" : 'No children right now',
            // The pill on the Who's where panel (§5.3.1).
            'label' => $kids > 0 ? "{$here} {$plural($here)} · need {$need} · {$kids} kids" : 'no children',
            'tone' => $kids === 0 ? 'mut' : ($here >= $need ? 'ok' : 'bad'),
        ];
    }

    /**
     * The room tiles for one person: their scheduled room first, flagged.
     *
     * @return list<array>
     */
    public function tilesFor(User $user, ?string $except = null): array
    {
        $mine = $this->scheduledRoom($user);
        $children = $this->childrenByRoom();

        return collect(ClassroomAssignment::rooms())
            ->reject(fn (string $room) => $room === $except)
            ->sortBy(fn (string $room) => $room === $mine ? 0 : 1, SORT_NUMERIC, false)
            ->values()
            ->map(fn (string $room) => $this->roomSummary($room, $children) + ['mine' => $room === $mine])
            ->all();
    }

    /** The room the roster has them in now, else their home room. */
    public function scheduledRoom(User $user): ?string
    {
        $minute = now()->hour * 60 + now()->minute;
        $shifts = StaffShift::where('user_id', $user->id)->whereDate('shift_date', today())->orderBy('starts_at')->get();
        $shift = $shifts->first(fn (StaffShift $shift) => $shift->covers($minute)) ?? $shifts->first();

        return $shift?->classroom ?: $user->classroom;
    }

    /**
     * After somebody has left a room: is it short now?
     *
     * Returns the sentence for the done screen, or null when the room is
     * fine (or empty, or was the office). The flag for the office is written
     * here too, so no caller can warn without telling the office.
     */
    public function shortWarning(?StaffRoomCheckin $left, User $who, ?StaffDevice $device = null): ?string
    {
        if ($left === null || $left->isOffice()) {
            return null;
        }

        $room = $left->classroom;
        $summary = $this->roomSummary($room);

        if (! $summary['short']) {
            return null;
        }

        $this->flag($who, "Ratio: {$room} short after {$who->name} left", $room, $device);

        $teachers = $summary['teachers'] === 1 ? 'teacher' : 'teachers';

        return "{$room} now has {$summary['teachers']} {$teachers} for {$summary['children']} children (needs {$summary['needed']}). The office has been told.";
    }

    /* ------------------------------------------------------------ the feed */

    public function logPunch(User $user, string $text, ?StaffDevice $device = null): KioskEvent
    {
        return $this->log(KioskEvent::PUNCH, $text, $user, null, $device);
    }

    public function logRoom(User $user, ?string $room, string $text, ?StaffDevice $device = null): KioskEvent
    {
        return $this->log(KioskEvent::ROOM, $text, $user, $room, $device);
    }

    public function flag(?User $user, string $text, ?string $room = null, ?StaffDevice $device = null): KioskEvent
    {
        return $this->log(KioskEvent::FLAG, $text, $user, $room, $device);
    }

    public function logTablet(StaffDevice $device, string $text): KioskEvent
    {
        return $this->log(KioskEvent::TABLET, $text, null, $device->classroom, $device);
    }

    private function log(string $kind, string $text, ?User $user, ?string $room, ?StaffDevice $device): KioskEvent
    {
        return KioskEvent::create([
            'kind' => $kind,
            'user_id' => $user?->id,
            'classroom' => $room,
            'staff_device_id' => $device?->id,
            'text' => mb_substr($text, 0, 300),
            'occurred_at' => now(),
        ]);
    }

    /* ------------------------------------------------------- who's where */

    /**
     * The whole floor at a glance (HANDOFF §5.3.1): room by room, on break,
     * in the office, not clocked in, and the newest lines of the feed.
     */
    /**
     * The feed shows the 12 newest lines (QA spec F2, 28 Oct 2026). The full
     * history is kept and read on Staff → Activity.
     */
    public const FEED_LINES = 12;

    public function board(int $feedLines = self::FEED_LINES): array
    {
        $children = $this->childrenByRoom();
        $teachers = User::teachers()->get();
        $today = today()->toDateString();

        $rooms = [];
        foreach (ClassroomAssignment::rooms() as $room) {
            $rooms[] = $this->roomSummary($room, $children) + [
                'people' => $this->teachersIn($room)->map(fn (StaffRoomCheckin $row) => [
                    'name' => $row->user?->name ?? '—',
                    'since' => $row->started_at->format('g:i A'),
                ])->values()->all(),
            ];
        }

        $onBreak = [];
        $office = [];
        $notIn = [];

        foreach ($teachers as $user) {
            $status = $this->status($user);

            match ($status['state']) {
                self::ON_BREAK => $onBreak[] = [
                    'name' => $user->name,
                    'since' => $status['since']?->format('g:i A') ?? '—',
                    'last' => $status['last_room'] ?? 'office',
                ],
                // Placed in the office says since when; on the clock by a
                // route that never asked for a room says so instead.
                self::NO_ROOM => $office[] = [
                    'name' => $user->name,
                    'since' => $status['placed'] ? 'since '.($status['since']?->format('g:i A') ?? '—') : 'no room picked',
                ],
                self::OFF => $notIn[] = ['name' => $user->name] + $this->notInNote($user, $today),
                default => null,
            };
        }

        return [
            'rooms' => $rooms,
            'on_break' => $onBreak,
            'office' => $office,
            'not_in' => $notIn,
            'flags' => KioskEvent::open()->newestFirst()->with('user')->get(),
            'activity' => KioskEvent::newestFirst()->with('user')->limit($feedLines)->get()->map(fn (KioskEvent $event) => [
                'time' => $event->occurred_at->format('g:i A'),
                'kind' => $event->kind,
                'colour' => match ($event->kind) {
                    KioskEvent::PUNCH => '#1b5e91',
                    KioskEvent::FLAG => '#be123c',
                    KioskEvent::ROOM => ScheduleEditor::COLOURS[$event->classroom][1] ?? '#9aa3b5',
                    default => '#9aa3b5',
                },
                'text' => $event->text,
                'resolved' => $event->resolved_at !== null,
            ])->all(),
        ];
    }

    /**
     * Why somebody who is not on the clock is not: clocked out, off, late,
     * due later, or never on today's roster.
     *
     * RULE (§5.3.1): time off is never late. "Out until 3:00 PM" at 10:30 is
     * a person doing what the office agreed, not a person the office should
     * chase.
     *
     * @return array{note: string, tone: string}
     */
    private function notInNote(User $user, string $today): array
    {
        $out = TimePunch::live()->where('user_id', $user->id)->whereDate('work_date', $today)
            ->where('type', TimePunch::OUT)->orderByDesc('punched_at')->value('punched_at');

        if ($out !== null) {
            return ['note' => 'Clocked out '.Carbon::parse($out)->format('g:i A'), 'tone' => 'mut'];
        }

        $minute = now()->hour * 60 + now()->minute;
        $change = StaffTimeOff::where('user_id', $user->id)->onDate($today)->orderByDesc('id')->first();

        if ($change !== null) {
            if ($change->kind === 'all') {
                return ['note' => 'Off today', 'tone' => 'info'];
            }
            if (in_array($change->kind, ['until', 'window'], true) && $change->from_minute !== null && $minute < $change->from_minute) {
                return ['note' => 'Time off until '.\App\Models\StaffRule::formatTime($change->from_minute), 'tone' => 'info'];
            }
        }

        $shift = StaffShift::where('user_id', $user->id)->whereDate('shift_date', $today)->orderBy('starts_at')->first();

        if ($shift === null) {
            return ['note' => 'Not scheduled', 'tone' => 'mut'];
        }

        $due = \App\Models\StaffRule::formatTime($shift->starts_at);

        return $minute > $shift->starts_at + self::LATE_AFTER_MINUTES
            ? ['note' => 'Late · due '.$due, 'tone' => 'bad']
            : ['note' => 'Due '.$due, 'tone' => 'mut'];
    }

    /* ---------------------------------------------------------- closing */

    /**
     * The closing-time safety net (HANDOFF §5.3.2).
     *
     * Anyone still on the clock is flagged for the office and taken out of
     * their room, so tomorrow's ratios do not start with yesterday's people
     * in them. No clock-out punch is written: a punch the person never made
     * is not evidence, and the director fixes the day in Staff attendance.
     *
     * @return int  how many people were flagged
     */
    public function closeDay(?StaffDevice $device = null): int
    {
        $today = today()->toDateString();
        $flagged = 0;

        // Teachers, and anybody else holding a check-in or a punch today — a
        // director who clocks in with a PIN is on the floor too (28 Oct 2026).
        $ids = User::teachers()->pluck('id')
            ->merge(StaffRoomCheckin::open()->pluck('user_id'))
            ->merge(TimePunch::live()->whereDate('work_date', $today)->pluck('user_id'))
            ->unique();

        foreach (User::whereIn('id', $ids)->get() as $user) {
            if ($this->clock->state($user, $today) === TimeClock::OFF) {
                // Off, but a check-in was left open (a clock-out typed in by
                // hand, or an earlier day): close it without a flag.
                $this->closeAllOpen($user, StaffRoomCheckin::ENDED_CLOSING, $device);

                continue;
            }

            $open = $this->leave($user, StaffRoomCheckin::ENDED_CLOSING, $device);
            $this->flag($user, "{$user->name} still on the clock at closing — office to fix the day", $open?->classroom, $device);
            $flagged++;
        }

        return $flagged;
    }
}
