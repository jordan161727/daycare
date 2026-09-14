<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A hand-made change to a day already gone.
 *
 * Append-only, and it outlives the attendance row it describes — which is the
 * point, because a removal leaves nothing in `attendances` to ask about. See
 * the migration for why this exists at all.
 */
class AttendanceAmendment extends Model
{
    public const ADDED = 'added';

    public const REMOVED = 'removed';

    /** The arrival stands; the hour on it was corrected. */
    public const RETIMED = 'retimed';

    protected $fillable = [
        'child_id',
        'attendance_date',
        'session',
        'action',
        'signed_in_at',
        'performed_by',
    ];

    protected $casts = [
        'attendance_date' => 'date:Y-m-d',
        'signed_in_at' => 'datetime',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Write one down.
     *
     * Only for days already gone: today's arrivals are ordinary sign-ins, not
     * amendments, and a log that fills with three hundred routine arrivals a
     * week is one nobody reads.
     */
    public static function record(string $action, Attendance|array $cell, ?User $user, ?Carbon $signedInAt = null): ?self
    {
        [$childId, $date, $session] = $cell instanceof Attendance
            ? [$cell->child_id, $cell->attendance_date->toDateString(), $cell->session ?? 'FULL']
            : [$cell['child_id'], $cell['attendance_date'], $cell['session'] ?? 'FULL'];

        if ($date >= now()->toDateString()) {
            return null;
        }

        return static::create([
            'child_id' => $childId,
            'attendance_date' => $date,
            'session' => $session,
            'action' => $action,
            'signed_in_at' => $signedInAt ?? ($cell instanceof Attendance ? $cell->signed_in_at : null),
            'performed_by' => $user?->id,
        ]);
    }

    /**
     * What was changed in a range of days, newest first, keyed by cell.
     *
     * The sheet marks a corrected cell and names who corrected it, so a row
     * that was not a live sign-in is visibly not one.
     *
     * @return array<string, array{action: string, by: string, on: string}>
     */
    public static function mapForRange(string $from, string $to): array
    {
        $map = [];

        foreach (static::with('performer')
            ->whereBetween('attendance_date', [$from, $to])
            ->orderBy('created_at')
            ->get() as $amendment) {
            // Last write wins: a cell added, removed and added again reads as
            // added, which is what it now is.
            $map[$amendment->child_id.'|'.$amendment->attendance_date->toDateString().'|'.$amendment->session] = [
                'action' => $amendment->action,
                'by' => $amendment->performer?->name ?? 'a former staff member',
                'on' => $amendment->created_at->format('M j'),
            ];
        }

        return $map;
    }
}
