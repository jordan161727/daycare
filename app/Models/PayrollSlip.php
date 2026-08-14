<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The pages of a combined payroll PDF belonging to one employee.
 */
class PayrollSlip extends Model
{
    protected $fillable = [
        'payroll_batch_id', 'user_id', 'matched_name', 'pages',
        'period_start', 'period_end', 'check_date',
        'status', 'sent_to', 'sent_at', 'error', 'position',
    ];

    protected $casts = [
        'pages' => 'array',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'check_date' => 'date:Y-m-d',
        'sent_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayrollBatch::class, 'payroll_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who this slip is for, falling back to the name printed on the page. */
    public function displayName(): string
    {
        return $this->user?->name ?? $this->matched_name ?? 'Unmatched';
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->displayName()))[0];
    }

    /**
     * Whether this slip can be emailed to the employee.
     *
     * Both halves matter: an unmatched page has nobody to send to, and a
     * matched one whose staff record has no email would fail at the mailer.
     * The screen greys the button on this rather than letting the send fail.
     */
    public function isSendable(): bool
    {
        return $this->user !== null && filled($this->user->email);
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    /** Status colour for the stepper: matched, sent, failed, or no address. */
    public function state(): string
    {
        return match (true) {
            $this->status === 'sent' => 'sent',
            $this->status === 'failed' => 'failed',
            $this->user === null => 'unmatched',
            blank($this->user->email) => 'no-email',
            default => 'pending',
        };
    }
}
