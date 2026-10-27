<?php

namespace App\Services;

use App\Models\ClosureDay;
use App\Models\LeaveRequest;
use App\Models\StaffRule;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Schedule page's editing side — Phase 7 of docs/work.md, as
 * Schedule.dc.html draws it.
 *
 * StaffSchedule builds a week from the rules; this is what the director does
 * to it afterwards: drag a shift into another room, drop somebody who is not
 * working into a room that is short, trim a shift around an appointment. Every
 * change is previewed before it is saved — what it does to both rooms, and
 * which rules it would break — and a change that breaks a Must rule cannot be
 * saved at all. A Prefer rule is only ever reported.
 *
 * It reasons about one day at a time, in minutes past midnight, the way the
 * shifts are stored, and reads the same rules, time off and leave the
 * generator reads, so the two never disagree about what is allowed.
 */
class ScheduleEditor
{
    /** Fill, border and text per room, from the mock-up. */
    public const COLOURS = [
        'Infant' => ['#ddf0ea', '#8cc4b2', '#1e5c4a'],
        'Transition' => ['#fbebd3', '#e0b577', '#7a5416'],
        'Toddler' => ['#eae4f6', '#b3a3dc', '#4b3a80'],
        'PreK' => ['#e3f0dc', '#9dc585', '#33591f'],
        'UPK-4' => ['#fde6ee', '#e8a0b8', '#8a2550'],
        'School Age' => ['#dfeaf8', '#92b4df', '#24487e'],
    ];

    private int $open;

    private int $close;

    private int $step;

    private ?string $weekStart = null;

    private Collection $staff;

    /** @var array<int, array<string, StaffTimeOff>> user → date → entry */
    private array $timeOff = [];

    /** @var array<int, array<string, LeaveRequest>> user → date → request */
    private array $leave = [];

    /** @var array<string, array<string, list<array>>> day → room → demand steps */
    private array $demandWeek = [];

    /** @var array<int, list<int>> NO_PAIR, both ways */
    private array $noPair = [];

    /** @var array<string, string> closed dates → label */
    private array $closed = [];

    public function __construct(private StaffSchedule $scheduler, private RoomDemand $demand)
    {
        $this->open = (int) config('daycare.open');
        $this->close = (int) config('daycare.close');
        $this->step = (int) config('daycare.coverage_step');
        $this->staff = collect();
    }

    public static function colour(?string $room): array
    {
        return self::COLOURS[$room] ?? ['#f2f4f9', '#cfd3de', '#5f6676'];
    }

    public static function rooms(): array
    {
        return ClassroomAssignment::rooms();
    }

    /* ---------------------------------------------------------------
     * The week, read once
     * --------------------------------------------------------------- */

    private function load(string $weekStart): void
    {
        if ($this->weekStart === $weekStart) {
            return;
        }

        $this->weekStart = $weekStart;
        $dates = StaffScheduleWeek::datesOf($weekStart);
        $last = end($dates)->toDateString();

        $this->staff = User::teachers()->with('staffRules')->get();
        $this->timeOff = StaffTimeOff::byUserAndDate($weekStart, $last);
        $this->leave = LeaveRequest::byUserAndDate($weekStart, $last);
        $this->demandWeek = $this->demand->forWeek($weekStart);
        $this->closed = ClosureDay::betweenDates(reset($dates), end($dates))->get()
            ->mapWithKeys(fn ($day) => [$day->closed_on->toDateString() => $day->label()])->all();

        $byName = $this->staff->keyBy('name');
        $this->noPair = [];

        foreach ($this->staff as $person) {
            foreach ($person->staffRules->where('rule_type', 'NO_PAIR') as $rule) {
                if ($other = $byName->get($rule->value_text)) {
                    $this->noPair[$person->id][] = $other->id;
                    $this->noPair[$other->id][] = $person->id;
                }
            }
        }
    }

    private function person(int $id): ?User
    {
        return $this->staff->firstWhere('id', $id);
    }

    private function date(string $day): string
    {
        return StaffScheduleWeek::datesOf($this->weekStart)[$day]->toDateString();
    }

