<?php

namespace App\Http\Controllers;

use App\Models\ClosureDay;
use App\Models\LeaveRequest;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Services\ClassroomAssignment;
use App\Services\ScheduleEditor;
use App\Services\StaffSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The Schedule — Phase 7 of docs/work.md, as Schedule.dc.html draws it.
 *
 * Two readings of one week. By teacher is a row per person across the five
 * days, as bars or as a simple table. By room is one day at a time, room by
 * room, with the coverage strip under each and the people not working that
 * day in a tray beside it — the view the director edits in, by dragging a
 * bar to another room or a name into a room that is short.
 *
 * A week is a draft until it is published. Staff see it only after that, so
 * a roster can be generated, argued with and put right before anybody
 * arranges their life around it.
 */
class StaffScheduleController extends Controller
{
    public function __construct(
        private StaffSchedule $scheduler,
        private ScheduleEditor $editor,
    ) {}

    public function index(Request $request)
    {
        $weekStart = StaffScheduleWeek::startOf($request->query('week', now()->toDateString()));
        $week = StaffScheduleWeek::with('publishedBy')->firstWhere('week_start', $weekStart);
        $dates = StaffScheduleWeek::datesOf($weekStart);
        $isAdmin = $request->user()->isAdmin();

        // A teacher sees the published week and nothing of a draft. A week
        // nobody generated and nobody published — shifts entered by hand —
        // is shown as it is: there is no draft to hide.
        $hidden = ! $isAdmin && $week !== null && ! $week->isPublished();

        $shifts = $hidden
            ? collect()
            : StaffShift::with('user:id,name,employment,title')->where('week_start', $weekStart)->orderBy('starts_at')->get();

        $view = in_array($request->query('view', $request->query('mode')), ['room', 'teacher'], true) ? $request->query('view', $request->query('mode')) : 'teacher';
        $day = in_array($request->query('day'), config('daycare.days'), true) ? $request->query('day') : $this->todayOr($dates);

        return view('staff-schedule.index', [
            'weekStart' => $weekStart,
            'dates' => $dates,
            'week' => $week,
            'status' => $week?->statusLine(),
            'hidden' => $hidden,
            'isAdmin' => $isAdmin,
            'shifts' => $shifts,
            'rooms' => ClassroomAssignment::rooms(),
            'colours' => ScheduleEditor::COLOURS,
            'view' => $view,
            'layout' => $request->query('layout') === 'table' ? 'table' : 'bars',
            'day' => $day,
            'teach' => $hidden ? [] : $this->editor->week($weekStart),
            'dayData' => $hidden ? null : $this->editor->day($weekStart, $day),
            'gaps' => $hidden ? [] : $this->editor->gaps($weekStart),
            'open' => (int) config('daycare.open'),
            'close' => (int) config('daycare.close'),
            'closures' => ClosureDay::betweenDates(reset($dates), end($dates))->get()
                ->keyBy(fn ($closed) => $closed->closed_on->toDateString())
                ->map(fn ($closed) => $closed->label()),
        ]);
    }

    /** The day to open By room on: today if it is in the week, else Monday. */
    private function todayOr(array $dates): string
    {
        foreach ($dates as $code => $date) {
            if ($date->isToday()) {
                return $code;
            }
        }

        return array_key_first($dates);
    }

