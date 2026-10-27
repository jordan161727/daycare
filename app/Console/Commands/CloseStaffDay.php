<?php

namespace App\Console\Commands;

use App\Services\StaffPresence;
use Illuminate\Console\Command;

/**
 * The closing-time safety net (HANDOFF §5.3.2).
 *
 * Runs at the hour Settings says the centre closes. Anyone still on the
 * clock is flagged for the office and taken out of their room, so the
 * morning's ratios do not open with last night's people still counted in
 * them. It does not clock anybody out: a punch the person never made is not
 * evidence, and the director puts the day right in Staff attendance.
 */
class CloseStaffDay extends Command
{
    protected $signature = 'staff:close-day';

    protected $description = 'Flag anyone still on the clock at closing time and close their room check-ins';

    public function handle(StaffPresence $presence): int
    {
        $flagged = $presence->closeDay();

        $this->info($flagged === 0 ? 'Nobody left on the clock.' : $flagged.' still on the clock at closing — flagged for the office.');

        return self::SUCCESS;
    }
}
