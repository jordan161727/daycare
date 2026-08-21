<?php

namespace App\Console\Commands;

use App\Models\TimesheetPeriod;
use App\Services\LeaveLedger;
use App\Services\PayPeriod;
use Illuminate\Console\Command;

/**
 * Earn a pay period's sick and vacation time for everybody who worked it.
 *
 * The approve button on the timesheet already does this, so the command is for
 * the periods approved before leave tracking existed and for a centre that
 * would rather run it from cron the morning after payroll closes. Posting is
 * idempotent either way — running it on a period twice earns nobody a second
 * hour.
 */
class AccrueLeave extends Command
{
    protected $signature = 'leave:accrue
                            {date? : Any date inside the pay period. Defaults to the one that has just ended.}';

    protected $description = 'Post leave accrual for an approved pay period';

    public function handle(LeaveLedger $ledger): int
    {
        // No date means the period just gone, which is the one that has both
        // ended and, with luck, been approved. Accruing the period we are
        // standing in would pay people for days they have not worked yet.
        $range = $this->argument('date')
            ? PayPeriod::containing($this->argument('date'))
            : PayPeriod::containing(today())->previous();

        $period = TimesheetPeriod::firstWhere('period_start', $range->key());

        if (! $period) {
            $this->warn('No timesheet exists for '.$range->label().'. Nothing has been worked or approved in it.');

            return self::SUCCESS;
        }

        $this->line('  period: '.$range->label().'  ('.$period->status.')');
        $this->newLine();

        foreach ($ledger->accrue($period) as $line) {
            $this->line('  '.$line);
        }

        return self::SUCCESS;
    }
}
