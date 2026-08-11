<?php

namespace App\Console\Commands;

use App\Services\ClassroomAssignment;
use Illuminate\Console\Command;

class SyncClassrooms extends Command
{
    protected $signature = 'classrooms:sync';

    protected $description = 'Move children into the room their age puts them in, leaving overrides alone';

    public function handle(): int
    {
        $moved = ClassroomAssignment::syncAll();

        $this->info($moved === 0
            ? 'Every child is already in the right room.'
            : $moved.' child(ren) moved room.');

        return self::SUCCESS;
    }
}
