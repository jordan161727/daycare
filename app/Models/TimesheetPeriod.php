<?php

namespace App\Models;

use App\Services\PayPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One pay period's hours, and whether they have been signed off.
 *
 * A period is draft until the director approves it. Approving is the moment the
 * hours stop being the centre's working notes and become the number payroll is
 * paying against, so from then on it is frozen — the same reasoning that locks
 * a children's week once its Friday has passed.
 */
class TimesheetPeriod extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'period_start', 'period_end', 'status',
        'seeded_at', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'seeded_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function range(): PayPeriod
    {
        return PayPeriod::containing($this->period_start);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Find or create the period holding a date. */
    public static function forDate(string $date): self
    {
        $range = PayPeriod::containing($date);

        return static::firstOrCreate(
            ['period_start' => $range->key()],
            ['period_end' => $range->end->toDateString()],
        );
    }
}
