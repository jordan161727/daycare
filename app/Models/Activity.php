<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One line of the activity log: who did what, when, in a sentence.
 *
 * Written by App\Services\ActivityLog; read by the Activity page under
 * Staff. See the migration for why it exists.
 */
class Activity extends Model
{
    protected $table = 'activity_log';

    protected $fillable = [
        'actor_id', 'actor_name', 'subject_type', 'subject_id', 'subject_name',
        'event', 'text', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** The lines of one day, in the centre's own time zone. */
    public function scopeOnDay(Builder $query, Carbon|string $day): Builder
    {
        $day = $day instanceof Carbon ? $day : Carbon::parse($day, config('app.timezone'));
        $start = $day->copy()->startOfDay();

        return $query->whereBetween('occurred_at', [$start->copy()->utc(), $start->copy()->addDay()->utc()]);
    }

    public function scopeBy(Builder $query, int|string $actor): Builder
    {
        return is_numeric($actor)
            ? $query->where('actor_id', (int) $actor)
            : $query->where('actor_name', $actor);
    }

    public function scopeAbout(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey());
    }

    public function scopeSearch(Builder $query, ?string $q): Builder
    {
        $q = trim((string) $q);

        return $q === '' ? $query : $query->where('text', 'like', '%'.$q.'%');
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /** "11:05 AM", in the centre's time. */
    public function clock(): string
    {
        return $this->occurred_at->timezone(config('app.timezone'))->format('g:i A');
    }
}
