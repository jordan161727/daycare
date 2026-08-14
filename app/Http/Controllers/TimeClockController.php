<?php

namespace App\Http\Controllers;

use App\Models\TimePunch;
use App\Models\TimesheetPeriod;
use App\Services\PayPeriod;
use App\Services\TimeClock;
use App\Services\Timesheet;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The clock as the person punching it sees it.
 *
 * Deliberately small: four or five buttons, today's punches underneath, and
 * the running total. Everything that could go wrong is kept off this screen —
 * the only punches offered are the ones legal from where the employee stands,
 * so the ordinary day cannot be mis-punched and the exception queue stays as
 * short as the mistakes people actually make.
 *
 * Nobody corrects anything here, including their own. A clock somebody can
 * quietly amend is not a record of anything, so a wrong punch goes to a
 * supervisor with a reason attached — see TimePunchController.
 */
class TimeClockController extends Controller
{
    public function __construct(
        private TimeClock $clock,
        private Timesheet $timesheets,
    ) {}

    public function index(Request $request)
    {
        abort_unless(config('daycare.timesheet.clock.enabled'), 404);

        $user = $request->user();
        $today = today()->toDateString();

        $day = $this->clock->day($user->id, $today, $this->clock->punches($user->id, $today, withVoided: true));
        $range = PayPeriod::containing($today);

        return view('clock.index', [
            'staff' => $user,
            'today' => today(),
            'day' => $day,
            'next' => TimeClock::NEXT[$day['state']],
            'range' => $range,
            // Their own hours so far this period. Hours only — what the centre
            // pays for them is not on a screen a colleague can lean over.
            'summary' => $this->timesheets->summary(TimesheetPeriod::forDate($range->key()))
                ->firstWhere('user.id', $user->id),
            'week' => $this->recentDays($user->id),
        ]);
    }

    /**
     * Press the clock.
     *
     * The punch is refused unless it is legal from where they stand, which is
     * belt and braces against a stale page rather than a real path — a screen
     * left open since lunch would otherwise post a second "start lunch".
     */
    public function punch(Request $request)
    {
        abort_unless(config('daycare.timesheet.clock.enabled'), 404);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(TimePunch::LABELS))],
        ]);

        $user = $request->user();
        $today = today()->toDateString();
        $state = $this->clock->state($user, $today);

        if (! in_array($data['type'], TimeClock::NEXT[$state], true)) {
            return back()->with('warning', 'That is not where you left off — the page was out of date, so nothing was recorded. It is up to date now.');
        }

        $punch = $this->clock->punch(
            user: $user,
            type: $data['type'],
            at: now(),
            ip: $request->ip(),
        );

        return back()->with('success', $punch->label().' at '.$punch->time().'.');
    }

    /**
     * The last fortnight of their own days, newest first.
     *
     * Somebody who missed a punch on Tuesday needs to be able to see that
     * before payday rather than after it, so the days they cannot fix
     * themselves are at least the days they can see.
     */
    private function recentDays(int $userId): array
    {
        $punches = TimePunch::live()
            ->where('user_id', $userId)
            ->whereBetween('work_date', [today()->subDays(13)->toDateString(), today()->toDateString()])
            ->orderBy('punched_at')
            ->get()
            ->groupBy(fn (TimePunch $punch) => $punch->work_date->toDateString());

        $days = [];

        foreach ($punches->sortKeysDesc() as $date => $onDay) {
            $days[$date] = $this->clock->day($userId, $date, $onDay);
        }

        return $days;
    }
}
