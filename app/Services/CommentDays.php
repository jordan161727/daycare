<?php

namespace App\Services;

use App\Http\Resources\AttendanceCommentResource;
use App\Models\AttendanceComment;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DoorAttendance;
use App\Models\SymptomCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A child's comments read back by day — Friday's note, on Monday.
 *
 * Only the days that have a comment are listed, newest first, and each day
 * carries the clock the door kept on it: first in, last out, the leaving
 * check. The screens show the day as a card; this builds the card.
 */
class CommentDays
{
    /** The ranges the "Show" box offers, keyed as the address names them. */
    public const RANGES = ['all', '30d', '7d', 'year', 'date'];

    /**
     * One page of a child's days with comments.
     *
     * `before` is the day the previous page ended on; the next page starts
     * under it. `date` lists that one day, whatever the range says.
     */
    public function forChild(Child $child, string $range = 'all', ?string $before = null, ?string $date = null, int $limit = 20): array
    {
        $query = AttendanceComment::query()
            ->where('child_id', $child->id)
            ->whereNotNull('date');

        if ($date) {
            $query->whereDate('date', $date);
        } elseif ($from = $this->rangeStart($range)) {
            $query->whereDate('date', '>=', $from);
        }

        if ($before) {
            $query->whereDate('date', '<', $before);
        }

        // One more than the page, to know whether there is another.
        $dates = (clone $query)
            ->select('date')
            ->distinct()
            ->orderByDesc('date')
            ->limit($limit + 1)
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString());

        $hasMore = $dates->count() > $limit;
        $dates = $dates->take($limit)->values();

        if ($dates->isEmpty()) {
            return ['days' => [], 'has_more' => false, 'next_before' => null];
        }

        $comments = AttendanceComment::with('author')
            ->where('child_id', $child->id)
            ->whereIn('date', $dates->all())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (AttendanceComment $c) => $c->date->toDateString());

        $clock = $this->clockOf($child->id, $dates);

        $days = $dates->map(fn (string $iso) => [
            'date' => $iso,
            'label' => Carbon::parse($iso)->format('D, M j'),
            ...$clock[$iso] ?? ['in' => null, 'out' => null, 'out_code' => null, 'out_code_label' => null, 'summary' => 'No arrival recorded'],
            'comments' => AttendanceCommentResource::collection($comments[$iso] ?? collect())->resolve(),
        ])->values()->all();

        return [
            'days' => $days,
            'has_more' => $hasMore,
            'next_before' => $hasMore ? $dates->last() : null,
        ];
    }

    /** The day the child's latest comment before `before` is on, if any. */
    public static function lastCommentDays(Collection $childIds, string $before): array
    {
        return AttendanceComment::query()
            ->whereIn('child_id', $childIds->all())
            ->whereNotNull('date')
            ->whereDate('date', '<', $before)
            ->selectRaw('child_id, MAX(date) as last_on')
            ->groupBy('child_id')
            ->pluck('last_on', 'child_id')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
    }

    /**
     * The door's clock on the given days: first in, last out, the leaving
     * check — one line a day, the way the dialog's card reads it.
     */
    public function clockOf(int $childId, Collection $dates): array
    {
        $timezone = config('app.timezone');
        $labels = SymptomCode::active()->pluck('label', 'code');

        return DoorAttendance::query()
            ->where('child_id', $childId)
            ->whereIn('attendance_date', $dates->all())
            ->get()
            ->groupBy(fn (DoorAttendance $row) => $row->attendance_date->toDateString())
            ->map(function (Collection $onDay) use ($timezone, $labels) {
                $first = $onDay->sortBy('signed_in_at')->first();
                $open = $onDay->contains(fn (DoorAttendance $row) => $row->signed_out_at === null);
                $last = $open ? null : $onDay->sortBy('signed_out_at')->last();

                $in = Child::timeShort($first?->signed_in_at?->timezone($timezone));
                $out = Child::timeShort($last?->signed_out_at?->timezone($timezone));
                $code = $last?->health_out_code;
                $codeLabel = $code === null ? null : ($labels[$code] ?? null);

                $parts = [];
                $parts[] = $in ? 'In '.$in.' → '.($out ? 'Out '.$out : 'no clock-out') : 'No arrival recorded';
                if ($code !== null) {
                    $parts[] = 'Leaving check '.$code.($codeLabel ? ' · '.$codeLabel : '');
                }

                return [
                    'in' => $in,
                    'out' => $out,
                    'out_code' => $code,
                    'out_code_label' => $codeLabel,
                    'summary' => implode(' · ', $parts),
                ];
            })
            ->all();
    }

    /** Where a range begins, or null for every day there is. */
    public function rangeStart(string $range): ?string
    {
        return match ($range) {
            '30d' => today()->subDays(30)->toDateString(),
            '7d' => today()->subDays(7)->toDateString(),
            'year' => self::schoolYearStart()->toDateString(),
            default => null,
        };
    }

    /** The first of September last passed: the school year the centre is in. */
    public static function schoolYearStart(?Carbon $asOf = null): Carbon
    {
        $asOf ??= today();
        $start = $asOf->copy()->month(9)->day(1)->startOfDay();

        return $asOf->lt($start) ? $start->subYear() : $start;
    }

    /**
     * The last day the centre was open before today: Friday on a Monday,
     * and the day before a holiday when the holiday is what was skipped.
     */
    public static function lastSchoolDay(?Carbon $asOf = null): Carbon
    {
        $day = ($asOf ?? today())->copy();
        $closed = ClosureDay::betweenDates($day->copy()->subDays(30), $day)
            ->pluck('closed_on')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        for ($i = 0; $i < 30; $i++) {
            $day->subDay();

            if (! $day->isWeekend() && ! in_array($day->toDateString(), $closed, true)) {
                return $day;
            }
        }

        return $day;
    }
}
