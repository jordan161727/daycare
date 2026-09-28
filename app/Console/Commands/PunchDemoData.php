<?php

namespace App\Console\Commands;

use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Demo punches, for looking at the Timesheets grid and its edit panel.
 *
 * Not for production. It invents a fortnight of clock punches for every
 * teacher so the grid has something in it — and, deliberately, so that every
 * colour a dot can be has a day behind it: green days with a lunch and a short
 * break, one day somebody went home without clocking out (amber), one day
 * somebody arrived after their rostered start (red), and one day a supervisor
 * has already put right (the hollow ring). A grid judged against a week where
 * everything went fine is one whose amber and red have never been looked at.
 *
 * Written through TimeClock::punch() rather than straight into the table, so
 * the timesheet entries rebuild from the punches exactly as they do for a real
 * day, and the correction is a real void-and-replace with a reason on it.
 *
 * A command rather than a seeder for the same reason demo:health is: it has
 * to be undoable, and `db:seed --class=X -- --undo` reads "--undo" as a second
 * class name.
 */
class PunchDemoData extends Command
{
    protected $signature = 'demo:punches
        {--undo : Remove the demo punches this wrote, and nothing else}
        {--force : Skip the "running as production" question — for a script, or a local box whose .env says production}';

    protected $description = 'Write (or remove) demo clock punches for this week and last, with one of each kind of problem';

    /**
     * How a demo punch is told apart from a real one.
     *
     * Stamped into the seconds of the punch, which the clock never sets — it
     * records to the minute and every reading of a punch drops the seconds.
     */
    private const SIGNATURE = 7;

    /**
     * The roster written for the "late" day, so that late means something:
     * without a shift saying when somebody was due there is no such thing.
     * Distinctive on purpose, because it is how --undo finds the shift again.
     */
    private const DEMO_SHIFT = ['starts_at' => 8 * 60, 'ends_at' => 16 * 60 + 30];

    public function handle(): int
    {
        // The question is for a person at a keyboard. A non-interactive shell
        // answers it "no" by default, which is the safe answer — and --force is
        // how a person who has already decided says yes from a script.
        if (app()->isProduction() && ! $this->option('undo') && ! $this->option('force')) {
            if (! $this->confirm('This app is running as production. Write demo punches anyway?', false)) {
                $this->warn('Nothing written.');

                return self::SUCCESS;
            }
        }

        return $this->option('undo') ? $this->undo() : $this->write();
    }

