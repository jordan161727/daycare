<?php

namespace App\Console\Commands;

use App\Services\UpkEmail;
use Illuminate\Console\Command;

/**
 * The monthly UPK-4 sheet to the school district — see UpkEmail.
 *
 * Scheduled daily; it decides for itself whether today is the day.
 */
class SendUpkSheet extends Command
{
    protected $signature = 'reports:upk-email';

    protected $description = 'Email last month’s UPK-4 attendance sheet on the day the office chose';

    public function handle(UpkEmail $email): int
    {
        $result = $email->runScheduled(today());

        $this->info($result === null ? 'Not today.' : 'Done: '.$result.'.');

        return self::SUCCESS;
    }
}