    /**
     * One person's own week, and nothing else on the floor.
     *
     * The full roster answers "who is in on Wednesday", which is a question
     * about the centre. This answers "when am I in", which is a question about
     * you — and it is the one a teacher actually opens on a phone in a
     * corridor. Same shifts, no gantt, no colleagues, no ratio bars.
     *
     * A draft week is not shown: until the director publishes it, the shifts
     * are a plan being argued with, not one to arrange a life around.
     */
    public function mine(Request $request)
    {
        $user = $request->user();
        $weekStart = StaffScheduleWeek::startOf($request->query('week', now()->toDateString()));
        $week = StaffScheduleWeek::firstWhere('week_start', $weekStart);
        $draft = $week !== null && ! $week->isPublished();

        $shifts = $draft
            ? collect()
            : StaffShift::where('user_id', $user->id)->where('week_start', $weekStart)->orderBy('starts_at')->get();

        $dates = StaffScheduleWeek::datesOf($weekStart);

        // The roster is never generated for a day the centre is shut, so
        // without this a public holiday reaches this screen as an empty card
        // reading "Not scheduled" — which is true, and says nothing about why.
        $closures = ClosureDay::betweenDates($dates[array_key_first($dates)], end($dates))
            ->get()
            ->keyBy(fn ($day) => $day->closed_on->toDateString())
            ->map(fn ($day) => $day->label());

        return view('staff-schedule.mine', [
            'staff' => $user,
            'weekStart' => $weekStart,
            'dates' => $dates,
            // Their own approved leave, so the day they booked off shows as
            // booked off rather than as a day they are simply not needed.
            'leave' => LeaveRequest::byUserAndDate($weekStart, end($dates)->toDateString())[$user->id] ?? [],
            'week' => $week,
            'draft' => $draft,
            'byDay' => $shifts->groupBy('day'),
            'minutes' => $shifts->sum(fn (StaffShift $shift) => $shift->minutes()),
            // What the rules say they are owed, so a short week is visible as a
            // short week rather than as a number with nothing to compare it to.
            'expected' => $user->loadMissing('staffRules')->weeklyHours(),
            'closures' => $closures,
            // The one question this page is actually opened to answer, hoisted
            // out of the grid so it is not five cards away from being read.
            'nextShift' => $this->nextShift($shifts),
        ]);
    }

    /**
     * The shift that has not finished yet — today's if it is still running or
     * still to come, otherwise the next one this week.
     *
     * Null once the week is behind them, which is the honest answer: a banner
     * pointing at Monday on a Friday afternoon is worse than no banner.
     */
    private function nextShift($shifts): ?StaffShift
    {
        $now = now();
        $minute = $now->hour * 60 + $now->minute;

        return $shifts
            ->sortBy([['shift_date', 'asc'], ['starts_at', 'asc']])
            ->first(function (StaffShift $shift) use ($now, $minute) {
                $date = $shift->shift_date->toDateString();

                return $date > $now->toDateString()
                    || ($date === $now->toDateString() && $shift->ends_at > $minute);
            });
    }

    /**
     * Re-solve the week from the current rules.
     *
     * Destructive by design — see StaffSchedule::generate(). The button says
     * so. The result is a new draft: whatever was published before is not
     * what this is, so it waits to be published again.
     */
    public function generate(Request $request)
    {
        $weekStart = StaffScheduleWeek::startOf($request->input('week', now()->toDateString()));

        $week = $this->scheduler->generate($weekStart, $request->user());
        $week->forceFill(['published_at' => null, 'published_by' => null, 'edits' => 0])->save();

        $warnings = count($week->warnings ?? []);

        return redirect()
            ->route('staff-schedule.index', ['week' => $weekStart, 'mode' => $request->input('mode', 'teacher')])
            ->with('success', $warnings === 0
                ? 'Schedule generated with no conflicts. It is a draft until you publish it.'
                : "Schedule generated with {$warnings} thing".($warnings === 1 ? '' : 's').' to look at. It is a draft until you publish it.');
    }

    /** Make the week visible to staff, and start counting edits from here. */
    public function publish(Request $request)
    {
        $weekStart = StaffScheduleWeek::startOf($request->input('week', now()->toDateString()));

        $week = StaffScheduleWeek::firstOrCreate(['week_start' => $weekStart], ['generated_at' => null]);
        $week->forceFill(['published_at' => now(), 'published_by' => $request->user()->id, 'edits' => 0])->save();

        return redirect()
            ->route('staff-schedule.index', ['week' => $weekStart, 'view' => $request->input('view', 'teacher')])
            ->with('success', 'Published. Staff can see this week now, on the Schedule and on My Schedule.');
    }

