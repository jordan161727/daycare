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
    protected $fillable = ['week_start', 'generated_at', 'generated_by', 'warnings', 'published_at', 'published_by', 'edits'];

    protected $casts = [
        'week_start' => 'date:Y-m-d',
        'generated_at' => 'datetime',
        'published_at' => 'datetime',
        'warnings' => 'array',
        'edits' => 'integer',
    ];

    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Visible to staff. A week nobody has published is the director's draft:
     * generated, perhaps edited, and not yet a plan anyone else should act on.
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** One edit by hand, counted against the last publish. */
    public function noteEdit(): void
    {
        $this->forceFill(['edits' => $this->edits + 1])->save();
    }

    /**
     * The line under the title: what state the week is in, in plain words.
     *
     * @return array{state: string, tone: string, text: string}
     */
    public function statusLine(): array
    {
        $edits = $this->edits;
        $changes = $edits.' '.($edits === 1 ? 'change' : 'changes');

        if (! $this->isPublished()) {
            return [
                'state' => 'Draft',
                'tone' => 'warn',
                'text' => "staff can't see it until you publish · ".($edits > 0 ? $changes.' since generating' : 'generated '.($this->generated_at?->diffForHumans() ?? 'by hand')),
            ];
        }

        return [
            'state' => 'Published',
            'tone' => 'ok',
            'text' => $edits > 0
                ? $changes.' since publishing — publish again to share them'
                : 'published '.$this->published_at->diffForHumans().($this->publishedBy ? ' by '.$this->publishedBy->name : ''),
        ];
    }

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
