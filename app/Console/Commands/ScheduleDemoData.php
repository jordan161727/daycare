<?php

namespace App\Console\Commands;

use App\Models\StaffRule;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\User;
use App\Services\StaffSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * A demo staff roster, for looking at the Week Schedule.
 *
 * Not for production. The generator writes nothing for a teacher with no
 * employment type and starts nobody before opening time unless somebody may
 * open the building — so on a fresh box the Week Schedule is an empty chart
 * with a warning under it. This gives it something to show: every teacher
 * without an employment type is made full time, the first two are made
 * keyholders, and the week is generated for this Monday and the ones after.
 *
 * Generated through StaffSchedule::generate() rather than written straight
 * into the table, so the chart is a real answer to the rules as they stand,
 * warnings included — the same thing pressing Generate does.
 *
 * A command rather than a seeder for the reason demo:health and demo:punches
 * are: it has to be undoable, and `db:seed --class=X -- --undo` reads
 * "--undo" as a second class name.
 */
class ScheduleDemoData extends Command
{
    protected $signature = 'demo:schedule
        {--weeks=2 : How many weeks to generate, starting with this one}
        {--undo : Remove the weeks and keyholder rules this wrote, and nothing else}
        {--force : Skip the "running as production" question — for a script, or a local box whose .env says production}';

    protected $description = 'Generate (or remove) a demo staff roster for this week and the next, rostering every teacher';

    /** How a rule this wrote is told apart from one a director set. */
    private const NOTE = 'demo:schedule';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('undo') && ! $this->option('force')) {
            if (! $this->confirm('This app is running as production. Generate a demo roster anyway?', false)) {
                $this->warn('Nothing written.');

                return self::SUCCESS;
            }
        }

        return $this->option('undo') ? $this->undo() : $this->write();
    }

    private function write(): int
    {
        $teachers = User::teachers()->get();

        if ($teachers->isEmpty()) {
            $this->warn('No teacher records to roster. Add staff first.');

            return self::SUCCESS;
        }

        // Nobody is left off the chart for want of an employment type. FT is
        // the ordinary case; a director corrects the rest on the profile.
        $rostered = $teachers->filter(fn (User $person) => $person->weeklyHours() <= 0)
            ->each(fn (User $person) => $person->forceFill(['employment' => 'FT'])->save());

        // Somebody has to open the building, or every shift starts at the
        // default opening time and the early room is short.
        $keyholders = 0;

        foreach ($teachers->take(2) as $person) {
            if (! $person->staffRules()->where('rule_type', 'CAN_OPEN')->exists()) {
                $person->staffRules()->create([
                    'rule_type' => 'CAN_OPEN', 'priority' => 'HARD', 'source_note' => self::NOTE,
                ]);
                $keyholders++;
            }
        }

        $weeks = max(1, (int) $this->option('weeks'));
        $monday = Carbon::parse(StaffScheduleWeek::startOf(today()));
        $generator = app(StaffSchedule::class);
        $shifts = 0;

        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $monday->copy()->addWeeks($i)->toDateString();
            $week = $generator->generate($weekStart);
            $count = StaffShift::where('week_start', $weekStart)->count();
            $shifts += $count;

            $this->line("Week of {$weekStart}: {$count} shifts".(count($week->warnings ?? []) ? ', '.count($week->warnings).' warning(s)' : ''));

            foreach ($week->warnings ?? [] as $warning) {
                $this->line('  - '.$warning);
            }
        }

        $this->info("{$shifts} shifts across {$teachers->count()} teachers, over {$weeks} week(s).");

        if ($rostered->isNotEmpty()) {
            $this->line($rostered->count().' teacher(s) had no employment type and are now FT: '.$rostered->pluck('name')->join(', ', ' and ').'.');
        }

        if ($keyholders) {
            $this->line("{$keyholders} keyholder rule(s) added so the building can open.");
        }

        $this->line('Remove the roster again with: php artisan demo:schedule --undo');

        return self::SUCCESS;
    }

    /**
     * Take back what this wrote: the weeks it generated and the keyholder
     * rules it added. An employment type it set stays — it is a fact on the
     * profile now, and the profile is where a wrong one is corrected.
     */
    private function undo(): int
    {
        // A week a director generated carries who pressed the button; the
        // ones this wrote carry nobody.
        $weeks = StaffScheduleWeek::whereNull('generated_by')->get();
        $shifts = 0;

        foreach ($weeks as $week) {
            $shifts += StaffShift::where('week_start', $week->week_start->toDateString())->delete();
            $week->delete();
        }

        $rules = StaffRule::where('source_note', self::NOTE)->delete();

        $this->info("Removed {$weeks->count()} week(s), {$shifts} shifts and {$rules} keyholder rule(s).");

        return self::SUCCESS;
    }
}
