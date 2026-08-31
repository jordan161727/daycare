<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\HolidayRule;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Services\HolidayCalendar;
use App\Services\WeekSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The days the centre is shut, set once and ahead of time.
 *
 * A closure is a fact about the whole centre, so it is set here and every room
 * follows: the day greys out on the attendance board, no child can be ticked
 * onto it, no staff shift is generated for it, and leave is not charged against
 * it. Nothing has to be repeated room by room or week by week.
 *
 * Two ways in, because they are two different acts. A one-off date is entered
 * as itself. A holiday that returns every year is entered as a rule, which
 * writes itself out five years ahead — and reaches the rest of the app as
 * ordinary closures, so nothing else has to know annual holidays exist.
 *
 * The attendance board keeps its own one-day toggle for the snow day nobody saw
 * coming. This page is the other half of the same table: a holiday entered in
 * March closes a September day whose week will not be built for months, because
 * a week reads the closures before it copies a pattern forward.
 */
class HolidayController extends Controller
{
    /** A range is a school break, not a data-entry accident. */
    private const MAX_RANGE_DAYS = 90;

    public function __construct(
        private WeekSchedule $weeks,
        private HolidayCalendar $calendar,
    ) {}

    public function index()
    {
        // Rolls the horizon forward on its own. Idempotent and free once the
        // years are written, which is why it can live on a page visit instead
        // of in a scheduled job nobody would notice had stopped.
        $this->calendar->materialise();

        $today = today()->toDateString();
        $days = ClosureDay::with(['creator', 'rule'])->orderBy('closed_on')->get();

        [$upcoming, $past] = $days->partition(fn ($day) => $day->closed_on->toDateString() >= $today);

        return view('holidays.index', [
            'upcoming' => $upcoming->values(),
            // Newest first going backwards: last month's closure is the one
            // somebody is checking, not one from two years ago.
            'past' => $past->sortByDesc('closed_on')->values(),
            // Sorted by where each one actually lands next, so a moving holiday sits
            // among the fixed dates rather than at the top with a null month.
            'rules' => HolidayRule::with('creator')->get()
                ->sortBy(fn ($rule) => $rule->dateIn((int) now()->year) ?? $rule->dateIn((int) now()->year + 1) ?? '9999')
                ->values(),
            // Who comes back if each upcoming day is reopened. The count alone
            // does not answer the question actually being asked at that moment
            // — which children am I putting back on the roster?
            'restores' => $this->restorePreview($upcoming),
        ]);
    }

    /**
     * The ticks each closure would put back, resolved to names.
     *
     * A box is only restorable if it is still there: a child who has left, or
     * whose room now splits the day into AM and PM, has nothing to tick. Those
     * are listed too and marked, because "3 of 5 come back" is the honest
     * answer and the dialog should say it before the click, not after.
     */
    private function restorePreview($upcoming): array
    {
        $dates = $upcoming->map(fn ($day) => $day->closed_on->toDateString());
        $wanted = $upcoming->flatMap(fn ($day) => collect($day->cleared_slots ?? [])->pluck('child_id'))->unique();

        if ($wanted->isEmpty()) {
            return [];
        }

        $children = Child::whereIn('id', $wanted)->get()->keyBy('id');

        // One query for every day on the page rather than one per day.
        $live = ScheduleSlot::whereIn('slot_date', $dates)
            ->get()
            ->map(fn ($slot) => $slot->slot_date->toDateString().'|'.$slot->child_id.'|'.$slot->session)
            ->flip();

        return $upcoming->mapWithKeys(fn ($day) => [$day->id => collect($day->cleared_slots ?? [])
            ->map(function ($slot) use ($children, $day, $live) {
                $child = $children[$slot['child_id']] ?? null;

                return [
                    // "First Last", the way the attendance board writes it —
                    // this dialog is about who goes back onto that board.
                    'name' => $child ? $child->first_name.' '.$child->last_name : 'A child who has left',
                    'sort' => $child ? $child->last_name.' '.$child->first_name : 'zzz',
                    'session' => $slot['session'],
                    'restorable' => $live->has($day->closed_on->toDateString().'|'.$slot['child_id'].'|'.$slot['session']),
                ];
            })
            // Ordered by surname, the way every other roster on the site is.
            ->sortBy('sort')
            ->map(fn ($row) => collect($row)->except('sort')->all())
            ->values()
            ->all()])->all();
    }

