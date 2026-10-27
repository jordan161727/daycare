<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to one person's day: out, out until, leaving at, only
 * available between, or an extra day — Phase 5's Time off tab.
 *
 * A rule is always true of somebody; this is true on the dates it names and
 * on no other. The schedule treats it as a Must for that date: an "out all
 * day" closes the day the way a day-off rule does, "out until 3" opens the
 * day at three, and so on (see StaffSchedule::windowFor). The clock on the
 * wall shows it to the person too, so somebody who is marked out until
 * three and arrives at nine sees the office's note before they press.
 */
class StaffTimeOff extends Model
{
    protected $table = 'staff_time_off';

    /** What kind of change, with the label and the hint the picker shows. */
    public const KINDS = [
        'all' => ['Out all day', 'Sick, vacation, personal'],
        'until' => ['Out until…', 'Arrives later (e.g. doctor)'],
        'leave' => ['Leaving at…', 'Goes home early'],
        'window' => ['Only available…', 'Between two times'],
        'extra' => ['Extra day', 'Can work a day they usually don’t'],
    ];

    public const REASONS = [
        'sick' => 'Sick',
        'vacation' => 'Vacation',
        'appointment' => 'Appointment',
        'personal' => 'Personal',
        'family' => 'Family',
        'extra' => 'Extra day',
        'other' => 'Other',
    ];

    /** The pill tone each reason wears. */
    public const REASON_TONES = [
        'sick' => 'bad', 'vacation' => 'info', 'appointment' => 'warn',
        'personal' => 'mut', 'family' => 'mut', 'extra' => 'ok', 'other' => 'mut',
    ];

