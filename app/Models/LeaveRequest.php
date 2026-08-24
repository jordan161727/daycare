<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One person asking to be away, and what was decided about it.
 *
 * A request is stored as the range that was asked for rather than as a row per
 * day, because that is how it is asked for and how it is argued about — "the
 * week of the 10th" is one decision, not five. The days it actually costs are
 * worked out from the range every time they are needed, so a closure day
 * declared after the request was filed stops consuming somebody's vacation
 * without anybody having to remember to go back and edit it.
 */
class LeaveRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    /** Withdrawn by the person who asked, or revoked after approval. */
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id', 'leave_type', 'starts_on', 'ends_on', 'hours_per_day',
        'status', 'reason', 'paid_hours', 'unpaid_hours',
        'reviewed_by', 'reviewed_at', 'decision_note',
    ];

    protected $casts = [
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
        'hours_per_day' => 'float',
        'paid_hours' => 'float',
        'unpaid_hours' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The director who approved or denied it. Null while it is pending. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Requests that hold hours: an approved one does, a pending one may yet. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /** Requests touching a date range at all, however little they overlap it. */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('starts_on', '<=', $to)->where('ends_on', '>=', $from);
    }

    public function label(): string
    {
        return config('daycare.leave.types')[$this->leave_type] ?? $this->leave_type;
    }

    /** The code payroll knows this leave by. */
    public function timesheetCode(): ?string
    {
        return config('daycare.leave.timesheet_codes')[$this->leave_type] ?? null;
    }

    public function minutesPerDay(): int
    {
        return (int) round($this->hours_per_day * 60);
    }

    /**
     * The dates this request actually costs.
     *
     * Weekends and days the centre is closed are skipped: nobody spends
     * vacation on a Sunday they were never rostered for, and a snow day
     * declared in the middle of somebody's week is the centre's day off rather
     * than theirs. The reference is the operating week in config, so a centre
     * that opens on Saturdays gets Saturdays counted without a code change.
     *
     * @return list<string>
     */
    public function workingDates(): array
    {
        $days = config('daycare.days');
        $closed = array_flip(self::closedDates(
            $this->starts_on->toDateString(),
            $this->ends_on->toDateString(),
        ));

        $dates = [];

        for ($date = $this->starts_on->copy(); $date->lte($this->ends_on); $date->addDay()) {
            $string = $date->toDateString();

            if (! in_array(strtoupper($date->format('D')), $days, true) || isset($closed[$string])) {
                continue;
            }

            $dates[] = $string;
        }

        return $dates;
    }

    public function days(): int
    {
        return count($this->workingDates());
    }

    /** What the request is worth in total, before anything pays for it. */
    public function hours(): float
    {
        return round($this->days() * $this->hours_per_day, 2);
    }

    /**
     * Split the days into the ones a balance covers and the ones it does not.
     *
     * Whole days, in date order. A balance that covers three and a half days of
     * a four-day request pays three of them and leaves the fourth unpaid rather
     * than paying half of it, because a timesheet day carries one leave code
     * and a half-paid absence is a conversation nobody wants to have with a
     * payslip already in their hand. The unspent half-day stays on the balance.
     *
     * @return array{paid: list<string>, unpaid: list<string>, paid_hours: float, unpaid_hours: float}
     */
    public function allocate(float $available): array
    {
        $paid = [];
        $unpaid = [];
        $budget = max(0.0, $available);

        foreach ($this->workingDates() as $date) {
            if ($this->hours_per_day > 0 && $budget + 0.001 >= $this->hours_per_day) {
                $budget -= $this->hours_per_day;
                $paid[] = $date;

                continue;
            }

            $unpaid[] = $date;
        }

        return [
            'paid' => $paid,
            'unpaid' => $unpaid,
            'paid_hours' => round(count($paid) * $this->hours_per_day, 2),
            'unpaid_hours' => round(count($unpaid) * $this->hours_per_day, 2),
        ];
    }

    /**
     * The days of an approved request the balance paid for.
     *
     * Rebuilt from the hours recorded at the decision rather than stored as a
     * list, so it can only ever agree with what came off the balance. The order
     * is the order allocate() spends in, which is why the two stay in step.
     *
     * @return list<string>
     */
    public function paidDates(): array
    {
        $dates = $this->workingDates();

        return array_slice($dates, 0, $this->paidDayCount(count($dates)));
    }

    /** The rest: approved, taken, and not paid for. @return list<string> */
    public function unpaidDates(): array
    {
        $dates = $this->workingDates();

        return array_slice($dates, $this->paidDayCount(count($dates)));
    }

    /** The timesheet code one date of this request should be paid under. */
    public function codeFor(string $date): string
    {
        return in_array($date, $this->paidDates(), true)
            ? ($this->timesheetCode() ?? 'UNPAID')
            : 'UNPAID';
    }

    private function paidDayCount(int $available): int
    {
        if ($this->hours_per_day <= 0) {
            return 0;
        }

        return min($available, (int) round($this->paid_hours / $this->hours_per_day));
    }

    /** "Mon 24 Aug 2026" for one day, "Mon 24 – Fri 28 Aug 2026" for a stretch. */
    public function rangeLabel(): string
    {
        if ($this->starts_on->isSameDay($this->ends_on)) {
            return $this->starts_on->format('D j M Y');
        }

        return $this->starts_on->format('D j M').' – '.$this->ends_on->format('D j M Y');
    }

    /**
     * Approved leave in a date range, as minutes per person per date.
     *
     * The one shape the scheduler needs: is this person out on this day, and
     * for how long. Built once and read many times, because asking it per
     * teacher per day is thirty queries for a week that could be one.
     *
     * @return array<int, array<string, int>>
     */
    public static function minutesByUserAndDate(string $from, string $to): array
    {
        $map = [];

        foreach (self::approved()->overlapping($from, $to)->get() as $request) {
            foreach ($request->workingDates() as $date) {
                if ($date < $from || $date > $to) {
                    continue;
                }

                $map[$request->user_id][$date] = ($map[$request->user_id][$date] ?? 0) + $request->minutesPerDay();
            }
        }

        return $map;
    }

    /**
     * The same leave, kept whole, for the screens that name it.
     *
     * A roster cell says "Vacation", not "480 minutes of something".
     *
     * @return array<int, array<string, self>>
     */
    public static function byUserAndDate(string $from, string $to): array
    {
        $map = [];

        foreach (self::approved()->overlapping($from, $to)->get() as $request) {
            foreach ($request->workingDates() as $date) {
                if ($date >= $from && $date <= $to) {
                    $map[$request->user_id][$date] = $request;
                }
            }
        }

        return $map;
    }

    /**
     * Live requests for one person that clash with a proposed range.
     *
     * Asking twice for the same Tuesday is a slip rather than a plan, and two
     * approved requests over one day would spend the balance for it twice.
     *
     * @return Collection<int, self>
     */
    public static function clashesFor(int $userId, string $from, string $to, ?int $ignore = null): Collection
    {
        return self::query()
            ->where('user_id', $userId)
            ->live()
            ->overlapping($from, $to)
            ->when($ignore, fn (Builder $query) => $query->whereKeyNot($ignore))
            ->get();
    }

    /** Dates the centre is shut inside a range. */
    private static function closedDates(string $from, string $to): array
    {
        return ClosureDay::whereBetween('closed_on', [$from, $to])
            ->pluck('closed_on')
            ->map(fn (Carbon $date) => $date->toDateString())
            ->all();
    }
}
