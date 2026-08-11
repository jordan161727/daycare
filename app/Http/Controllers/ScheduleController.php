<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Services\AttendanceProjection;
use App\Services\ClassroomAssignment;
use App\Services\WeekSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ScheduleController extends Controller
{
    public function __construct(private WeekSchedule $weeks, private AttendanceProjection $projection) {}

    /**
     * Tick or untick days. One request covers a single box, a drag across a row,
     * a quick-set preset and a whole column — they only differ in how many slots
     * the browser sends.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d'],
            'is_scheduled' => ['required', 'boolean'],
            'slots' => ['required', 'array', 'min:1', 'max:2000'],
            'slots.*.child_id' => ['required', 'integer'],
            'slots.*.slot_date' => ['required', 'date_format:Y-m-d'],
            'slots.*.session' => ['required', Rule::in(['AM', 'PM', 'FULL'])],
        ]);

        $weekStart = ScheduleWeek::startOf($data['week_start']);
        abort_unless(ScheduleWeek::where('week_start', $weekStart)->exists(), 404, 'That week has not been opened yet.');

        // A finished week is a record of what was planned, and DSS bills against
        // it. Nothing rewrites it after the fact.
        abort_if($this->weeks->isFrozen($weekStart), 422, 'That week has ended and can no longer be edited.');

        $children = Child::whereIn('id', collect($data['slots'])->pluck('child_id')->unique())->get()->keyBy('id');
        $closed = ClosureDay::inWeek($weekStart);
        $updated = 0;

        foreach ($data['slots'] as $slot) {
            $child = $children->get($slot['child_id']);

            // A teacher may only touch their own rooms, and nobody may schedule a
            // child outside the days they are actually enrolled or on a day the
            // centre is shut.
            if (! $child
                || ! $request->user()->canAccessClassroom($child->classroom)
                || ! $child->isEnrolledOn($slot['slot_date'])
                || in_array($slot['slot_date'], $closed, true)
                || ScheduleWeek::startOf($slot['slot_date']) !== $weekStart) {
                continue;
            }

            $updated += ScheduleSlot::where('child_id', $child->id)
                ->where('slot_date', $slot['slot_date'])
                ->where('session', $slot['session'])
                ->update(['is_scheduled' => $data['is_scheduled']]);
        }

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    /**
     * Shut the centre for a day, or open it again — a holiday, a snow day.
     *
     * One request grays out every child in every room, which is the only sane
     * way to record something that is true of the whole centre.
     */
    public function closure(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'closed' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $weekStart = ScheduleWeek::startOf($data['date']);

        abort_if($this->weeks->isFrozen($weekStart), 422, 'That week has ended and can no longer be edited.');

        $cleared = $this->weeks->setClosure($data['date'], $data['closed'], $data['reason'] ?? null, $request->user());

        return response()->json([
            'success' => true,
            'closed' => $data['closed'],
            'cleared' => $cleared,
        ]);
    }

    /**
     * Put a child in a room by hand, or hand them back to the age rule.
     *
     * Set from the schedule because that is where the decision gets made — a
     * child ready for Transition at 17½ months goes there now — but it belongs
     * to the child, not to a day or a week. One child is in one room.
     */
    public function classroom(Request $request)
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer', 'exists:children,id'],
            'classroom' => ['nullable', 'string', Rule::in(ClassroomAssignment::rooms())],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $child = Child::findOrFail($data['child_id']);
        $clearing = blank($data['classroom'] ?? null);

        // Defaulting to today keeps the common case one click, while still
        // letting a move be dated for the day it actually happens.
        $from = $clearing ? null : ($data['effective_from'] ?? today()->toDateString());

        // Room drives the ratio that had to be staffed, so it is part of what a
        // finished week records. Same reason its schedule cannot be rewritten.
        if ($from && $this->weeks->isFrozen(ScheduleWeek::startOf($from))) {
            abort(422, 'That week has ended — an override cannot start in it.');
        }

        $child->forceFill([
            'classroom_override' => $clearing ? null : $data['classroom'],
            'classroom_override_from' => $from,
        ])->save();

        // A move in or out of School Age changes a full day into AM/PM, so the
        // week's boxes have to catch up with the room.
        $this->weeks->resyncSessions($child);

        return response()->json([
            'success' => true,
            'child_id' => $child->id,
            'classroom' => $child->classroom,
            'automatic_classroom' => $child->automaticClassroom(),
            'classroom_override' => $child->classroom_override,
            'classroom_override_from' => $child->classroom_override_from?->toDateString(),
            'override_stale' => $child->classroomOverrideIsStale(),
            'sessions' => $child->sessions(),
        ]);
    }

    /**
     * Tick this week from the projection.
     *
     * The forecast is on the page whether or not anyone acts on it. This is the
     * director accepting it into the plan — one deliberate click, never a thing
     * that happens on its own, because a schedule that rewrote itself from last
     * week's absences is the failure mode the whole design avoids.
     */
    public function project(Request $request)
    {
        $data = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d'],
            'mode' => ['nullable', Rule::in(['replace', 'add'])],
        ]);

        $weekStart = ScheduleWeek::startOf($data['week_start']);

        abort_unless(ScheduleWeek::where('week_start', $weekStart)->exists(), 404, 'That week has not been opened yet.');

        if ($this->weeks->isFrozen($weekStart)) {
            return redirect()
                ->route('attendance.index', ['date' => $weekStart])
                ->with('warning', 'That week has ended and can no longer be edited.');
        }

        $mode = $data['mode'] ?? 'replace';
        $projection = $this->projection->forWeek($weekStart);

        $this->weeks->applyProjection($weekStart, $mode);

        $source = Carbon::parse($projection['source_week_start']);
        $label = $source->format('M j').' – '.$source->copy()->addDays(4)->format('M j');

        // A fill that moved nothing looks exactly like one that failed. Say which
        // it was, and why there was nothing to do.
        if ($this->weeks->tickChange === 0) {
            $warning = $projection['totals']['sessions'] === 0
                ? 'Nothing to project — no attendance in the week of '.$label.', and no days ticked here to fall back on.'
                : 'Nothing changed — this week already matches the projection.';

            if ($mode === 'add' && $this->weeks->tickedDays > $projection['totals']['sessions']) {
                $warning .= ' It also has days the projection does not — choose "Replace this week" to match it exactly.';
            }

            return redirect()->route('attendance.index', ['date' => $weekStart])->with('warning', $warning);
        }

        $message = 'Projected from the week of '.$label.'. '
            .abs($this->weeks->tickChange).' day(s) '.($this->weeks->tickChange > 0 ? 'added' : 'cleared')
            .' — '.$this->weeks->tickedDays.' now ticked this week.';

        if ($projection['totals']['without_pattern'] > 0) {
            $message .= ' '.$projection['totals']['without_pattern']
                .' child(ren) have expected hours but no pattern to project from — set their days by hand.';
        }

        return redirect()->route('attendance.index', ['date' => $weekStart])->with('success', $message);
    }

    /** Rebuild this week's pattern from another week, keeping every sign-in. */
    public function copy(Request $request)
    {
        $data = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d'],
            'source_week_start' => ['required', 'date_format:Y-m-d', 'different:week_start'],
            'with_sign_ins' => ['nullable', 'boolean'],
            'mode' => ['nullable', Rule::in(['replace', 'add'])],
        ]);

        $weekStart = ScheduleWeek::startOf($data['week_start']);
        $source = ScheduleWeek::startOf($data['source_week_start']);

        abort_unless(ScheduleWeek::where('week_start', $source)->exists(), 404, 'That week has not been set up.');

        // Copying rewrites the target's pattern, so a finished week is off limits
        // as a destination. Any week is fair game as a source.
        if ($this->weeks->isFrozen($weekStart)) {
            return redirect()
                ->route('attendance.index', ['date' => $weekStart])
                ->with('warning', 'That week has ended and can no longer be edited.');
        }

        // Sign-ins only travel when the box is ticked, and only an admin may ask
        // for it — it writes attendance for days nobody was actually signed in.
        $withSignIns = $request->boolean('with_sign_ins') && $request->user()->isAdmin();
        $mode = $data['mode'] ?? 'replace';

        $sourceTicks = $this->weeks->tickedIn($source);

        $this->weeks->copyFrom($weekStart, $source, $withSignIns, $mode);

        $label = Carbon::parse($source)->format('M j').' – '.Carbon::parse($source)->addDays(4)->format('M j');

        // A copy that changed nothing looks identical to one that failed, so say
        // so outright rather than reporting a success the grid does not show.
        if ($this->weeks->tickChange === 0 && $this->weeks->copiedSignIns === 0) {
            if ($sourceTicks === 0) {
                $warning = 'Nothing to copy — the week of '.$label.' has no days ticked. Set that week up first, or pick another one.';
            } else {
                $warning = 'Nothing changed — this week already has every day the week of '.$label.' does.';

                // "Add" cannot clear anything, so a week holding extra days looks
                // identical to a no-op. Name the option that would actually move it.
                if ($mode === 'add' && $this->weeks->tickedDays > $sourceTicks) {
                    $warning .= ' It also has '.($this->weeks->tickedDays - $sourceTicks)
                        .' day(s) that week does not — choose "Replace this week" to match it exactly.';
                }
            }

            return redirect()
                ->route('attendance.index', ['date' => $weekStart])
                ->with('warning', $warning);
        }

        $message = $mode === 'add'
            ? 'Copied from '.$label.'. '.$this->weeks->tickChange.' day(s) added — '.$this->weeks->tickedDays.' now ticked this week.'
            : 'Copied from '.$label.'. This week now matches it — '.$this->weeks->tickedDays.' day(s) ticked.';

        if ($this->weeks->copiedSignIns > 0) {
            $message .= ' '.$this->weeks->copiedSignIns.' sign-in(s) came across too.';
        }

        return redirect()
            ->route('attendance.index', ['date' => $weekStart])
            ->with('success', $message);
    }
}