    /** The day's shifts as plain arrays, which is what every check reads. */
    private function shiftsOn(string $day): array
    {
        return StaffShift::where('week_start', $this->weekStart)->where('day', $day)->orderBy('starts_at')->get()
            ->map(fn (StaffShift $s) => ['id' => $s->id, 'user_id' => $s->user_id, 'classroom' => $s->classroom, 'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at, 'role' => $s->role])
            ->values()->all();
    }

    /* ---------------------------------------------------------------
     * Rules, time off and leave for one person on one day
     * --------------------------------------------------------------- */

    /**
     * When this person may work on the day: the rules' window with the Staff
     * page's change for the date laid over it. Null is a closed day.
     *
     * @return array{start: int, end: int}|null
     */
    private function window(User $person, string $day, string $date): ?array
    {
        $window = $this->scheduler->windowFor($person, $day);
        $window = $window ? ['start' => $window['start'], 'end' => $window['end']] : null;

        if ($entry = $this->timeOff[$person->id][$date] ?? null) {
            $window = $entry->applyTo($window);
        }

        return $window;
    }

    /**
     * The stretches this person is away on the day, from time off and leave.
     *
     * @return list<array{0: int, 1: int, 2: string}> from, to, label
     */
    private function busy(int $userId, string $date): array
    {
        $blocks = [];

        if ($leave = $this->leave[$userId][$date] ?? null) {
            $blocks[] = [$this->open, $this->close, $leave->label()];
        }

        if ($entry = $this->timeOff[$userId][$date] ?? null) {
            $label = $entry->reasonLabel();
            $blocks = array_merge($blocks, match ($entry->kind) {
                'all' => [[$this->open, $this->close, $label]],
                'until' => [[$this->open, (int) $entry->from_minute, $label]],
                'leave' => [[(int) $entry->to_minute, $this->close, $label]],
                'window' => [[$this->open, (int) $entry->from_minute, $label], [(int) $entry->to_minute, $this->close, $label]],
                default => [],
            });
        }

        return array_values(array_filter($blocks, fn ($b) => $b[1] > $b[0]));
    }

    private function busyAt(int $userId, string $date, int $minute): bool
    {
        foreach ($this->busy($userId, $date) as [$a, $b]) {
            if ($minute >= $a && $minute < $b) {
                return true;
            }
        }

        return false;
    }

    /** "out until 3:00 PM", "out all day", … for a sentence. */
    private function describeOff(int $userId, string $date): ?string
    {
        if ($leave = $this->leave[$userId][$date] ?? null) {
            return 'on approved '.strtolower($leave->label());
        }

        $entry = $this->timeOff[$userId][$date] ?? null;

        return $entry ? lcfirst($entry->describe()).' · '.strtolower($entry->reasonLabel()) : null;
    }

    private function hasRule(User $person, string $type): bool
    {
        return $person->staffRules->contains(fn (StaffRule $r) => $r->rule_type === $type);
    }

    private function forbidden(User $person, string $room): bool
    {
        return $person->staffRules->contains(fn (StaffRule $r) => $r->rule_type === 'ROOM_FORBIDDEN' && $r->value_text === $room);
    }

    /* ---------------------------------------------------------------
     * Coverage
     * --------------------------------------------------------------- */

    /** The slices of the operating day, as start minutes. */
    private function slices(): array
    {
        return range($this->open, $this->close - $this->step, $this->step);
    }

    /**
     * Staff on the floor against staff the ratio demands, per slice.
     *
     * Somebody scheduled but away — out until three, on leave — is not on the
     * floor, so a shift over a time-off block counts for nothing in it.
     *
     * @return array{have: list<int>, need: list<int>}
     */
    private function cover(array $shifts, string $day, string $date, string $room): array
    {
        $steps = $this->demandWeek[$day][$room] ?? [];
        $have = [];
        $need = [];

        foreach ($this->slices() as $minute) {
            $have[] = count(array_filter($shifts, fn ($s) => $s['classroom'] === $room && $s['starts_at'] <= $minute && $s['ends_at'] > $minute && ! $this->busyAt($s['user_id'], $date, $minute)));
            $need[] = $this->demand->staffNeeded($steps, $room, $minute);
        }

        return ['have' => $have, 'need' => $need];
    }

    /** Runs of true in a list of flags, as [from index, to index) pairs. */
    private function runs(array $flags): array
    {
        $out = [];
        $start = null;

        foreach ($flags as $i => $flag) {
            if ($flag && $start === null) {
                $start = $i;
            }
            if (! $flag && $start !== null) {
                $out[] = [$start, $i];
                $start = null;
            }
        }

        if ($start !== null) {
            $out[] = [$start, count($flags)];
        }

        return $out;
    }

    /** "9a–11:30a" for a run of slices. */
    private function runText(array $run): string
    {
        $slices = $this->slices();

        return StaffRule::compactTime($slices[$run[0]]).'–'.StaffRule::compactTime($slices[$run[1] - 1] + $this->step);
    }

    private function runMinutes(array $run): array
    {
        $slices = $this->slices();

        return [$slices[$run[0]], $slices[$run[1] - 1] + $this->step];
    }

    /* ---------------------------------------------------------------
     * Must and Prefer
     * --------------------------------------------------------------- */

    /**
     * Every Must rule the day's shifts would break, in plain words.
     *
     * Must is what the generator treats as hard: a forbidden room, a day or
     * hours the rules close, time off and leave, two people who are never
     * scheduled together, somebody who must never be the only adult in a
     * room, and one person in two rooms at once.
     *
     * @return list<string>
     */
    public function mustErrors(array $shifts, string $day, string $date): array
    {
        $errors = [];
        $names = $this->staff->pluck('name', 'id');
        $dayName = ucfirst(strtolower($day));

        foreach ($shifts as $s) {
            $person = $this->person($s['user_id']);

            if (! $person) {
                continue;
            }

            if ($this->forbidden($person, $s['classroom'])) {
                $errors[] = "{$person->name} is never assigned to {$s['classroom']}.";
            }

            if ($leave = $this->leave[$person->id][$date] ?? null) {
                $errors[] = "{$person->name} is on approved ".strtolower($leave->label()).' that day.';
            }

            $entry = $this->timeOff[$person->id][$date] ?? null;
            $offSaid = false;

            if ($entry && $entry->kind !== 'extra') {
                foreach ($this->busy($person->id, $date) as [$a, $b]) {
                    if ($s['starts_at'] < $b && $s['ends_at'] > $a && ! ($this->leave[$person->id][$date] ?? null)) {
                        $errors[] = "{$person->name} is ".lcfirst($entry->describe()).' ('.strtolower($entry->reasonLabel()).').';
                        $offSaid = true;
                        break;
                    }
                }
            }

            $window = $this->window($person, $day, $date);

            // The time off narrows the window too; one line for one fact.
            if ($window === null) {
                if (! $entry && ! ($this->leave[$person->id][$date] ?? null)) {
                    $errors[] = "{$person->name} can’t work {$dayName}.";
                }
            } elseif (! $offSaid && ($s['starts_at'] < $window['start'] || $s['ends_at'] > $window['end'])) {
                $errors[] = "{$person->name} only works ".StaffRule::compactTime($window['start']).'–'.StaffRule::compactTime($window['end']).' that day.';
            }

            // The same person twice at once: a drop that overlaps their own shift.
            foreach ($shifts as $o) {
                if ($o !== $s && $o['user_id'] === $s['user_id'] && $o['starts_at'] < $s['ends_at'] && $s['starts_at'] < $o['ends_at'] && ($o['id'] ?? 0) < ($s['id'] ?? 0)) {
                    $errors[] = "{$person->name} would be in two rooms at once.";
                }
            }
        }

        // Never together in a room.
        foreach ($this->noPair as $a => $others) {
            foreach ($others as $b) {
                if ($a >= $b) {
                    continue;
                }
                foreach (self::rooms() as $room) {
                    $together = collect($this->slices())->contains(function ($minute) use ($shifts, $a, $b, $room) {
                        $in = fn ($id) => collect($shifts)->contains(fn ($s) => $s['user_id'] === $id && $s['classroom'] === $room && $s['starts_at'] <= $minute && $s['ends_at'] > $minute);

                        return $in($a) && $in($b);
                    });

                    if ($together) {
                        $errors[] = "{$names[$a]} and {$names[$b]} can’t share a room ({$room}).";
                    }
                }
            }
        }

        // Never the only adult in a room.
        foreach ($this->staff as $person) {
            if (! $this->hasRule($person, 'NEEDS_SUPERVISION')) {
                continue;
            }
            foreach (self::rooms() as $room) {
                $alone = array_map(function ($minute) use ($shifts, $person, $room) {
                    $there = array_filter($shifts, fn ($s) => $s['classroom'] === $room && $s['starts_at'] <= $minute && $s['ends_at'] > $minute);

                    return count($there) === 1 && reset($there)['user_id'] === $person->id;
                }, $this->slices());

                foreach ($this->runs($alone) as $run) {
                    $errors[] = "{$person->name} would be the only adult in {$room} ".$this->runText($run).'.';
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * The soft rules the day's shifts go against. Reported, never blocking.
     *
     * @return list<string>
     */
    public function preferNotes(array $shifts, string $day): array
    {
        $notes = [];

        foreach ($this->staff as $person) {
            $mine = array_filter($shifts, fn ($s) => $s['user_id'] === $person->id);

            if ($mine === []) {
                continue;
            }

            foreach ($person->staffRules->reject(fn (StaffRule $r) => $r->isHard()) as $rule) {
                if ($rule->rule_type === 'ROOM_PREFERENCE' && filled($rule->value_text)) {
                    $elsewhere = array_filter($mine, fn ($s) => $s['classroom'] !== $rule->value_text);
                    if ($elsewhere !== []) {
                        $notes[] = "{$person->name} prefers {$rule->value_text}.";
                    }
                }

                if ($rule->rule_type === 'PREFERRED_DAY_OFF' && $rule->appliesOn($day)) {
                    $notes[] = "{$person->name} prefers ".ucfirst(strtolower($day)).' off.';
                }

                if ($rule->rule_type === 'PREFERRED_START' && $rule->time_1 !== null && min(array_column($mine, 'starts_at')) !== (int) $rule->time_1) {
                    $notes[] = "{$person->name} prefers to start at ".StaffRule::formatTime((int) $rule->time_1).'.';
                }
            }
        }

        return array_values(array_unique($notes));
    }

    /* ---------------------------------------------------------------
     * One day, for the By-room view
     * --------------------------------------------------------------- */

    public function day(string $weekStart, string $day): array
    {
        $this->load($weekStart);
        $date = $this->date($day);
        $shifts = $this->shiftsOn($day);
        $dateObj = Carbon::parse($date);

        $rooms = [];
        $issues = [];
        $shortRuns = [];

        foreach (self::rooms() as $room) {
            $cv = $this->cover($shifts, $day, $date, $room);
            $steps = $this->demandWeek[$day][$room] ?? [];
            $peak = $this->demand->peak($steps);
            $ratio = config('daycare.ratios')[$room] ?? null;
            $cap = config('daycare.capacity')[$room] ?? null;
            [$fill, $border, $text] = self::colour($room);

            $lanes = [];
            foreach (array_filter($shifts, fn ($s) => $s['classroom'] === $room) as $s) {
                $person = $this->person($s['user_id']);
                $multi = collect($shifts)->contains(fn ($o) => $o['user_id'] === $s['user_id'] && $o['classroom'] !== $room);
                $offs = array_map(fn ($b) => ['from' => max($this->open, $b[0]), 'to' => min($this->close, $b[1]), 'label' => $b[2]], $this->busy($s['user_id'], $date));
                $lanes[] = [
                    'id' => $s['id'],
                    'user_id' => $s['user_id'],
                    'name' => $person?->name ?? 'Unknown',
                    'from' => $s['starts_at'],
                    'to' => $s['ends_at'],
                    'label' => StaffRule::compactTime($s['starts_at']).' → '.StaffRule::compactTime($s['ends_at']),
                    'title' => ($person?->name ?? 'Unknown').' · '.$room.' '.StaffRule::compactTime($s['starts_at']).'–'.StaffRule::compactTime($s['ends_at']).' — drag to another room',
                    'float' => $multi,
                    'conf' => $this->clashes($s, $date),
                    'offs' => $offs,
                ];
            }

            $bands = [];
            $i = 0;
            $n = count($cv['have']);
            $slices = $this->slices();
            while ($i < $n) {
                $j = $i;
                while ($j + 1 < $n && $cv['have'][$j + 1] === $cv['have'][$i] && $cv['need'][$j + 1] === $cv['need'][$i]) {
                    $j++;
                }
                $h = $cv['have'][$i];
                $k = $cv['need'][$i];
                $bands[] = [
                    'from' => $slices[$i], 'to' => $slices[$j] + $this->step, 'have' => $h, 'need' => $k,
                    't' => ($h === 0 && $k === 0) ? '' : $h.'/'.$k,
                    'cls' => ($h === 0 && $k === 0) ? 'cs-none' : ($h < $k ? 'cs-bad' : ($h > $k ? 'cs-over' : 'cs-ok')),
                    'tip' => StaffRule::compactTime($slices[$i]).'–'.StaffRule::compactTime($slices[$j] + $this->step).': '.$h.' staff, need '.$k,
                ];
                $i = $j + 1;
            }

            $short = $this->runs(array_map(fn ($h, $k) => $h < $k, $cv['have'], $cv['need']));
            foreach ($short as $run) {
                $issues[] = ['cls' => 'bad', 'text' => $room.' short '.$this->runText($run)];
                $shortRuns[] = ['room' => $room, 'run' => $run];
            }

            $rooms[] = [
                'key' => $room,
                'fill' => $fill, 'border' => $border, 'text' => $text,
                'ratio' => $ratio,
                'kidsLine' => $peak.' '.($peak === 1 ? 'child' : 'children').' at peak · ratio 1:'.($ratio ?? '—'),
                'capLine' => $cap ? $peak.' of '.$cap.' places' : $peak.' places',
                'capCls' => $cap && $peak > $cap ? 'bad' : ($cap && $peak === $cap ? 'warn' : 'mut'),
                'status' => $short ? 'Short' : 'At ratio',
                'stCls' => $short ? 'bad' : 'ok',
                'lanes' => $lanes,
                'empty' => $lanes === [],
                'cov' => $bands,
            ];
        }

        foreach ($this->mustErrors($shifts, $day, $date) as $error) {
            $issues[] = ['cls' => 'bad', 'text' => $error];
        }

        return [
            'day' => $day,
            'date' => $date,
            'dayName' => $dateObj->format('l, M j'),
            'dayShort' => $dateObj->format('D'),
            'dayLabel' => $dateObj->format('D j'),
            'closed' => $this->closed[$date] ?? null,
            'open' => $this->open,
            'close' => $this->close,
            'rooms' => $rooms,
            'issues' => $issues,
            'ok' => $issues === [] && ! isset($this->closed[$date]),
            'tray' => $this->tray($shifts, $day, $date),
            'conf' => $this->conflicts($shifts, $date),
            'cover' => $this->coverSuggestions($shifts, $day, $date, $shortRuns),
            'moveTo' => self::rooms(),
        ];
    }

    /**
     * Which days have something wrong on them — a room short, or a shift over
     * somebody's time off — for the red dot on the day chips.
     *
     * @return array<string, bool>
     */
    public function gaps(string $weekStart): array
    {
        $this->load($weekStart);
        $out = [];

        foreach (StaffScheduleWeek::datesOf($weekStart) as $day => $dateObj) {
            $date = $dateObj->toDateString();
            $shifts = $this->shiftsOn($day);
            $gap = collect($shifts)->contains(fn ($s) => $this->clashes($s, $date));

            foreach (self::rooms() as $room) {
                if ($gap) {
                    break;
                }
                $cv = $this->cover($shifts, $day, $date, $room);
                $gap = collect($cv['have'])->contains(fn ($h, $i) => $h < $cv['need'][$i]);
            }

            $out[$day] = $gap;
        }

        return $out;
    }

    private function clashes(array $shift, string $date): bool
    {
        foreach ($this->busy($shift['user_id'], $date) as [$a, $b]) {
            if ($shift['starts_at'] < $b && $shift['ends_at'] > $a) {
                return true;
            }
        }

        return false;
    }

    /** Everyone not working today, with why or whether they could. */
    private function tray(array $shifts, string $day, string $date): array
    {
        $working = array_flip(array_column($shifts, 'user_id'));
        $out = [];

        foreach ($this->staff as $person) {
            if (isset($working[$person->id]) || $person->isOffSchedule()) {
                continue;
            }

            $entry = $this->timeOff[$person->id][$date] ?? null;
            $leave = $this->leave[$person->id][$date] ?? null;
            $window = $this->window($person, $day, $date);
            $extra = $entry?->kind === 'extra' ? $entry : null;
            $ok = $window !== null && ! $leave && $entry?->kind !== 'all';

            $note = match (true) {
                (bool) $leave => 'Out · '.$leave->label(),
                $entry?->kind === 'all' => 'Out · '.$entry->reasonLabel(),
                (bool) $extra => 'Extra day · '.StaffRule::compactTime((int) $extra->from_minute).'–'.StaffRule::compactTime((int) $extra->to_minute),
                $window === null => 'Can’t work '.ucfirst(strtolower($day)),
                $entry?->kind === 'until' => 'Free after '.StaffRule::compactTime((int) $entry->from_minute),
                $entry?->kind === 'leave' => 'Free until '.StaffRule::compactTime((int) $entry->to_minute),
                $person->weeklyHours() <= 0 => 'No employment type',
                default => 'Available',
            };

            $out[] = ['user_id' => $person->id, 'name' => $person->name, 'note' => $note, 'ok' => $ok];
        }

        return $out;
    }

    /** Shifts that run into time off or leave, with the fix on offer. */
    private function conflicts(array $shifts, string $date): array
    {
        $out = [];

        foreach ($shifts as $s) {
            if (! $this->clashes($s, $date)) {
                continue;
            }

            $person = $this->person($s['user_id']);
            $keep = [$s['starts_at'], $s['ends_at']];

            foreach ($this->busy($s['user_id'], $date) as [$a, $b]) {
                if ($keep[0] < $b && $keep[1] > $a) {
                    if ($a <= $keep[0]) {
                        $keep[0] = $b;
                    } else {
                        $keep[1] = $a;
                    }
                }
            }

            $can = $keep[1] - $keep[0] >= 60;

            $out[] = [
                'id' => $s['id'],
                'title' => ($person?->name ?? 'Someone').' is '.$this->describeOff($s['user_id'], $date),
                'detail' => 'Scheduled in '.$s['classroom'].' '.StaffRule::compactTime($s['starts_at']).'–'.StaffRule::compactTime($s['ends_at']).'.'.($can ? ' Keep only '.StaffRule::compactTime($keep[0]).'–'.StaffRule::compactTime($keep[1]).'?' : ' The whole shift is affected.'),
                'fixLabel' => $can ? 'Keep '.StaffRule::compactTime($keep[0]).'–'.StaffRule::compactTime($keep[1]) : 'Remove shift',
                'keep' => $can ? $keep : null,
            ];
        }

        return $out;
    }

    /**
     * Who is free for a stretch and breaks no Must rule standing in that room.
     *
     * @return list<User>
     */
    private function free(array $shifts, string $day, string $date, int $from, int $to, string $room): array
    {
        $out = [];

        foreach ($this->staff as $person) {
            if ($person->isOffSchedule() || $person->weeklyHours() <= 0 || $this->hasRule($person, 'NEEDS_SUPERVISION')) {
                continue;
            }
            if ($this->forbidden($person, $room) || ($this->leave[$person->id][$date] ?? null)) {
                continue;
            }

            $window = $this->window($person, $day, $date);
            if ($window === null || $from < $window['start'] || $to > $window['end']) {
                continue;
            }

            $busy = collect($this->busy($person->id, $date))->contains(fn ($b) => $from < $b[1] && $to > $b[0]);
            $working = collect($shifts)->contains(fn ($s) => $s['user_id'] === $person->id && $s['starts_at'] < $to && $s['ends_at'] > $from);
            if ($busy || $working) {
                continue;
            }

            $pairClash = collect($this->noPair[$person->id] ?? [])->contains(
                fn ($other) => collect($shifts)->contains(fn ($s) => $s['user_id'] === $other && $s['classroom'] === $room && $s['starts_at'] < $to && $s['ends_at'] > $from)
            );
            if ($pairClash) {
                continue;
            }

            $out[] = $person;
        }

        return $out;
    }

    private function coverSuggestions(array $shifts, string $day, string $date, array $shortRuns): array
    {
        $out = [];

        foreach ($shortRuns as ['room' => $room, 'run' => $run]) {
            [$from, $to] = $this->runMinutes($run);
            $who = [];

            foreach (array_slice($this->free($shifts, $day, $date, $from, $to, $room), 0, 4) as $person) {
                $entry = $this->timeOff[$person->id][$date] ?? null;
                $who[] = [
                    'user_id' => $person->id,
                    'name' => $person->name,
                    'note' => $entry?->kind === 'extra' ? 'extra day' : (collect($shifts)->contains(fn ($s) => $s['user_id'] === $person->id) ? 'free then' : 'not working '.ucfirst(strtolower($day))),
                ];
            }

            $out[] = ['room' => $room, 'when' => $this->runText($run), 'from' => $from, 'to' => $to, 'who' => $who, 'none' => $who === []];
        }

        return $out;
    }

    /* ---------------------------------------------------------------
     * A change: what it would do, and doing it
     * --------------------------------------------------------------- */

    /**
     * The day as it would be after a change, with the choices the change
     * offers and the one taken.
     *
     * @return array{after: list<array>, title: string, opts: list<array>, opt: string, person: ?User, kind: string, change: array}
     */
    private function propose(array $shifts, string $day, string $date, array $change): array
    {
        $kind = $change['kind'];
        $byId = collect($shifts)->keyBy('id');

        if ($kind === 'move') {
            $s = $byId->get((int) $change['id']) ?? throw new \InvalidArgumentException('That shift is not on this day any more. Reload and try again.');
            $person = $this->person($s['user_id']);
            $to = $change['to'];

            $opts = [['k' => 'whole', 'label' => 'Whole shift '.StaffRule::compactTime($s['starts_at']).'–'.StaffRule::compactTime($s['ends_at'])]];
            foreach (range(11 * 60, 16 * 60, 60) as $t) {
                if ($t > $s['starts_at'] + 30 && $t < $s['ends_at'] - 30) {
                    $opts[] = ['k' => (string) $t, 'label' => 'From '.StaffRule::compactTime($t)];
                }
            }
            $opt = (string) ($change['opt'] ?? 'whole');
            if (! collect($opts)->contains(fn ($o) => $o['k'] === $opt)) {
                $opt = 'whole';
            }

            $after = array_values(array_filter($shifts, fn ($o) => $o['id'] !== $s['id']));
            if ($opt === 'whole') {
                $after[] = ['classroom' => $to] + $s;
            } else {
                $after[] = ['ends_at' => (int) $opt] + $s;
                $after[] = ['id' => null, 'user_id' => $s['user_id'], 'classroom' => $to, 'starts_at' => (int) $opt, 'ends_at' => $s['ends_at'], 'role' => StaffShift::ROLE_FLOAT];
            }

            return ['after' => $after, 'title' => 'Move '.$person?->name.' from '.$s['classroom'].' to '.$to, 'opts' => $opts, 'opt' => $opt, 'person' => $person, 'kind' => $kind, 'change' => $change + ['opt' => $opt, 'shift' => $s]];
        }

        if ($kind === 'add') {
            $person = $this->person((int) $change['user_id']) ?? throw new \InvalidArgumentException('That person is not on the team.');
            $to = $change['to'];
            $cv = $this->cover($shifts, $day, $date, $to);
            $short = $this->runs(array_map(fn ($h, $k) => $h < $k, $cv['have'], $cv['need']));

            if ($short !== []) {
                [$a] = $this->runMinutes($short[0]);
                [, $b] = $this->runMinutes($short[count($short) - 1]);
                $fit = [$a, min($b, $a + 9 * 60)];
            } else {
                $fit = [9 * 60, 17 * 60];
            }

            // Trimmed to when they can be there.
            $window = $this->window($person, $day, $date);
            $clip = function (array $t) use ($window, $person, $date) {
                if ($window) {
                    $t = [max($t[0], $window['start']), min($t[1], $window['end'])];
                }
                foreach ($this->busy($person->id, $date) as [$a, $b]) {
                    if ($t[0] < $b && $t[1] > $a) {
                        if ($a <= $t[0]) {
                            $t[0] = $b;
                        } else {
                            $t[1] = $a;
                        }
                    }
                }

                return $t;
            };

            $times = ['fit' => $clip($fit), 'am' => $clip([9 * 60, 15 * 60]), 'pm' => $clip([12 * 60, 18 * 60])];
            $opts = [];
            foreach ($times as $k => $t) {
                if ($t[1] - $t[0] < $this->step) {
                    continue;
                }
                $opts[] = ['k' => $k, 'label' => StaffRule::compactTime($t[0]).'–'.StaffRule::compactTime($t[1]).($k === 'fit' && $short !== [] ? ' · covers the gap' : '')];
            }
            if ($opts === []) {
                $opts[] = ['k' => 'fit', 'label' => StaffRule::compactTime($fit[0]).'–'.StaffRule::compactTime($fit[1])];
                $times['fit'] = $fit;
            }

            $opt = (string) ($change['opt'] ?? 'fit');
            if (! collect($opts)->contains(fn ($o) => $o['k'] === $opt)) {
                $opt = $opts[0]['k'];
            }
            $t = $times[$opt];

            $home = $person->classroom ?: $person->title;
            $after = $shifts;
            $after[] = ['id' => null, 'user_id' => $person->id, 'classroom' => $to, 'starts_at' => $t[0], 'ends_at' => $t[1], 'role' => $person->isSubstitute() ? StaffShift::ROLE_PATCH : ($home === $to ? StaffShift::ROLE_STAFF : StaffShift::ROLE_FLOAT)];

            return ['after' => $after, 'title' => 'Add '.$person->name.' to '.$to.' on '.Carbon::parse($date)->format('D j'), 'opts' => $opts, 'opt' => $opt, 'person' => $person, 'kind' => $kind, 'change' => $change + ['opt' => $opt, 'from' => $t[0], 'to_minute' => $t[1]]];
        }

        if ($kind === 'trim' || $kind === 'remove') {
            $s = $byId->get((int) $change['id']) ?? throw new \InvalidArgumentException('That shift is not on this day any more. Reload and try again.');
            $person = $this->person($s['user_id']);
            $after = array_values(array_filter($shifts, fn ($o) => $o['id'] !== $s['id']));

            if ($kind === 'trim') {
                $from = (int) $change['from'];
                $to = (int) $change['to'];
                if ($to - $from < $this->step || $from < $s['starts_at'] || $to > $s['ends_at']) {
                    throw new \InvalidArgumentException('That is not a stretch of this shift.');
                }
                $after[] = ['starts_at' => $from, 'ends_at' => $to] + $s;
                $title = 'Keep '.$person?->name.' '.StaffRule::compactTime($from).'–'.StaffRule::compactTime($to).' in '.$s['classroom'];
            } else {
                $title = 'Remove '.$person?->name.' from '.$s['classroom'].' '.StaffRule::compactTime($s['starts_at']).'–'.StaffRule::compactTime($s['ends_at']);
            }

            return ['after' => $after, 'title' => $title, 'opts' => [], 'opt' => '', 'person' => $person, 'kind' => $kind, 'change' => $change + ['shift' => $s]];
        }

        throw new \InvalidArgumentException('Unknown change.');
    }

    /**
     * What a change does, before it is saved: the Must rules it would break,
     * every room that goes short or over or gets its gap filled, the hours it
     * puts somebody over, and the preferences it goes against.
     */
    public function preview(string $weekStart, string $day, array $change): array
    {
        $this->load($weekStart);
        $date = $this->date($day);
        $before = $this->shiftsOn($day);
        $p = $this->propose($before, $day, $date, $change);
        $after = $p['after'];

        $impact = [];
        $was = $this->mustErrors($before, $day, $date);
        $errors = array_values(array_diff($this->mustErrors($after, $day, $date), $was));

        foreach ($errors as $e) {
            $impact[] = ['tag' => 'Must', 'cls' => 'bad', 'text' => $e];
        }

        foreach (self::rooms() as $room) {
            $b4 = $this->cover($before, $day, $date, $room);
            $af = $this->cover($after, $day, $date, $room);
            $n = count($af['have']);

            foreach ($this->runs(array_map(fn ($i) => $af['have'][$i] < $af['need'][$i] && $b4['have'][$i] >= $b4['need'][$i], range(0, $n - 1))) as $run) {
                $impact[] = ['tag' => 'Short', 'cls' => 'bad', 'text' => $room.' drops below ratio '.$this->runText($run).' ('.$af['have'][$run[0]].' of '.$af['need'][$run[0]].' staff).'];
            }
            foreach ($this->runs(array_map(fn ($i) => $b4['have'][$i] < $b4['need'][$i] && $af['have'][$i] >= $af['need'][$i], range(0, $n - 1))) as $run) {
                $impact[] = ['tag' => 'Fixed', 'cls' => 'ok', 'text' => 'Fills the '.$room.' gap '.$this->runText($run).'.'];
            }
            foreach ($this->runs(array_map(fn ($i) => $af['have'][$i] > $af['need'][$i] && $af['have'][$i] > $b4['have'][$i], range(0, $n - 1))) as $run) {
                $impact[] = ['tag' => 'Over', 'cls' => 'info', 'text' => $room.' would have '.($af['have'][$run[0]] - $af['need'][$run[0]]).' more staff than needed '.$this->runText($run).'.'];
            }
        }

        if ($p['person']) {
            $target = $p['person']->weeklyHours();
            $weekBefore = $this->weekMinutes($p['person']->id, $day, $before);
            $weekAfter = $this->weekMinutes($p['person']->id, $day, $after);
            $cap = max(40 * 60, $target * 60);

            if ($weekAfter > $cap && $weekAfter > $weekBefore) {
                $impact[] = ['tag' => 'Hours', 'cls' => 'warn', 'text' => $p['person']->name.' would be scheduled '.round($weekAfter / 60, 1).' h this week (over '.round($cap / 60).').'];
            }
        }

        foreach (array_diff($this->preferNotes($after, $day), $this->preferNotes($before, $day)) as $note) {
            $impact[] = ['tag' => 'Prefer', 'cls' => 'warn', 'text' => $note.' Reported only — you can still save.'];
        }

        $order = ['Must' => 0, 'Short' => 1, 'Over' => 2, 'Hours' => 3, 'Prefer' => 4, 'Fixed' => 5];
        usort($impact, fn ($a, $b) => $order[$a['tag']] <=> $order[$b['tag']]);

        if ($impact === []) {
            $impact[] = ['tag' => 'OK', 'cls' => 'ok', 'text' => 'No change to coverage in any room.'];
        }

        return [
            'title' => $p['title'],
            'opts' => array_map(fn ($o) => ['k' => $o['k'], 'label' => $o['label'], 'on' => $o['k'] === $p['opt']], $p['opts']),
            'opt' => $p['opt'],
            'impact' => $impact,
            'blocked' => $errors !== [],
            'applyLabel' => match ($p['kind']) { 'add' => 'Add shift', 'move' => 'Move', 'trim' => 'Keep', default => 'Remove' },
            'kind' => $p['kind'],
        ];
    }

    /** This person's minutes across the week, with one day replaced. */
    private function weekMinutes(int $userId, string $day, array $dayShifts): int
    {
        $others = StaffShift::where('week_start', $this->weekStart)->where('user_id', $userId)->where('day', '!=', $day)->get()
            ->sum(fn (StaffShift $s) => $s->minutes());

        return (int) $others + array_sum(array_map(fn ($s) => $s['ends_at'] - $s['starts_at'], array_filter($dayShifts, fn ($s) => $s['user_id'] === $userId)));
    }

    /**
     * Save a change — the same one preview() described — unless it is
     * blocked by a Must rule.
     *
     * @return array The day afterwards, as day() hands it over.
     */
    public function apply(string $weekStart, string $day, array $change, User $actor): array
    {
        $this->load($weekStart);
        $date = $this->date($day);
        $before = $this->shiftsOn($day);
        $p = $this->propose($before, $day, $date, $change);

        $errors = array_values(array_diff($this->mustErrors($p['after'], $day, $date), $this->mustErrors($before, $day, $date)));

        if ($errors !== []) {
            throw new \DomainException('Blocked by a Must rule: '.implode(' ', $errors));
        }

        DB::transaction(function () use ($weekStart, $day, $date, $p) {
            $c = $p['change'];
            $base = ['week_start' => $weekStart, 'shift_date' => $date, 'day' => $day];

            if ($p['kind'] === 'move') {
                $shift = StaffShift::findOrFail($c['shift']['id']);
                $person = $this->person($shift->user_id);
                $home = $person?->classroom ?: $person?->title;

                if ($c['opt'] === 'whole') {
                    $shift->update(['classroom' => $c['to'], 'role' => $shift->role === StaffShift::ROLE_PATCH ? $shift->role : ($home === $c['to'] ? StaffShift::ROLE_STAFF : StaffShift::ROLE_FLOAT)]);
                } else {
                    $end = $shift->ends_at;
                    $shift->update(['ends_at' => (int) $c['opt']]);
                    StaffShift::create($base + ['user_id' => $shift->user_id, 'starts_at' => (int) $c['opt'], 'ends_at' => $end, 'classroom' => $c['to'], 'role' => StaffShift::ROLE_FLOAT]);
                }
            } elseif ($p['kind'] === 'add') {
                $new = collect($p['after'])->last();
                StaffShift::create($base + ['user_id' => $new['user_id'], 'starts_at' => $new['starts_at'], 'ends_at' => $new['ends_at'], 'classroom' => $new['classroom'], 'role' => $new['role']]);
            } elseif ($p['kind'] === 'trim') {
                StaffShift::findOrFail($c['shift']['id'])->update(['starts_at' => (int) $c['from'], 'ends_at' => (int) $c['to']]);
            } else {
                StaffShift::findOrFail($c['shift']['id'])->delete();
            }

            StaffScheduleWeek::firstOrCreate(['week_start' => $weekStart], ['generated_at' => null])->noteEdit();
        });

        return $this->day($weekStart, $day);
    }

    /* ---------------------------------------------------------------
     * The week, for the By-teacher view
     * --------------------------------------------------------------- */

    /**
     * One row per person: their shifts each day as bars and as lines, the
     * day's reason where there are none, and the week's hours.
     */
    public function week(string $weekStart): array
    {
        $this->load($weekStart);
        $dates = StaffScheduleWeek::datesOf($weekStart);
        $shifts = StaffShift::where('week_start', $weekStart)->orderBy('starts_at')->get()->groupBy('user_id');
        $rows = [];

        foreach ($this->staff as $person) {
            $mine = $shifts->get($person->id, collect());

            if ($person->isOffSchedule() && $mine->isEmpty()) {
                continue;
            }

            $minutes = 0;
            $days = [];

            foreach ($dates as $day => $dateObj) {
                $date = $dateObj->toDateString();
                $segs = $mine->where('day', $day)->sortBy('starts_at')->values();
                $multi = $segs->pluck('classroom')->unique()->count() > 1;
                $minutes += $segs->sum(fn (StaffShift $s) => $s->minutes());
                $busy = $this->busy($person->id, $date);
                $entry = $this->timeOff[$person->id][$date] ?? null;
                $leave = $this->leave[$person->id][$date] ?? null;

                $offText = match (true) {
                    isset($this->closed[$date]) => $this->closed[$date],
                    (bool) $leave => $leave->label().' · approved leave',
                    $entry?->kind === 'all' => 'Out · '.$entry->reasonLabel(),
                    $segs->isEmpty() && $this->window($person, $day, $date) === null => 'Can’t work',
                    default => 'Off',
                };

                $bars = [];
                if ($segs->isNotEmpty()) {
                    foreach ($busy as [$a, $b, $label]) {
                        $bars[] = ['from' => max($this->open, $a), 'to' => min($this->close, $b), 'off' => true, 'label' => $label, 'title' => 'Time off · '.$label, 'fill' => '', 'border' => '#cfd3de', 'text' => '#5f6676', 'float' => false];
                    }
                }
                foreach ($segs as $s) {
                    [$fill, $border, $text] = self::colour($s->classroom);
                    $bars[] = ['from' => $s->starts_at, 'to' => $s->ends_at, 'off' => false, 'label' => StaffRule::compactTime($s->starts_at).' → '.StaffRule::compactTime($s->ends_at), 'title' => $s->classroom.' '.StaffRule::compactTime($s->starts_at).'–'.StaffRule::compactTime($s->ends_at), 'fill' => $fill, 'border' => $border, 'text' => $text, 'float' => $multi];
                }

                $lines = $segs->map(fn (StaffShift $s) => ['time' => StaffRule::compactTime($s->starts_at).' – '.StaffRule::compactTime($s->ends_at), 'room' => $s->classroom, 'color' => self::colour($s->classroom)[2]])->all();
                if ($entry && $entry->kind !== 'all' && $segs->isNotEmpty()) {
                    // The short form for a table cell: "Out until 3:00 PM",
                    // not the picker's longer sentence.
                    $when = match ($entry->kind) {
                        'until' => 'Out until '.StaffRule::formatTime((int) $entry->from_minute),
                        'leave' => 'Leaving at '.StaffRule::formatTime((int) $entry->to_minute),
                        'window' => 'Only '.StaffRule::formatTime((int) $entry->from_minute).' – '.StaffRule::formatTime((int) $entry->to_minute),
                        default => $entry->describe(),
                    };
                    $lines[] = ['time' => $when, 'room' => '· '.$entry->reasonLabel(), 'color' => '#8a90a0'];
                }

                $days[$day] = ['off' => $segs->isEmpty(), 'offText' => $offText, 'bars' => $bars, 'lines' => $lines];
            }

            $target = $person->weeklyHours();
            $rows[] = [
                'id' => $person->id,
                'name' => $person->name,
                'role' => ($person->employmentLabel() === 'Not set' ? 'Type not set' : $person->employmentLabel()).($this->hasRule($person, 'CAN_OPEN') ? ' · opens' : ''),
                'days' => $days,
                'hrs' => rtrim(rtrim(number_format($minutes / 60, 1), '0'), '.').' h',
                'hCls' => $minutes > max(40 * 60, $target * 60) ? 'warn' : 'mut',
            ];
        }

        return $rows;
    }

    /**
     * Time off entered from a bar on the schedule: the same entry the Staff
     * page writes, so it shows there too.
     */
    public function timeOff(User $person, string $date, string $kind, ?int $minute, string $reason, User $actor): StaffTimeOff
    {
        return $person->timeOff()->create([
            'kind' => $kind,
            'date' => $date,
            'from_minute' => $kind === 'until' ? $minute : null,
            'to_minute' => $kind === 'leave' ? $minute : null,
            'reason' => $reason,
            'paid' => in_array($reason, ['sick', 'vacation'], true),
            'note' => 'Added from the Schedule by '.$actor->name,
            'created_by' => $actor->id,
        ]);
    }
}