    /** One day of the By-room view, as data — what the day chips fetch. */
    public function day(Request $request)
    {
        [$weekStart, $day] = $this->weekAndDay($request);

        return response()->json($this->editor->day($weekStart, $day));
    }

    /**
     * What a change would do, before it is saved.
     *
     * Asked again every time the director picks a different option in the
     * pending card, so the Must / Short / Over lines always describe the
     * change about to be applied, not the one first dropped.
     */
    public function preview(Request $request)
    {
        [$weekStart, $day] = $this->weekAndDay($request);
        $change = $this->change($request);

        try {
            return response()->json($this->editor->preview($weekStart, $day, $change));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Save the change — refused whole when a Must rule blocks it. */
    public function apply(Request $request)
    {
        [$weekStart, $day] = $this->weekAndDay($request);
        $change = $this->change($request);

        try {
            return response()->json(['saved' => true] + $this->editor->apply($weekStart, $day, $change, $request->user()));
        } catch (\InvalidArgumentException|\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Time off entered from a bar: out all day, out until, or leaving at, for
     * the person and day the bar belongs to. The same entry the Staff page
     * keeps, so it shows there too and the Schedule treats it as a Must.
     */
    public function timeOff(Request $request)
    {
        [$weekStart, $day] = $this->weekAndDay($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereIn('role', ['teacher', 'admin'])],
            'kind' => ['required', Rule::in(['all', 'until', 'leave'])],
            'minute' => ['nullable', 'integer', 'min:0', 'max:1440', 'required_unless:kind,all'],
            'reason' => ['required', Rule::in(['appointment', 'sick', 'personal', 'vacation', 'family'])],
        ]);

        $date = StaffScheduleWeek::datesOf($weekStart)[$day]->toDateString();

        if (StaffTimeOff::where('user_id', $data['user_id'])->onDate($date)->exists()) {
            return response()->json(['message' => 'There is already time off on that day. Change it on the Staff page.'], 422);
        }

        $this->editor->timeOff(User::findOrFail($data['user_id']), $date, $data['kind'], isset($data['minute']) ? (int) $data['minute'] : null, $data['reason'], $request->user());

        return response()->json(['saved' => true] + $this->editor->day($weekStart, $day));
    }

    /** The week as a sheet for the wall: one row per person, plain times. */
    public function print(Request $request)
    {
        $weekStart = StaffScheduleWeek::startOf($request->query('week', now()->toDateString()));
        $dates = StaffScheduleWeek::datesOf($weekStart);

        return view('staff-schedule.print', [
            'weekStart' => $weekStart,
            'dates' => $dates,
            'teach' => $this->editor->week($weekStart),
            'week' => StaffScheduleWeek::firstWhere('week_start', $weekStart),
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function weekAndDay(Request $request): array
    {
        $data = validator($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'day' => ['required', Rule::in(config('daycare.days'))],
        ])->validate();

        return [StaffScheduleWeek::startOf($data['week']), $data['day']];
    }

    /** The change a request describes, checked for shape here and for sense in the editor. */
    private function change(Request $request): array
    {
        // "to" is the room for a move or an add, and the end minute for a trim.
        $trim = $request->input('kind') === 'trim';

        return $request->validate([
            'kind' => ['required', Rule::in(['move', 'add', 'trim', 'remove'])],
            'id' => ['required_if:kind,move,trim,remove', 'nullable', 'integer'],
            'user_id' => ['required_if:kind,add', 'nullable', 'integer'],
            'to' => $trim ? ['required', 'integer', 'min:0', 'max:1440'] : ['required_if:kind,move,add', 'nullable', Rule::in(ClassroomAssignment::rooms())],
            'opt' => ['nullable', 'string', 'max:10'],
            'from' => ['required_if:kind,trim', 'nullable', 'integer', 'min:0', 'max:1440'],
        ]);
    }
}
