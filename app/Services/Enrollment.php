<?php

namespace App\Services;

use App\Models\Child;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A child's place on the roll, from agreed to gone — Phase 2.2 of docs/work.md.
 *
 *   Pending ──start──▶ Active ──withdraw──▶ Withdrawn ──archive──▶ Archived
 *      │                  ▲                     │                     │
 *      └──cancel──▶ Withdrawn           re-enroll ┘            restore ┘
 *
 * The column still says Inactive where the screen says Withdrawn (see
 * Child::STATUS_LABELS); nothing here renames a status the rest of the app
 * reads. Dates in the future are honoured by the nightly command
 * (children:sync-enrollments) rather than by the press: a child whose last
 * day is Friday stays on the check-in screen until Friday is over, and a
 * child whose first day is Monday is not counted for the ratio on Sunday.
 *
 * Every step writes a line to the enrollment history with the reason given.
 */
class Enrollment
{
    /**
     * Pending → Active, from a first day.
     *
     * Today or earlier starts them now; a later date is written down and the
     * nightly command starts them on it.
     */
    public function start(Child $child, Carbon $firstDay, ?User $by = null): Child
    {
        return DB::transaction(function () use ($child, $firstDay) {
            $child->enrolled_on = $firstDay->toDateString();
            $child->withdrawn_on = null;
            $child->withdrawal_reason = null;
            $child->withdrawal_note = null;
            $child->could_return = null;

            if ($firstDay->lte(Carbon::today())) {
                $child->status = 'Active';
                $child->save();
                $child->recordEvent('started', 'Enrollment started · '.$child->classroom, 'First day '.$firstDay->format('M j, Y'));
            } else {
                $child->save();
                $child->recordEvent('enrolled', 'First day agreed · '.$firstDay->format('M j, Y'), 'Starts on its own that morning');
            }

            return $child;
        });
    }

    /** Pending → Withdrawn: they never started. */
    public function cancel(Child $child, string $reason, ?string $note = null): Child
    {
        return DB::transaction(function () use ($child, $reason, $note) {
            $child->status = 'Inactive';
            $child->withdrawn_on = Carbon::today()->toDateString();
            $child->withdrawal_reason = $reason;
            $child->withdrawal_note = $note;
            $child->could_return = null;
            $child->save();

            $child->recordEvent('cancelled', 'Enrollment cancelled · never started', trim($reason.(filled($note) ? ' · '.$note : '')));

            return $child;
        });
    }

    /**
     * Active → Withdrawn, from a last day attended.
     *
     * A last day already past takes them off the roll now; one still to come
     * leaves them Active until the nightly command passes it, so attendance
     * up to the last day still counts for billing and the DSS export.
     */
    public function withdraw(Child $child, Carbon $lastDay, string $reason, ?string $couldReturn, ?string $note = null): Child
    {
        return DB::transaction(function () use ($child, $lastDay, $reason, $couldReturn, $note) {
            $child->withdrawn_on = $lastDay->toDateString();
            $child->withdrawal_reason = $reason;
            $child->withdrawal_note = $note;
            $child->could_return = $couldReturn;

            if ($lastDay->lt(Carbon::today())) {
                $child->status = 'Inactive';
            }

            $child->save();

            $child->recordEvent('withdrawn', 'Withdrawn · '.$reason, trim(
                'Last day '.$lastDay->format('M j, Y')
                .(filled($note) ? ' · '.$note : '')
                .(filled($couldReturn) ? ' · Could return: '.$couldReturn : '')
            ));

            return $child;
        });
    }

    /** Withdrawn → Active, with the same LAN and family. */
    public function reenroll(Child $child): Child
    {
        return DB::transaction(function () use ($child) {
            $child->status = 'Active';
            $child->withdrawn_on = null;
            $child->withdrawal_reason = null;
            $child->withdrawal_note = null;
            $child->could_return = null;
            $child->save();

            $child->recordEvent('reenrolled', 'Re-enrolled · '.$child->classroom, 'Same LAN and family');

            return $child;
        });
    }

    /**
     * The dates that have come round since yesterday: agreed first days that
     * are now today or past, and last days that are now behind us.
     *
     * @return array{started: int, withdrawn: int}
     */
    public function sync(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->toDateString();
        $counts = ['started' => 0, 'withdrawn' => 0];

        Child::where('status', 'Pending')->whereNotNull('enrolled_on')->whereDate('enrolled_on', '<=', $today)
            ->get()->each(function (Child $child) use (&$counts) {
                $child->status = 'Active';
                $child->save();
                $child->recordEvent('started', 'Enrollment started · '.$child->classroom, 'On the agreed first day');
                $counts['started']++;
            });

        Child::where('status', 'Active')->whereNotNull('withdrawn_on')->whereDate('withdrawn_on', '<', $today)
            ->get()->each(function (Child $child) use (&$counts) {
                $child->status = 'Inactive';
                $child->save();
                $counts['withdrawn']++;
            });

        return $counts;
    }
}