    private function write(): int
    {
        $clock = app(TimeClock::class);
        $supervisor = User::where('role', 'admin')->first();
        $staff = User::whereIn('role', ['teacher', 'staff'])->orderBy('id')->get();

        if ($staff->isEmpty()) {
            $this->warn('No teachers or staff to write punches for.');

            return self::SUCCESS;
        }

        // Weekdays of last week and this week, up to and including today.
        $days = collect(range(0, 13))
            ->map(fn (int $offset) => today()->subWeek()->startOfWeek()->addDays($offset))
            ->filter(fn (Carbon $day) => ! $day->isWeekend() && $day->lte(today()))
            ->values();

        // The three problem days, given to different people on different
        // days already gone, so they do not pile up in one cell.
        $gone = $days->filter(fn (Carbon $day) => $day->lt(today()))->values();
        $faults = [
            'missing' => [$staff[0]->id, $gone->get(1)?->toDateString()],
            'late' => [$staff[1 % $staff->count()]->id, $gone->get(2)?->toDateString()],
            'corrected' => [$staff[2 % $staff->count()]->id, $gone->get(3)?->toDateString()],
            // Went on a break, never came back off it, and clocked out anyway.
            // The clock refuses to pay that day; the panel draws the missing
            // end in and the admin types it.
            'break' => [$staff[3 % $staff->count()]->id, $gone->get(4)?->toDateString()],
        ];

        $made = 0;

        foreach ($staff as $person) {
            foreach ($days as $day) {
                $iso = $day->toDateString();

                if (TimePunch::where('user_id', $person->id)->whereDate('work_date', $iso)->exists()) {
                    continue;
                }

                // Not everybody every day: a fortnight with no gaps does not
                // show whether an empty cell reads as empty.
                $fault = collect($faults)->search(fn ($f) => $f[0] === $person->id && $f[1] === $iso);

                if ($fault === false && random_int(1, 10) > 8) {
                    continue;
                }

                $at = fn (int $h, int $m) => $day->copy()->setTime($h, $m, self::SIGNATURE);
                $in = $fault === 'late' ? $at(8, 40) : $at(7, random_int(45, 59));

                $clock->punch(user: $person, type: TimePunch::IN, at: $in);

                // Today's people are mostly still here; the grid should show
                // half-finished days the way a real morning does.
                if ($day->isToday() && random_int(1, 3) !== 1) {
                    $made++;

                    continue;
                }

                $clock->punch(user: $person, type: TimePunch::LUNCH_START, at: $at(12, random_int(0, 20)));
                $clock->punch(user: $person, type: TimePunch::LUNCH_END, at: $at(12, random_int(35, 55)));
                $clock->punch(user: $person, type: TimePunch::BREAK_START, at: $at(15, 0));

                // The forgotten end of a break: everything else on the day is
                // there, so the fix is one punch typed into one gap.
                if ($fault !== 'break') {
                    $clock->punch(user: $person, type: TimePunch::BREAK_END, at: $at(15, 12));
                }

                if ($fault === 'missing') {
                    // Went home and never pressed the button: the amber dot.
                    $made++;

                    continue;
                }

                if ($fault === 'late') {
                    // A roster that says eight, so arriving at 8:40 is late
                    // rather than just a morning.
                    StaffShift::firstOrCreate(
                        ['user_id' => $person->id, 'shift_date' => $iso],
                        self::DEMO_SHIFT + [
                            'week_start' => $day->copy()->startOfWeek()->toDateString(),
                            'day' => strtoupper($day->format('D')),
                            'classroom' => $person->classroom,
                            'role' => StaffShift::ROLE_STAFF,
                        ],
                    );
                }

                $out = $clock->punch(user: $person, type: TimePunch::OUT, at: $at(16, random_int(28, 40)));

                if ($fault === 'corrected' && $supervisor) {
                    // Clocked out at the wrong time and put right afterwards:
                    // a real void and a real replacement, with a reason, so
                    // the ring on the grid and the history in the panel are
                    // both telling the truth.
                    $clock->void($out, $supervisor, TimePunch::reasonText('wrong_time', 'Pressed it for the closing teacher'));
                    $clock->punch(
                        user: $person,
                        type: TimePunch::OUT,
                        at: $at(16, 30),
                        source: TimePunch::SOURCE_SUPERVISOR,
                        by: $supervisor,
                        reason: TimePunch::reasonText('wrong_time', 'Pressed it for the closing teacher'),
                        corrects: $out,
                    );
                }

                $made++;
            }
        }

        $this->info("{$made} demo days punched across {$staff->count()} people, over {$days->count()} weekdays.");
        $this->line('One day is missing its clock-out (amber), one never ended its break (amber), one is late against a roster (red), one was corrected (ring).');
        $this->line('Remove them again with: php artisan demo:punches --undo');

        return self::SUCCESS;
    }

    /** Take back exactly what this wrote, and nothing else. */
    private function undo(): int
    {
        $from = today()->subWeek()->startOfWeek()->toDateString();
        $to = today()->toDateString();

        $punches = TimePunch::whereBetween('work_date', [$from, $to])
            ->get()
            ->filter(fn (TimePunch $punch) => (int) $punch->punched_at->second === self::SIGNATURE);

        // The rosters written for the late day, found by the distinctive
        // shift they were given — and only on days that held demo punches.
        $shifts = StaffShift::whereIn('shift_date', $punches->pluck('work_date')->map->toDateString()->unique())
            ->whereIn('user_id', $punches->pluck('user_id')->unique())
            ->where(self::DEMO_SHIFT)
            ->delete();

        $clock = app(TimeClock::class);
        $days = $punches->map(fn (TimePunch $punch) => [$punch->user_id, $punch->work_date->toDateString()])->unique();

        TimePunch::whereIn('id', $punches->pluck('id'))->delete();

        // The timesheet entries those punches built are rebuilt from what is
        // left — which is nothing — so the fortnight reads as it did before.
        foreach ($days as [$userId, $date]) {
            if ($user = User::find($userId)) {
                $clock->rebuild($user, $date, force: true);
            }
        }

        $this->info($punches->count().' demo punches removed across '.$days->count().' person-days, with '.$shifts.' demo roster row(s). Anything else on those days is untouched.');

        return self::SUCCESS;
    }
}
