<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A day the centre is shut. Nobody is scheduled on a closed day, but a sign-in
 * is still accepted — if a child turns up, DSS bills the attendance that
 * happened, not the attendance we planned for.
 */
class ClosureDay extends Model
{
    protected $fillable = ['closed_on', 'reason', 'created_by'];

    protected $casts = [
        'closed_on' => 'date:Y-m-d',
    ];

    /** The closed dates inside one Mon–Fri week, as Y-m-d strings. */
    public static function inWeek(string $weekStart): array
    {
        $dates = ScheduleWeek::datesOf($weekStart);

        return static::whereBetween('closed_on', [$dates[0], $dates[4]])
            ->pluck('closed_on')
            ->map(fn ($date) => $date->toDateString())
            ->all();
    }
}