    protected $fillable = [
        'user_id', 'date', 'until', 'kind', 'from_minute', 'to_minute',
        'reason', 'paid', 'note', 'created_by',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'until' => 'date:Y-m-d',
        'from_minute' => 'integer',
        'to_minute' => 'integer',
        'paid' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The last date this covers: `until` for a stretch, `date` for one day. */
    public function lastDate(): Carbon
    {
        return $this->until ?? $this->date;
    }

    public function coversDate(string $date): bool
    {
        return $date >= $this->date->toDateString() && $date <= $this->lastDate()->toDateString();
    }

    /** Still ahead, or running today. Past entries fold away by themselves. */
    public function isUpcoming(): bool
    {
        return $this->lastDate()->gte(today());
    }

    /** Entries touching a date. */
    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->where('date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('until')->where('date', $date)->orWhere('until', '>=', $date));
    }

    /** Entries touching any day in a range. */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('date', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('until')->where('date', '>=', $from)->orWhere('until', '>=', $from));
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind][0] ?? $this->kind;
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? ucfirst($this->reason);
    }

    public function reasonTone(): string
    {
        return self::REASON_TONES[$this->reason] ?? 'mut';
    }

    public function payLabel(): string
    {
        if ($this->kind === 'extra') {
            return '—';
        }

        return $this->paid ? 'Paid (PTO / sick)' : 'Unpaid';
    }

    /** "Out until 3:00 PM · available after", "Leaving at 12:00 PM", … */
    public function describe(): string
    {
        $from = StaffRule::formatTime($this->from_minute);
        $to = StaffRule::formatTime($this->to_minute);

        return match ($this->kind) {
            'until' => 'Out until '.$from.' · available after',
            'leave' => 'Leaving at '.$to,
            'window' => 'Only available '.$from.' – '.$to,
            'extra' => 'Extra day · available '.$from.' – '.$to,
            default => 'Out all day',
        };
    }

    /**
     * Narrow a day's window [start, end] (minutes) to what this leaves of it.
     *
     * Null closes the day. An extra day opens one where the rules had none.
     *
     * @param  array{start:int,end:int}|null  $window
     * @return array{start:int,end:int}|null
     */
    public function applyTo(?array $window): ?array
    {
        if ($this->kind === 'extra') {
            return $this->from_minute !== null && $this->to_minute !== null && $this->to_minute > $this->from_minute
                ? ['start' => $this->from_minute, 'end' => $this->to_minute]
                : $window;
        }

        if ($window === null || $this->kind === 'all') {
            return null;
        }

        [$start, $end] = [$window['start'], $window['end']];

        match ($this->kind) {
            'until' => $start = max($start, (int) $this->from_minute),
            'leave' => $end = min($end, (int) $this->to_minute),
            'window' => [$start, $end] = [max($start, (int) $this->from_minute), min($end, (int) $this->to_minute)],
            default => null,
        };

        return $end > $start ? ['start' => $start, 'end' => $end] + $window : null;
    }

    /**
     * What this does to the shift the person was given that day — the line
     * under the form and on the entry.
     *
     * @param  array{start:int,end:int,room:?string}|null  $shift
     * @return array{tag:string,tone:string,text:string}
     */
    public function impactOn(?array $shift, string $firstName): array
    {
        if ($this->kind === 'extra') {
            return ['tag' => 'Schedule', 'tone' => 'ok', 'text' => $firstName.' will show as available on '.$this->date->format('D, M j').' so you can add a shift.'];
        }

        if ($shift === null) {
            return ['tag' => 'Schedule', 'tone' => 'ok', 'text' => 'Not on the schedule that day — nothing to fix.'];
        }

        $room = $shift['room'] ?: 'their room';
        $was = StaffRule::formatTime($shift['start']).' – '.StaffRule::formatTime($shift['end']);
        $left = $this->applyTo(['start' => $shift['start'], 'end' => $shift['end']]);

        return match ($this->kind) {
            'all' => ['tag' => 'Conflict', 'tone' => 'bad', 'text' => 'Scheduled '.$was.' in '.$room.' on '.$this->date->format('D, M j').'. Saving removes the shift and the Schedule shows who could cover.'],
            'until' => match (true) {
                $this->from_minute >= $shift['end'] => ['tag' => 'Conflict', 'tone' => 'bad', 'text' => 'Scheduled '.$was.'. None of it is left — '.$room.' needs cover for the whole shift.'],
                $this->from_minute > $shift['start'] => ['tag' => 'Conflict', 'tone' => 'bad', 'text' => 'Scheduled '.$was.'. Only '.StaffRule::formatTime($this->from_minute).' – '.StaffRule::formatTime($shift['end']).' can stay — '.$room.' needs cover '.StaffRule::formatTime($shift['start']).' – '.StaffRule::formatTime($this->from_minute).'.'],
                default => ['tag' => 'OK', 'tone' => 'ok', 'text' => 'Already starts after that — no change.'],
            },
            'leave' => match (true) {
                $this->to_minute <= $shift['start'] => ['tag' => 'Conflict', 'tone' => 'bad', 'text' => 'Scheduled '.$was.'. None of it is left — '.$room.' needs cover for the whole shift.'],
                $this->to_minute < $shift['end'] => ['tag' => 'Conflict', 'tone' => 'bad', 'text' => 'Scheduled '.$was.'. Shift will end at '.StaffRule::formatTime($this->to_minute).' — '.$room.' needs cover '.StaffRule::formatTime($this->to_minute).' – '.StaffRule::formatTime($shift['end']).'.'],
                default => ['tag' => 'OK', 'tone' => 'ok', 'text' => 'Already leaves before that — no change.'],
            },
            default => $left === null
                ? ['tag' => 'Conflict', 'tone' => 'bad', 'text' => 'Scheduled '.$was.'. None of it fits the window — '.$room.' needs cover for the whole shift.']
                : ['tag' => 'Check', 'tone' => 'warn', 'text' => 'Scheduled '.$was.'. Any part outside '.StaffRule::formatTime($this->from_minute).' – '.StaffRule::formatTime($this->to_minute).' is flagged on the Schedule.'],
        };
    }

    /**
     * The entries that bite on each person on each date in a range, for the
     * scheduler: user id → date → entry.
     *
     * @return array<int, array<string, self>>
     */
    public static function byUserAndDate(string $from, string $to): array
    {
        $map = [];

        foreach (self::overlapping($from, $to)->orderBy('id')->get() as $entry) {
            for ($date = $entry->date->copy(); $date->lte($entry->lastDate()); $date->addDay()) {
                $key = $date->toDateString();
                if ($key >= $from && $key <= $to) {
                    $map[$entry->user_id][$key] = $entry;
                }
            }
        }

        return $map;
    }
}
