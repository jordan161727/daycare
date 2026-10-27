<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the kiosks' story: a punch, a room move, a flag, a tablet
 * being set up. The flags are the lines the office has to answer.
 */
class KioskEvent extends Model
{
    public const PUNCH = 'punch';

    public const ROOM = 'room';

    public const FLAG = 'flag';

    public const TABLET = 'tablet';

    protected $fillable = [
        'kind', 'user_id', 'classroom', 'staff_device_id', 'text',
        'occurred_at', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(StaffDevice::class, 'staff_device_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeFlags(Builder $query): Builder
    {
        return $query->where('kind', self::FLAG);
    }

    /** Flags nobody in the office has seen to. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->flags()->whereNull('resolved_at');
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function isFlag(): bool
    {
        return $this->kind === self::FLAG;
    }

    public function resolve(User $by): self
    {
        $this->forceFill(['resolved_at' => now(), 'resolved_by' => $by->id])->save();

        return $this;
    }
}
