<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Services\ClassroomAssignment;
use App\Services\WeekSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ScheduleController extends Controller
{
    public function __construct(private WeekSchedule $weeks) {}

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
     * Put an hour on a day still to come, or take it off again.
     *
     * The other half of a booking. "Expected on Thursday" is the day; this is
     * the hour, and a room cannot staff a morning knowing only the first.
     *
     * Three things it is deliberately not:
     *
     *   Not an arrival. No row is written to `attendances`, so Thursday's
     *   headcount, its ratios and its bill stay empty until somebody actually
     *   walks in. The plan and the record are different facts and the whole
     *   reason the register is trustworthy is that it only ever held the
     *   second one.
     *
     *   Not for today or for a day gone. Those have a real arrival time, or
     *   they have a blank that means nobody came. An intention printed over
     *   either would be read as the fact.
     *
     *   Not a tick on its own. An hour implies the day: giving a time for a
     *   child marked "not attending" and leaving them not attending is a state
     *   nobody meant, so the tick goes on with it.
     */
    public function plannedTime(Request $request)
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'slot_date' => ['required', 'date_format:Y-m-d'],
            'session' => ['required', Rule::in(['AM', 'PM', 'FULL'])],
            // Null clears it — booked, hour no longer agreed — which is a
            // different answer from never having set one but stores the same.
            'planned_time' => ['nullable', 'date_format:H:i'],
        ]);

        $weekStart = ScheduleWeek::startOf($data['slot_date']);

        abort_unless(ScheduleWeek::where('week_start', $weekStart)->exists(), 404, 'That week has not been opened yet.');
        abort_if($this->weeks->isFrozen($weekStart), 422, 'That week has ended and can no longer be edited.');

        if ($data['slot_date'] <= now()->toDateString()) {
            return response()->json([
                'message' => Carbon::parse($data['slot_date'])->format('l, M j')
                    .' has already begun — its times are the hours children arrived, not hours they are booked for.',
            ], 422);
        }

        $child = Child::find($data['child_id']);

        abort_unless($child && $request->user()->canAccessClassroom($child->classroom), 403);

        if (! $child->isEnrolledOn($data['slot_date'])) {
            return response()->json([
                'message' => $child->displayName().' is not on the roll on '
                    .Carbon::parse($data['slot_date'])->format('l, M j').'.',
            ], 422);
        }

        if (in_array($data['slot_date'], ClosureDay::inWeek($weekStart), true)) {
            return response()->json([
                'message' => 'The centre is closed on '.Carbon::parse($data['slot_date'])->format('l, M j').'.',
            ], 422);
        }

        $slot = ScheduleSlot::where('child_id', $child->id)
            ->where('slot_date', $data['slot_date'])
            ->where('session', $data['session'])
            ->first();

        abort_unless($slot, 404, 'There is no booking to put an hour on.');

        $slot->planned_time = $data['planned_time'];

        // An hour means they are coming. Clearing it says nothing either way,
        // so the tick is left exactly as it was.
        if ($data['planned_time'] !== null) {
            $slot->is_scheduled = true;
        }

        $slot->save();

        return response()->json([
            'success' => true,
            'planned_time' => $slot->plannedTimeValue(),
            'is_scheduled' => (bool) $slot->is_scheduled,
        ]);
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

        // Closing reports what it took off the board; reopening reports what it
        // put back. The browser needs the second one to know whether the grid it
        // is holding is still true.
        $moved = $this->weeks->setClosure($data['date'], $data['closed'], $data['reason'] ?? null, $request->user());

        return response()->json([
            'success' => true,
            'closed' => $data['closed'],
            'cleared' => $data['closed'] ? $moved : 0,
            'restored' => $data['closed'] ? 0 : $moved,
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

}
