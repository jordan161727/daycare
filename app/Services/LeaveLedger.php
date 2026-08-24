<?php

namespace App\Services;

use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leave balances: what has been earned, what has been spent, and what is left.
 *
 * Everything here works on the ledger rather than on a stored total, so a
 * balance is always the sum of things that happened rather than a number
 * somebody hopes is still right. That costs a query and buys the ability to
 * answer "where did my other four hours go", which is the only question anybody
 * ever asks about leave.
 *
 * Posting is idempotent by (person, type, source, reference). Accrual for a pay
 * period can therefore be run by the approve button, by the artisan command and
 * by an impatient director in the same afternoon and still be paid once.
 */
class LeaveLedger
{
    public function __construct(private Timesheet $timesheets) {}

    /** Every type's balance for one person. */
    public function balances(int $userId): array
    {
        $sums = LeaveLedgerEntry::where('user_id', $userId)
            ->groupBy('leave_type')
            ->selectRaw('leave_type, SUM(hours) as total')
            ->pluck('total', 'leave_type');

        return collect(config('daycare.leave.types'))
            ->mapWithKeys(fn ($label, $type) => [$type => round((float) ($sums[$type] ?? 0), 2)])
            ->all();
    }

    public function balance(int $userId, string $type): float
    {
        return $this->balances($userId)[$type] ?? 0.0;
    }

    /**
     * Everybody's balances in one query, for the director's table.
     *
     * @return array<int, array<string, float>>
     */
    public function balancesFor(Collection $users): array
    {
        $types = array_keys(config('daycare.leave.types'));

        $sums = LeaveLedgerEntry::whereIn('user_id', $users->pluck('id'))
            ->groupBy('user_id', 'leave_type')
            ->selectRaw('user_id, leave_type, SUM(hours) as total')
            ->get();

        $balances = [];

        foreach ($users as $user) {
            foreach ($types as $type) {
                $balances[$user->id][$type] = 0.0;
            }
        }

        foreach ($sums as $row) {
            $balances[$row->user_id][$row->leave_type] = round((float) $row->total, 2);
        }

        return $balances;
    }

    /**
     * Hours already asked for and not yet decided, by type.
     *
     * Shown next to the balance because the two together are what somebody
     * needs before asking for a third week: a balance of forty with thirty-two
     * already pending is eight hours, whatever the first number says.
     */
    public function committed(int $userId): array
    {
        $hours = collect(config('daycare.leave.types'))->map(fn () => 0.0)->all();

        foreach (LeaveRequest::where('user_id', $userId)->where('status', LeaveRequest::STATUS_PENDING)->get() as $request) {
            $hours[$request->leave_type] = round(($hours[$request->leave_type] ?? 0) + $request->hours(), 2);
        }

        return $hours;
    }