    /**
     * Close a day, or a run of days for a break that spans a week or more.
     *
     * Weekends are skipped rather than rejected — a break is entered by its real
     * first and last day, and the centre is not open at the weekend anyway, so
     * closing Saturday would be a row that says nothing.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'closed_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:closed_on'],
            'reason' => ['nullable', 'string', 'max:120'],
        ], [], [
            'closed_on' => 'date',
            'ends_on' => 'last day',
        ]);

        $from = Carbon::parse($data['closed_on']);
        $to = Carbon::parse($data['ends_on'] ?? $data['closed_on']);
        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;

        if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            return back()
                ->withInput()
                ->withErrors(['ends_on' => 'That range covers more than '.self::MAX_RANGE_DAYS.' days. Enter the break as its own dates.']);
        }

        $closed = [];
        $frozen = [];
        $cleared = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if ($date->isWeekend()) {
                continue;
            }

            $day = $date->toDateString();

            // A week whose Friday has passed is a record of what happened, and
            // DSS bills against it. Closing a day inside it now would rewrite
            // that, so those days are reported back rather than written.
            if ($this->weeks->isFrozen(ScheduleWeek::startOf($day))) {
                $frozen[] = $day;

                continue;
            }

            $cleared += $this->weeks->setClosure($day, true, $reason, $request->user());
            $closed[] = $day;
        }

        if ($closed === []) {
            return back()->withInput()->with('warning', $frozen === []
                ? 'Nothing to close — that range is all weekend.'
                : 'Nothing was closed. Those days are in weeks that have already ended and can no longer be edited.');
        }

        $message = count($closed) === 1
            ? Carbon::parse($closed[0])->format('l, j F Y').' is now closed.'
            : count($closed).' days closed, '.Carbon::parse($closed[0])->format('j M').' – '.Carbon::parse(end($closed))->format('j M Y').'.';

        // The number that answers "did that do what I meant?" — closing a day
        // is only visible on the board as the ticks it took off.
        $message .= $cleared > 0
            ? ' '.$cleared.' scheduled day(s) were cleared from the attendance board.'
            : ' No scheduled days needed clearing.';

        if ($frozen !== []) {
            $message .= ' '.count($frozen).' day(s) were skipped — their week has already ended.';
        }

        return redirect()->route('holidays.index')->with($frozen === [] ? 'success' : 'warning', $message);
    }

    /**
     * Open a day back up, putting back the ticks the closure took off.
     *
     * A child who has since left, or whose room now splits the day into AM and
     * PM, has no box left to tick — so what comes back is reported rather than
     * assumed, and a day that restores nothing says so.
     */
    public function destroy(Request $request, ClosureDay $holiday)
    {
        $date = $holiday->closed_on->toDateString();
        $expected = $holiday->clearedCount();

        if ($this->weeks->isFrozen(ScheduleWeek::startOf($date))) {
            return redirect()->route('holidays.index')
                ->with('warning', 'That week has ended — '.$holiday->closed_on->format('j F Y').' stays as it was recorded.');
        }

        $restored = $this->weeks->setClosure($date, false);

        $message = $holiday->closed_on->format('l, j F Y').' is open again.';

        if ($restored > 0) {
            $message .= ' '.$restored.' scheduled day(s) were put back on the attendance board.';
        }

        // Silence here would read as "restored, nothing to say". These are two
        // different days — one had nothing planned, the other lost its children
        // to a room change — and neither should look like a success that quietly
        // did less than it appears.
        if ($restored < $expected) {
            $message .= ' '.($expected - $restored).' could not be put back — those children have left or changed room since.';
        } elseif ($restored === 0) {
            $message .= ' Nothing was scheduled on it, so the day is open and empty.';
        }

        return redirect()->route('holidays.index')->with('success', $message);
    }

