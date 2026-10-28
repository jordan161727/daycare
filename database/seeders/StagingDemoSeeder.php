<?php

namespace Database\Seeders;

use App\Models\StaffScheduleWeek;
use App\Models\User;
use App\Services\StaffSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The staging server, filled as the mock-ups show it — one command.
 *
 * Every demo seeder this app has, in the order they depend on each other,
 * so a fresh staging database comes up with the mock-ups' invented people
 * (Ava Cruz, Ruby Kim, Mary Jo, Sultana, LaQuora …) and something on every
 * screen: the roll with every tab filled, guardians for the door, staff
 * with rules and time off, this week's schedule generated and published,
 * today's floor as the floor-plan mock-up draws it, two weeks of staff
 * punches, and two months of child sign-ins for the reports and the sheet.
 *
 * Re-runnable: every step tops up or rewrites its own rows rather than
 * doubling them. No real child's or staff member's details are in here;
 * run it on staging, never on live.
 *
 *     php artisan migrate --force
 *     php artisan db:seed --class=StagingDemoSeeder --force
 *
 * For a live-looking Staff attendance outside working hours, follow with
 *     DEMO_NOW=15:30 php artisan db:seed --class=StaffAttendanceDemoSeeder --force
 */
class StagingDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(StaffSchedule $scheduler): void
    {
        $step = fn (string $what) => $this->command?->info($what);

        $step('1/10 Accounts, rooms and room hours (admin@daycare.test / password)…');
        $this->call(DatabaseSeeder::class);
        // Pay is the Director's alone, so staging needs one to show it; the
        // seeded admin stays an office admin, who sees who can instead.
        User::updateOrCreate(['email' => 'director@daycare.test'], [
            'name' => 'Ray Kader',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'job_role' => 'Director',
        ]);

        $step('2/10 The roll: the mock-ups\' children, this week ticked…');
        $this->call(DemoScenarioSeeder::class);

        $step('3/10 Child records: every card filled, every tab with someone in it…');
        $this->call(ChildRecordDemoSeeder::class);
        // Enough staff for this roll (step 1 ran this before any child was in).
        $this->call(RoomCoverSeeder::class);

        $step('4/10 Guardians for the door kiosk…');
        $this->call(KioskDemoSeeder::class);

        $step('5/10 Today\'s floor and the mock-ups\' staff (first pass, so they are on the books)…');
        $this->call(FloorPlanDemoSeeder::class);

        $step('6/10 Staff details, rules, time off, and a face for each…');
        $this->call(StaffDemoSeeder::class);
        $this->call(StaffAvatarSeeder::class);

        $weekStart = StaffScheduleWeek::startOf(today()->toDateString());
        $admin = User::where('role', 'admin')->orderBy('id')->first();
        $step("7/10 Generating and publishing the schedule for the week of {$weekStart}…");
        $week = $scheduler->generate($weekStart, $admin);
        $week->forceFill(['published_at' => now(), 'published_by' => $admin?->id, 'edits' => 0])->save();
        $warnings = count($week->warnings ?? []);
        $this->command?->line('    '.($warnings === 0 ? 'No conflicts.' : "{$warnings} thing".($warnings === 1 ? '' : 's').' to look at on the Schedule page.'));

        $step('8/10 Today\'s floor again, over the published week (Sultana late, LaQuora free to cover)…');
        $this->call(FloorPlanDemoSeeder::class);

        $step('9/10 Staff attendance: last week and this week\'s days gone…');
        $this->call(StaffAttendanceDemoSeeder::class);

        $step('10/10 Child attendance: last month to yesterday, register and door…');
        $this->call(ChildAttendanceDemoSeeder::class);

        $this->command?->info('Staging demo ready. Log in as admin@daycare.test, or director@daycare.test to see pay (password "password"); the room teachers are <room>.teacher@daycare.test.');
    }
}
