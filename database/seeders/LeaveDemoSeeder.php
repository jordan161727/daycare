<?php

namespace Database\Seeders;

use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\LeaveLedger;
use App\Services\StaffSchedule;
use App\Services\Timesheet;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sick and vacation time you can walk through by hand.
 *
 * Every state the feature can be in is staged at once, because most of them
 * only make sense next to each other: a request the balance covers reads as
 * ordinary until you see the one beside it that does not, and an "at the cap"
 * balance means nothing without a nearly-empty one two rows down.
 *
 * The approved absence deliberately lands in a week that is already published,
 * so the roster shows the harder half of this feature — shifts pulled out of a
 * printed week, and the week itself asking to be regenerated. That is the state
 * worth looking at, and the one nobody stages by accident.
 *
 * Everything is anchored to today, so the future leave is genuinely in the
 * future whenever this is run and the backdated sick day is genuinely behind.
 *
 * Run StaffSeeder first: without staff there is nobody to hold a balance.
 * DemoScenarioSeeder before that, so the roster the approval reaches into is
 * sized against the children actually booked in. TimesheetDemoSeeder as well,
 * if you want the balances to include hours genuinely earned rather than only
 * hours typed in — this seeder accrues from whatever period it finds approved.
 */
class LeaveDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * @var list<string> Days an approval could not reach the timesheet, and why.
     *
     * Collected rather than discarded: the app refuses to write leave over a
     * day somebody has already spoken for or into a pay period that has been
     * approved, and a seeder that stayed quiet about it would leave a demo
     * where leave was granted and payroll never heard.
     */
    private array $unreached = [];

    public function run(StaffSchedule $scheduler, Timesheet $timesheets, LeaveLedger $ledger): void
    {
        $staff = User::teachers()->whereNotNull('employment')->orderBy('name')->get();

        if ($staff->count() < 5) {
            $this->command?->error('Not enough staff to stage the leave screens.');
            $this->command?->line('  Run: php artisan db:seed --class=DemoScenarioSeeder');
            $this->command?->line('       php artisan db:seed --class=StaffSeeder');

            return;
        }

        $director = User::where('role', 'admin')->orderBy('id')->first();

        if (! $director) {
            $this->command?->error('No director on file to decide anything. Run the DatabaseSeeder first.');

            return;
        }

        $cleared = $this->reset($staff);

        $this->openingBalances($ledger, $staff, $director);
        $earned = $this->accrue($ledger, $director);

        // The roster has to exist before anything is approved, or the approval
        // reaches into an empty week and the most interesting state on the
        // whole feature — a published week losing a shift — is never staged.
        $weekStart = $this->publishNextWeek($scheduler, $director);

        $staged = $this->stageRequests($scheduler, $timesheets, $ledger, $staff, $director, $weekStart);

        $this->report($ledger, $staff, $staged, $earned, $cleared, $weekStart);
    }

    /**
     * Clear the leave this seeder owns, so a second run does not stack.
     *
     * Scoped to the staff cohort rather than truncating the tables: anything
     * somebody entered by hand for a person outside it survives, and whatever
     * is removed is counted and reported rather than disappearing quietly.
     */
    private function reset(Collection $staff): int
    {
        $ids = $staff->pluck('id');

        $requests = LeaveRequest::whereIn('user_id', $ids)->count();

        LeaveRequest::whereIn('user_id', $ids)->delete();
        LeaveLedgerEntry::whereIn('user_id', $ids)->delete();

        return $requests;
    }

    /**
     * The balances a centre adopting this app would type in on day one.
     *
     * Chosen to put the three states that behave differently next to each
     * other: comfortable, nearly empty, and hard against the cap.
     */
    private function openingBalances(LeaveLedger $ledger, Collection $staff, User $director): void
    {
        $cap = config('daycare.leave.cap');

        // By position rather than by name, so the seeder still stages every
        // state if the staff room is edited.
        $opening = [
            0 => ['VACATION' => 62, 'SICK' => 12],                    // comfortable
            1 => ['VACATION' => 6, 'SICK' => 4],                      // nearly empty
            2 => ['VACATION' => $cap['VACATION'], 'SICK' => 16],      // at the cap
            3 => ['VACATION' => 18, 'SICK' => 9],
            4 => ['VACATION' => 30, 'SICK' => 24],
        ];

        foreach ($staff->values() as $index => $person) {
            $hours = $opening[$index] ?? ['VACATION' => 24, 'SICK' => 8];

            foreach ($hours as $type => $amount) {
                $ledger->post(
                    $person->id,
                    $type,
                    (float) $amount,
                    LeaveLedgerEntry::SOURCE_ADJUSTMENT,
                    null,
                    today()->startOfYear()->toDateString(),
                    'Carried over from the paper card',
                    $director->id,
                );
            }
        }
    }

    /**
     * Earn whatever an approved pay period is owed, for real.
     *
     * Opening balances are somebody's word; these rows are the ones the app
     * worked out, and having both on the statement is the point — a teacher
     * reading it should be able to tell what was given from what was earned.
     *
     * @return list<string>
     */
    private function accrue(LeaveLedger $ledger, User $director): array
    {
        $approved = TimesheetPeriod::where('status', TimesheetPeriod::STATUS_APPROVED)
            ->orderByDesc('period_start')
            ->first();

        if (! $approved) {
            return [];
        }

        return $ledger->accrue($approved, $director);
    }

    /**
     * Make sure next week is on the wall before anybody is granted time off it.
     *
     * Only generates when the week is empty. Rebuilding a roster somebody has
     * already read is exactly the thing the rest of the app refuses to do
     * behind a director's back, and a seeder has no business doing it either.
     */
    private function publishNextWeek(StaffSchedule $scheduler, User $director): string
    {
        $weekStart = StaffScheduleWeek::startOf(today()->addWeek()->toDateString());

        if (! StaffShift::where('week_start', $weekStart)->exists()) {
            $scheduler->generate($weekStart, $director);
        }

        return $weekStart;
    }

    /**
     * One request in each state the queue and the teacher's page can show.
     *
     * @return array<string, LeaveRequest|null>
     */
    private function stageRequests(
        StaffSchedule $scheduler,
        Timesheet $timesheets,
        LeaveLedger $ledger,
        Collection $staff,
        User $director,
        string $weekStart,
    ): array {
        $people = $staff->values();
        $monday = Carbon::parse($weekStart);
        $fortnight = $monday->copy()->addWeek();

        // Approved, and inside the week already published: this is the one that
        // pulls shifts off the roster and leaves the week asking to be rebuilt.
        $booked = $this->request($people[0], 'VACATION', $monday->copy()->addDay(), $monday->copy()->addDays(2), 8, 'Family visiting');
        $this->decide($booked, $director, $ledger, $scheduler, $timesheets, 'Enjoy it — Emily will cover Infant.');

        // Pending, and worth more than the balance behind it. The queue shows
        // the shortfall and the approve form grows its unpaid tickbox.
        $short = $this->request($people[1], 'VACATION', $fortnight, $fortnight->copy()->addDays(2), 8, 'Wedding in Cebu');

        // Pending, backdated: the sick day filed the morning after, which is
        // how sick leave actually arrives.
        $sick = $this->request($people[3], 'SICK', today()->subDay(), today()->subDay(), 8, 'Woke up with a fever');

        // Pending, and a half day — a request for four hours over one date
        // rather than a second kind of request.
        $half = $this->request($people[4], 'VACATION', $fortnight->copy()->addDays(4), $fortnight->copy()->addDays(4), 4, 'Dentist, back after lunch');

        // Decided against, and kept. The teacher reads the reason on their own
        // page months later, which is why nothing is ever deleted.
        $denied = $this->request($people[2], 'VACATION', $monday->copy()->addDays(4), $monday->copy()->addDays(4), 8, 'Long weekend');
        $denied->forceFill([
            'status' => LeaveRequest::STATUS_DENIED,
            'reviewed_by' => $director->id,
            'reviewed_at' => now()->subDays(2),
            'decision_note' => 'Two others are already off that Friday — ask again for the week after.',
        ])->save();

        // Withdrawn by the person who asked, before anybody looked at it.
        $withdrawn = $this->request($people[3], 'VACATION', $fortnight->copy()->addDays(7), $fortnight->copy()->addDays(8), 8, 'Changed my mind');
        $withdrawn->forceFill(['status' => LeaveRequest::STATUS_CANCELLED])->save();

        // Already taken, and already paid: an approved sick day behind us, so
        // the statement has a spent row and the timesheet has the day on it.
        // The date is chosen rather than fixed — a day in a frozen period, or
        // one somebody has already confirmed, is a day the timesheet is right
        // to refuse, and the demo would be a paid absence payroll never sees.
        $when = $this->openPastDate($people[3]);

        $taken = $when
            ? $this->request($people[3], 'SICK', $when, $when, 8, 'Chest infection')
            : null;

        if ($taken) {
            $this->decide($taken, $director, $ledger, $scheduler, $timesheets, 'Get well.');
        }

        return [
            'approved next week' => $booked,
            'pending, over balance' => $short,
            'pending, backdated sick' => $sick,
            'pending, half day' => $half,
            'denied' => $denied,
            'withdrawn' => $withdrawn,
            'taken and paid' => $taken,
        ];
    }

    private function request(User $person, string $type, Carbon $from, Carbon $to, float $hoursPerDay, string $reason): LeaveRequest
    {
        return LeaveRequest::create([
            'user_id' => $person->id,
            'leave_type' => $type,
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'hours_per_day' => $hoursPerDay,
            'status' => LeaveRequest::STATUS_PENDING,
            'reason' => $reason,
        ]);
    }

    /**
     * Grant a request the way the director's button does.
     *
     * Mirrors LeaveRequestController::approve deliberately — the same
     * allocation, the same ledger entry, the same reach into the roster and the
     * timesheet, in the same order. It stages a state the app can actually
     * produce rather than one only a seeder knows how to make; if that
     * controller ever grows a step, this needs it too.
     */
    private function decide(
        LeaveRequest $request,
        User $director,
        LeaveLedger $ledger,
        StaffSchedule $scheduler,
        Timesheet $timesheets,
        string $note,
    ): void {
        $split = $request->allocate($ledger->balance($request->user_id, $request->leave_type));

        $request->forceFill([
            'status' => LeaveRequest::STATUS_APPROVED,
            'paid_hours' => $split['paid_hours'],
            'unpaid_hours' => $split['unpaid_hours'],
            'reviewed_by' => $director->id,
            'reviewed_at' => now()->subDay(),
            'decision_note' => $note,
        ])->save();

        $ledger->spend($request, $split['paid_hours'], $director);

        $scheduler->applyLeave($request->fresh('user'));

        foreach ($timesheets->recordLeave($request->fresh())['skipped'] as $problem) {
            $this->unreached[] = $request->user->firstName().': '.$problem;
        }
    }

    /**
     * The most recent past weekday leave could actually be recorded on.
     *
     * Walks back from yesterday looking for a day in a pay period still open,
     * on which nobody has already said what happened. Anything else would stage
     * an absence the timesheet is right to refuse, and the demo would show a
     * paid sick day that payroll never sees.
     */
    private function openPastDate(User $person): ?Carbon
    {
        $days = config('daycare.days');

        for ($date = today()->subDay(); $date->gte(today()->subDays(28)); $date->subDay()) {
            if (! in_array(strtoupper($date->format('D')), $days, true)) {
                continue;
            }

            if (TimesheetPeriod::forDate($date->toDateString())->isApproved()) {
                continue;
            }

            $entry = TimesheetEntry::where('user_id', $person->id)
                ->where('work_date', $date->toDateString())
                ->first();

            if ($entry && $entry->source !== TimesheetEntry::SOURCE_SCHEDULE) {
                continue;
            }

            return $date;
        }

        return null;
    }

    private function report(LeaveLedger $ledger, Collection $staff, array $staged, array $earned, int $cleared, string $weekStart): void
    {
        $this->command?->info('Leave ready.');

        if ($cleared > 0) {
            $this->command?->line('  cleared      : '.$cleared.' leave request(s) already on file for these staff');
        }

        foreach ($staged as $label => $request) {
            if (! $request) {
                continue;
            }

            $this->command?->line('  '.str_pad($label, 24).' : '
                .str_pad($request->user->firstName(), 8).' '
                .strtolower($request->label()).', '.$request->rangeLabel()
                .' ('.$request->hours().'h'
                .($request->unpaid_hours > 0 ? ', '.$request->unpaid_hours.'h unpaid' : '').')');
        }

        $this->command?->newLine();
        $this->command?->line('  balances:');

        $cap = config('daycare.leave.cap');

        foreach ($staff as $person) {
            $this->command?->line('    '.str_pad($person->name, 16)
                .collect($ledger->balances($person->id))
                    ->map(fn ($hours, $type) => str_pad(
                        $type.' '.number_format($hours, 2).'h'.($hours >= ($cap[$type] ?? INF) ? ' at the cap' : ''),
                        28,
                    ))
                    ->join(''));
        }

        if ($earned !== []) {
            $this->command?->newLine();
            $this->command?->line('  accrued from the approved pay period:');

            foreach (array_slice($earned, 0, 6) as $line) {
                $this->command?->line('    '.$line);
            }

            if (count($earned) > 6) {
                $this->command?->line('    …and '.(count($earned) - 6).' more.');
            }
        }

        if ($this->unreached !== []) {
            $this->command?->newLine();
            $this->command?->line('  approved, but not written to the timesheet:');

            foreach ($this->unreached as $line) {
                $this->command?->line('    '.$line);
            }

            $this->command?->line('    (both refusals are the app working — a punched day and a frozen');
            $this->command?->line('     period both outrank a decision made about them afterwards.)');
        }

        $waiting = LeaveRequest::where('status', LeaveRequest::STATUS_PENDING)->count();

        $this->command?->newLine();
        $this->command?->line('  Open  /leave/requests  as the director: '.$waiting.' are waiting, and one of them');
        $this->command?->line('  is worth more than the balance behind it — approving it is refused until');
        $this->command?->line('  the shortfall is accepted as unpaid. Then  /staff-schedule?week='.$weekStart);
        $this->command?->line('  where the approved week already lost its shifts and says so.');
        $this->command?->line('  Sign in as a teacher for  /leave  — the balance, the statement, the answers.');
    }
}
