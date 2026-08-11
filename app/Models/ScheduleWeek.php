<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ScheduleWeek extends Model
{
    protected $fillable = ['week_start', 'copied_from_week_start', 'created_by'];

    protected $casts = [
        'week_start' => 'date:Y-m-d',
        'copied_from_week_start' => 'date:Y-m-d',
    ];

    public function slots()
    {
        return $this->hasMany(ScheduleSlot::class, 'week_start', 'week_start');
    }

    /** The Monday of whatever week a date falls in. */
    public static function startOf(string|Carbon $date): string
    {
        return Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    /** Monday to Friday of a week. */
    public static function datesOf(string $weekStart): array
    {
        $monday = Carbon::parse($weekStart);

        return collect(range(0, 4))->map(fn ($offset) => $monday->copy()->addDays($offset))->all();
    }
}
