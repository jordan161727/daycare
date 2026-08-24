<?php

namespace App\Http\Controllers;

use App\Models\TimePunch;
use App\Models\TimesheetEntry;
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
            return back()->with('warning', self::STANDING[$state].', so '.TimePunch::action($data['type']).' was not recorded — the page had been sitting open. It is up to date now.');
        }

        $punch = $this->clock->punch(
            user: $user,
            type: $data['type'],
            at: now(),
            ip: $request->ip(),
        );

        return back()->with('success', $this->confirm($punch, $this->clock->day($user->id, $today)));
    }

    /** Where the refusal says they already stand, for the warning above. */
    private const STANDING = [
        TimeClock::OFF => 'You are not on the clock',
        TimeClock::WORKING => 'You are already on the clock',
        TimeClock::LUNCH => 'You are already at lunch',
        TimeClock::BREAK => 'You are already on a break',
    ];

    /**
     * What the punch that just landed says back.
     *
     * More than "recorded": each step names the time it went in at and what to
     * press next, because the punch a day dies on is the one nobody realised
     * was still owed — a lunch started and never ended is a broken day, and
     * the moment to say so is while they are still looking at the screen.
     */
    private function confirm(TimePunch $punch, array $day): string
    {
        $cap = (int) config('daycare.timesheet.clock.paid_break_cap');
        $said = $punch->label().' at '.$punch->time().'.';

        return match ($punch->type) {
            TimePunch::IN => $said.' Press '.TimePunch::action(TimePunch::LUNCH_START).' or '
                .TimePunch::action(TimePunch::BREAK_START).' when you take one, and '
                .TimePunch::action(TimePunch::OUT).' when you leave.',

            TimePunch::LUNCH_START => $said.' Lunch is unpaid — press '
                .TimePunch::action(TimePunch::LUNCH_END).' when you are back.',

            TimePunch::BREAK_START => $said.' The first '.$cap.' minutes are paid — press '
                .TimePunch::action(TimePunch::BREAK_END).' when you are back.',

            TimePunch::LUNCH_END => $said.' That was '.$this->sincePrevious($day, $punch).' minutes, unpaid. Back on the clock.',

            TimePunch::BREAK_END => $said.' '.$this->describeBreak($this->sincePrevious($day, $punch), $cap).' Back on the clock.',

            TimePunch::OUT => $said.' '.TimesheetEntry::formatHours($day['worked']).' h worked today'
                .($day['unpaid_break'] > 0 ? ', after '.$day['unpaid_break'].' minutes unpaid' : '').'.',

            default => $said,
        };
    }

    /** How long the stretch this punch closed ran for, in minutes. */
    private function sincePrevious(array $day, TimePunch $punch): int
    {
        $punches = $day['punches']->values();
        $index = $punches->search(fn (TimePunch $earlier) => $earlier->is($punch));

        if ($index === false || $index === 0) {
            return 0;
        }

        return max(0, $punch->minutes() - $punches[$index - 1]->minutes());
    }

    /** A rest break is paid to the cap and unpaid past it, so say which it was. */
    private function describeBreak(int $minutes, int $cap): string
    {
        if ($minutes <= $cap) {
            return $minutes.' minutes, paid.';
        }

        return $minutes.' minutes — '.$cap.' paid, '.($minutes - $cap).' unpaid.';
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