    /**
     * Add a holiday that returns every year.
     *
     * Entered as a month and a day rather than a date, because that is what the
     * rule actually is — the year it is entered in has nothing to do with it.
     */
    public function storeRule(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'day' => ['required', 'integer', 'between:1,31'],
            'reason' => ['required', 'string', 'max:120'],
        ]);

        // 31 February is not a date. Checked against a leap year, so 29 February
        // is allowed through — it is a real date that simply skips most years.
        if (! checkdate($data['month'], $data['day'], 2024)) {
            return back()->withInput()->withErrors(['day' => 'That day does not exist in that month.']);
        }

        if (HolidayRule::where('type', HolidayRule::FIXED)->where('month', $data['month'])->where('day', $data['day'])->exists()) {
            return back()->withInput()->withErrors(['day' => 'That date is already set as an annual holiday.']);
        }

        $rule = HolidayRule::create([
            'type' => HolidayRule::FIXED,
            'month' => $data['month'],
            'day' => $data['day'],
            'reason' => trim($data['reason']),
            'created_by' => $request->user()->id,
        ]);

        $result = $this->calendar->materialiseRule($rule, null, $request->user());

        $message = $rule->reason.' is now an annual holiday.';

        $message .= $result['closed'] > 0
            ? ' '.$result['closed'].' year(s) closed, through '.$rule->materialised_through.'.'
            : ' It falls at a weekend or in a finished week every year to '.$rule->materialised_through.', so nothing was closed.';

        if ($result['cleared'] > 0) {
            $message .= ' '.$result['cleared'].' scheduled day(s) were cleared from the attendance board.';
        }

        return redirect()->route('holidays.index')->with('success', $message);
    }


    /**
     * Change a closed day — its reason, or the date itself.
     *
     * Moving a day is a reopen and a close, which is exactly what it should be:
     * the ticks come back on the day the centre is open again, and go off the
     * one it is now shut. Nothing special happens, and nothing has to.
     */
    public function update(Request $request, ClosureDay $holiday)
    {
        $data = $request->validate([
            'closed_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $from = $holiday->closed_on->toDateString();
        $to = $data['closed_on'];
        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;

        if ($this->weeks->isFrozen(ScheduleWeek::startOf($from))) {
            return redirect()->route('holidays.index')
                ->with('warning', 'That week has ended — '.$holiday->closed_on->format('j F Y').' stays as it was recorded.');
        }

        // Renaming is the common edit and must not churn the roster: the day is
        // the same day, so the ticks it cleared stay cleared.
        if ($from === $to) {
            $holiday->forceFill(['reason' => $reason])->save();

            return redirect()->route('holidays.index')
                ->with('success', $holiday->closed_on->format('l, j F Y').' is now "'.$holiday->label().'".');
        }

        if (Carbon::parse($to)->isWeekend()) {
            return back()->withErrors(['closed_on' => 'The centre is not open at the weekend — pick a weekday.']);
        }

        if ($this->weeks->isFrozen(ScheduleWeek::startOf($to))) {
            return back()->withErrors(['closed_on' => 'That week has already ended and can no longer be closed.']);
        }

        $restored = $this->weeks->setClosure($from, false);
        $cleared = $this->weeks->setClosure($to, true, $reason, $request->user(), $holiday->holiday_rule_id);

        $message = 'Moved to '.Carbon::parse($to)->format('l, j F Y').'.';

        if ($restored > 0) {
            $message .= ' '.$restored.' scheduled day(s) came back on '.Carbon::parse($from)->format('j M').'.';
        }

        if ($cleared > 0) {
            $message .= ' '.$cleared.' were cleared on the new date.';
        }

        return redirect()->route('holidays.index')->with('success', $message);
    }

    /**
     * Change an annual holiday.
     *
     * Two different edits wearing one form. A rename touches no dates, so the
     * days it already wrote simply take the new name and not a tick moves. A
     * new date is a different holiday in all but identity, so its days are
     * released and written again.
     *
     * A moving holiday — Good Friday, Labour Day — has no month and day to
     * edit. Its rule is a calculation, and the name is all there is to change.
     */
    public function updateRule(Request $request, HolidayRule $rule)
    {
        $fixed = $rule->type === HolidayRule::FIXED;

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'month' => [Rule::requiredIf($fixed), 'integer', 'between:1,12'],
            'day' => [Rule::requiredIf($fixed), 'integer', 'between:1,31'],
        ]);

        $reason = trim($data['reason']);

        if (! $fixed) {
            $renamed = $this->calendar->rename($rule, $reason);

            return redirect()->route('holidays.index')->with('success',
                'Renamed to "'.$reason.'". '.$renamed.' upcoming closed day(s) now read it.');
        }

        if (! checkdate($data['month'], $data['day'], 2024)) {
            return back()->withErrors(['day' => 'That day does not exist in that month.']);
        }

        $clash = HolidayRule::where('type', HolidayRule::FIXED)
            ->where('month', $data['month'])
            ->where('day', $data['day'])
            ->whereKeyNot($rule->id)
            ->exists();

        if ($clash) {
            return back()->withErrors(['day' => 'That date is already set as an annual holiday.']);
        }

        $moved = $rule->month !== (int) $data['month'] || $rule->day !== (int) $data['day'];

        if (! $moved) {
            $renamed = $this->calendar->rename($rule, $reason);

            return redirect()->route('holidays.index')->with('success',
                'Renamed to "'.$reason.'". '.$renamed.' upcoming closed day(s) now read it.');
        }

        $rule->forceFill(['month' => $data['month'], 'day' => $data['day'], 'reason' => $reason])->save();

        $result = $this->calendar->rewrite($rule, $request->user());

        $message = $reason.' now falls on '.$rule->label().'.';
        $message .= ' '.$result['released'].' day(s) reopened, '.$result['closed'].' closed through '.$rule->materialised_through.'.';

        if ($result['cleared'] > 0) {
            $message .= ' '.$result['cleared'].' scheduled day(s) were cleared.';
        }

        return redirect()->route('holidays.index')->with('success', $message);
    }

    /** Stop a holiday recurring. Days already past are left as the record they are. */
    public function destroyRule(HolidayRule $rule)
    {
        $reason = $rule->reason;
        $removed = $this->calendar->forget($rule);

        return redirect()->route('holidays.index')->with('success',
            $reason.' is no longer an annual holiday. '
            .($removed > 0
                ? $removed.' upcoming closed day(s) were reopened; any ticks they cleared are back.'
                : 'No upcoming closed days needed removing.')
            .' Days already past are left as they were recorded.');
    }
}
