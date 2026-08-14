<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A generated staff roster for one Monday-to-Friday week.
 *
 * Unlike the children's ScheduleWeek, this one does not copy forward. A staff
 * week is a solution to the rules as they stood when it was generated, so
 * carrying it into next week would hide a rule change rather than apply it.
 */
class StaffScheduleWeek extends Model
{
    protected $fillable = ['week_start', 'generated_at', 'generated_by', 'warnings'];

    protected $casts = [
        'week_start' => 'date:Y-m-d',
        'generated_at' => 'datetime',
        'warnings' => 'array',
    ];

    public function shifts(): HasMany
    {
        return $this->hasMany(StaffShift::class, 'week_start', 'week_start');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** The Monday of whatever week a date falls in. */
    public static function startOf(string|Carbon $date): string
    {
        return Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    /** Monday to Friday of a week, keyed by the day code rules use. */
    public static function datesOf(string $weekStart): array
    {
        $monday = Carbon::parse($weekStart);

        return collect(config('daycare.days'))
            ->mapWithKeys(fn ($day, $offset) => [$day => $monday->copy()->addDays($offset)])
            ->all();
    }
}
