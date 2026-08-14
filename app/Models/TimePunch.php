<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One press of the clock.
 *
 * Rows here are written once and never edited. Putting a punch right means
 * voiding it and recording a replacement, because a corrected punch that had
 * simply been updated in place would be indistinguishable from one nobody ever
 * questioned — and the whole value of a time clock is that its record can be
 * shown to somebody who was not there.
 */
class TimePunch extends Model
{
    public const IN = 'IN';

    public const OUT = 'OUT';

    /** Unpaid: a meal break where the employee is relieved of duty. */
    public const LUNCH_START = 'LUNCH_START';

    public const LUNCH_END = 'LUNCH_END';

    /** Paid up to the cap in config, per the FLSA's short rest breaks. */
    public const BREAK_START = 'BREAK_START';

    public const BREAK_END = 'BREAK_END';

    /** The employee pressed it themselves. */
    public const SOURCE_CLOCK = 'clock';

    /** A supervisor put it right afterwards, and said why. */
    public const SOURCE_SUPERVISOR = 'supervisor';

    protected $fillable = [
        'user_id', 'work_date', 'punched_at', 'type',
        'source', 'reason', 'recorded_by', 'ip_address', 'corrects_id',
    ];

    protected $casts = [
        'work_date' => 'date:Y-m-d',
        'punched_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    /** What each punch is called on screen. */
    public const LABELS = [
        self::IN => 'Clocked in',
        self::OUT => 'Clocked out',
        self::LUNCH_START => 'Left for lunch',
        self::LUNCH_END => 'Back from lunch',
        self::BREAK_START => 'Started a break',
        self::BREAK_END => 'Back from break',
    ];

    /** The wording on the button, which is an instruction rather than a record. */
    public const ACTIONS = [
        self::IN => 'Clock in',
        self::OUT => 'Clock out',
        self::LUNCH_START => 'Start lunch',
        self::LUNCH_END => 'End lunch',
        self::BREAK_START => 'Start break',
        self::BREAK_END => 'End break',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** The punch this one was written to replace. */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_id');
    }

    /** Punches that still count. Voided ones stay visible but stop counting. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isCorrection(): bool
    {
        return $this->source === self::SOURCE_SUPERVISOR;
    }

    /** Minutes past midnight, the unit every other time in the app is kept in. */
    public function minutes(): int
    {
        return $this->punched_at->hour * 60 + $this->punched_at->minute;
    }

    public function time(): string
    {
        return $this->punched_at->format('g:i a');
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }

    public static function action(string $type): string
    {
        return self::ACTIONS[$type] ?? $type;
    }
}
