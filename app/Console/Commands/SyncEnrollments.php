<?php

namespace App\Console\Commands;

use App\Services\Enrollment;
use Illuminate\Console\Command;

/**
 * Start the children whose agreed first day has come, and take off the roll
 * the ones whose last day has passed. Runs once a night (routes/console.php)
 * so neither waits on somebody remembering.
 */
class SyncEnrollments extends Command
{
    protected $signature = 'children:sync-enrollments';

    protected $description = 'Start enrollments whose first day has come and close ones whose last day has passed';

    public function handle(Enrollment $enrollment): int
    {
        $counts = $enrollment->sync();

        $this->info($counts['started'].' started, '.$counts['withdrawn'].' withdrawn.');

        return self::SUCCESS;
    }
}
