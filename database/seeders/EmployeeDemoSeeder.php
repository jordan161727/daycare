<?php

namespace Database\Seeders;

use App\Models\StaffScheduleWeek;
use App\Models\User;
use App\Services\StaffSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Everything the Employee section needs, in one run and in the right order.
 *
 * Four steps that each depend on the one before: staff assigned to rooms
 * (enough openers and closers for the children on the roll), this week's
 * schedule generated from them, a face for each of them, and this week's
 * punches made against their shifts. Run on its own after the children are
 * in, or again whenever the roll changes — every step tops up rather than
 * doubling, and the week is regenerated from the current rules.
 *
 *     php artisan db:seed --class=EmployeeDemoSeeder
 */
class EmployeeDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(StaffSchedule $scheduler): void
    {
        $this->command?->info('1/4 Assigning staff to rooms…');
        $this->call(RoomCoverSeeder::class);

        $weekStart = StaffScheduleWeek::startOf(today()->toDateString());
        $this->command?->info("2/4 Generating the schedule for the week of {$weekStart}…");
        $week = $scheduler->generate($weekStart, User::where('role', 'admin')->first());
        $warnings = count($week->warnings ?? []);
        $this->command?->line('    '.($warnings === 0 ? 'No conflicts.' : "{$warnings} thing".($warnings === 1 ? '' : 's').' to look at on the Week Schedule page.'));

        $this->command?->info('3/4 Drawing avatars…');
        $this->call(StaffAvatarSeeder::class);

        $this->command?->info('4/4 Punching this week…');
        $this->call(TimesheetWeekDemoSeeder::class);

        $this->command?->info('Employee data ready: '.User::teachers()->count().' staff on the roster.');
    }
}