    /** The statement behind a balance, newest first. */
    public function statement(int $userId, int $limit = 40): Collection
    {
        return LeaveLedgerEntry::with('author')
            ->where('user_id', $userId)
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Write one movement, unless that exact movement is already there.
     *
     * Returns null when it was already posted — the caller can say "nothing to
     * do" rather than pretending it did something, which is what makes running
     * accrual twice safe rather than merely harmless.
     */
    public function post(
        int $userId,
        string $type,
        float $hours,
        string $source,
        ?string $reference = null,
        ?string $effectiveOn = null,
        ?string $note = null,
        ?int $by = null,
    ): ?LeaveLedgerEntry {
        if (round($hours, 2) === 0.0) {
            return null;
        }

        // An adjustment has no reference and so is never deduplicated: a
        // director granting four hours twice meant it both times.
        if ($reference !== null && LeaveLedgerEntry::where([
            'user_id' => $userId,
            'leave_type' => $type,
            'source' => $source,
            'reference' => $reference,
        ])->exists()) {
            return null;
        }

        return LeaveLedgerEntry::create([
            'user_id' => $userId,
            'leave_type' => $type,
            'hours' => round($hours, 2),
            'effective_on' => $effectiveOn ?? today()->toDateString(),
            'source' => $source,
            'reference' => $reference,
            'note' => $note,
            'created_by' => $by,
        ]);
    }

    /** Take the approved hours off the balance. */
    public function spend(LeaveRequest $request, float $hours, ?User $actor = null): ?LeaveLedgerEntry
    {
        return $this->post(
            $request->user_id,
            $request->leave_type,
            -abs($hours),
            LeaveLedgerEntry::SOURCE_TAKEN,
            (string) $request->id,
            $request->starts_on->toDateString(),
            $request->rangeLabel(),
            $actor?->id,
        );
    }

    /** Give them back when an approved request is revoked. */
    public function restore(LeaveRequest $request, ?User $actor = null): ?LeaveLedgerEntry
    {
        return $this->post(
            $request->user_id,
            $request->leave_type,
            abs($request->paid_hours),
            LeaveLedgerEntry::SOURCE_RESTORED,
            (string) $request->id,
            today()->toDateString(),
            'Revoked: '.$request->rangeLabel(),
            $actor?->id,
        );
    }

    /**
     * Earn a pay period's leave for everybody in it.
     *
     * Only an approved period accrues. Until it is approved its hours are still
     * the centre's working notes — accruing off a draft would hand somebody
     * sick time for a shift that a correction later removed, and taking it back
     * afterwards is a conversation no director wants to have.
     *
     * Hourly staff earn against the hours they actually worked, so leave and
     * holidays earn nothing: an hour off does not earn a further hour off.
     * Salaried staff earn a flat rate instead, because their worked hours are
     * a formality and would make their balance jump about for no reason they
     * could explain.
     *
     * @return list<string> What happened, in the order it happened.
     */
    public function accrue(TimesheetPeriod $period, ?User $actor = null): array
    {
        if (! $period->isApproved()) {
            return ['Nothing accrued: '.$period->range()->label().' has not been approved yet, so its hours can still change.'];
        }

        $reference = $period->period_start->toDateString();
        $effective = $period->period_end->toDateString();
        $caps = config('daycare.leave.cap');
        $lines = [];

        $summary = $this->timesheets->summary($period)->keyBy('user.id');
        $staff = User::teachers()->get();
        $balances = $this->balancesFor($staff);

        DB::transaction(function () use ($staff, $summary, $balances, $caps, $reference, $effective, $actor, &$lines) {
            foreach ($staff as $person) {
                $worked = (float) ($summary[$person->id]['worked_hours'] ?? 0);

                foreach ($this->earned($person, $worked) as $type => $hours) {
                    if ($hours <= 0) {
                        continue;
                    }

                    $held = $balances[$person->id][$type] ?? 0.0;
                    $cap = $caps[$type] ?? null;
                    $room = $cap === null ? $hours : max(0, round($cap - $held, 2));
                    $posted = min($hours, $room);

                    if ($posted <= 0) {
                        $lines[] = "{$person->name}: already at the {$cap}h {$type} cap — nothing earned this period.";

                        continue;
                    }

                    $entry = $this->post(
                        $person->id,
                        $type,
                        $posted,
                        LeaveLedgerEntry::SOURCE_ACCRUAL,
                        $reference,
                        $effective,
                        $worked > 0 ? 'Earned on '.round($worked, 2).'h worked' : 'Earned for the period',
                        $actor?->id,
                    );

                    if (! $entry) {
                        continue;
                    }

                    $lines[] = $posted < $hours
                        ? "{$person->name}: {$posted}h {$type} earned — capped at {$cap}h, so ".round($hours - $posted, 2).'h was not.'
                        : "{$person->name}: {$posted}h {$type} earned.";
                }
            }
        });

        return $lines === []
            ? ['Nothing to accrue for '.$period->range()->label().' — either it has already been run, or nobody worked hours that earn leave.']
            : $lines;
    }

    /**
     * What one person earns for one period, by type.
     *
     * @return array<string, float>
     */
    private function earned(User $person, float $workedHours): array
    {
        $flat = config('daycare.leave.accrual.per_period')[$person->employment] ?? null;

        if ($flat !== null) {
            return collect($flat)->map(fn ($hours) => round((float) $hours, 2))->all();
        }

        return collect(config('daycare.leave.accrual.per_hours_worked'))
            ->map(fn ($per) => $per > 0 ? round($workedHours / $per, 2) : 0.0)
            ->all();
    }
}
