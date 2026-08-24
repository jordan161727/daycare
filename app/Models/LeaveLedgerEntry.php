<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement on somebody's leave balance.
 *
 * The balance itself is nowhere: it is the sum of these rows, worked out when
 * asked for. A stored total would be a second copy of the truth, and the first
 * time an accrual run and a taken day disagreed with it there would be no way
 * to tell which of the three was right.
 *
 * Hours are signed. Accrual and restoration add, leave taken subtracts, and an
 * adjustment does whichever the director typed.
 */
class LeaveLedgerEntry extends Model
{
    /** Earned by working a pay period, or by being salaried through one. */
    public const SOURCE_ACCRUAL = 'ACCRUAL';

    /** Spent on an approved request. */
    public const SOURCE_TAKEN = 'TAKEN';

    /** Given back when an approved request was revoked. */
    public const SOURCE_RESTORED = 'RESTORED';

    /** A director's own correction — a starting balance, a goodwill grant. */
    public const SOURCE_ADJUSTMENT = 'ADJUSTMENT';

    protected $fillable = [
        'user_id', 'leave_type', 'hours', 'effective_on',
        'source', 'reference', 'note', 'created_by',
    ];

    protected $casts = [
        'hours' => 'float',
        'effective_on' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whoever caused the entry. Null for an accrual run by the scheduler. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'reference');
    }

    /** The entry in a sentence, for the statement on somebody's leave page. */
    public function describe(): string
    {
        return match ($this->source) {
            self::SOURCE_ACCRUAL => 'Earned over the period beginning '.$this->reference,
            self::SOURCE_TAKEN => 'Taken as approved leave',
            self::SOURCE_RESTORED => 'Returned — the approved leave was revoked',
            self::SOURCE_ADJUSTMENT => 'Adjusted by the director',
            default => $this->source,
        };
    }

    /** "+4.00" / "−8.00". Signed, because which way it went is the point. */
    public function signedHours(): string
    {
        return ($this->hours >= 0 ? '+' : '−').number_format(abs($this->hours), 2);
    }
}
