<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\HealthAudit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Demo attendance and health checks, for looking at the grids.
 *
 * Not for production. It invents arrivals, departures and symptom codes so the
 * month grid and the sheet can be judged with something in them — a screen
 * built against three rows of data is one whose column widths, chip sizes and
 * shading have never actually been tested.
 *
 * A command rather than a seeder because it has to be undoable, and a seeder
 * has nowhere to put a flag: `db:seed --class=X -- --undo` reads "--undo" as a
 * second class name and fails looking for it.
 *
 * Everything it writes is a plausible day: arrivals in the morning peak,
 * departures in the afternoon, and mostly code 0 with the occasional symptom,
 * because a sheet where every child is unwell tells you nothing about how the
 * amber reads against the green.
 *
 * It skips days that already hold a record, so it cannot overwrite anything
 * real, and it only ever touches the current month up to today — a future
 * arrival is not a thing the app should ever hold.
 */
class HealthDemoData extends Command
{
    protected $signature = 'demo:health {--undo : Remove the demo days this wrote, and nothing else}';

    protected $description = 'Write (or remove) demo attendance and health checks for the current month';

    /**
     * How a demo row is told apart from a real one.
     *
     * Stamped into the seconds of the arrival, which nothing else in the app
     * sets or reads — the clock records to the minute. It means the rows can
     * be removed later without guessing, and without a column on the table
     * that production would carry for ever to serve a demo.
     */
    private const SIGNATURE = 7;

    /** Mostly well, occasionally not. The mix a real month has. */
    private const CODES = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 3, 4, 6, 8, 10];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('undo')) {
            // Said plainly rather than refused: a staging box often runs with
            // APP_ENV=production, and the person typing this knows which one
            // they are on better than the environment name does.
            if (! $this->confirm('This app is running as production. Write demo attendance anyway?', false)) {
                $this->warn('Nothing written.');

                return self::SUCCESS;
            }
        }

        return $this->option('undo') ? $this->undo() : $this->write();
    }

    private function write(): int
    {
        $by = User::where('role', 'admin')->first();
        $children = Child::where('status', 'Active')->orderBy('last_name')->get();

        $days = collect(range(0, today()->day - 1))
            ->map(fn (int $offset) => today()->startOfMonth()->addDays($offset))
            // The centre is shut at weekends, and a grid showing arrivals in
            // the shaded columns would be testing the wrong thing.
            ->reject(fn (Carbon $day) => $day->isWeekend());

        $made = 0;

        foreach ($children as $child) {
            foreach ($days as $day) {
                $iso = $day->toDateString();

                // Not every child every day: a month with no gaps in it does
                // not show whether an empty cell reads as empty.
                if (random_int(1, 10) > 8) {
                    continue;
                }

                if (Attendance::where('child_id', $child->id)->whereDate('attendance_date', $iso)->exists()) {
                    continue;
                }

                $in = $day->copy()->setTime(random_int(7, 9), random_int(0, 59), self::SIGNATURE);
                $out = $day->copy()->setTime(random_int(15, 18), random_int(0, 59), self::SIGNATURE);

                $inCode = self::CODES[array_rand(self::CODES)];
                $outCode = self::CODES[array_rand(self::CODES)];

                $attendance = Attendance::create([
                    'child_id' => $child->id,
                    'attendance_date' => $iso,
                    'session' => $child->sessions()[0] ?? 'FULL',
                    'signed_in_at' => $in,
                    // Some of today's children are still here, so the grid has
                    // half-finished days in it as a real one would.
                    'signed_out_at' => $day->isToday() && random_int(1, 3) === 1 ? null : $out,
                    'health_in_code' => $inCode,
                ]);

                if ($attendance->signed_out_at !== null) {
                    $attendance->forceFill(['health_out_code' => $outCode])->save();
                }

                // The audit trail too, so the record reads the way a real one
                // would rather than as codes that appeared from nowhere.
                HealthAudit::note($attendance, HealthAudit::IN, null, $inCode, null, $by);

                if ($attendance->signed_out_at !== null) {
                    HealthAudit::note($attendance, HealthAudit::OUT, null, $outCode, null, $by);
                }

                $made++;
            }
        }

        $this->info("{$made} demo days written across {$children->count()} children.");
        $this->line('Remove them again with: php artisan demo:health --undo');

        return self::SUCCESS;
    }

    /** Take back exactly what this wrote, and nothing else. */
    private function undo(): int
    {
        $rows = Attendance::whereBetween('attendance_date', [
            today()->startOfMonth()->toDateString(),
            today()->endOfMonth()->toDateString(),
        ])->get()->filter(fn (Attendance $row) => (int) $row->signed_in_at?->second === self::SIGNATURE);

        HealthAudit::whereIn('attendance_id', $rows->pluck('id'))->delete();
        Attendance::whereIn('id', $rows->pluck('id'))->delete();

        $this->info($rows->count().' demo days removed. Anything else on those days is untouched.');

        return self::SUCCESS;
    }
}
