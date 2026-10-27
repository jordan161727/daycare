<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ClassroomAssignment;
use App\Services\FloorCover;
use App\Services\FloorPlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The building, room by room, live — Phase 4.2 of docs/work.md.
 *
 * A view of what the door, the register, the staff clock and the roster
 * already say. Two views: the morning, read off the clock this minute, and
 * the afternoon, the day as booked at the hour School Age is back. Tapping
 * a room opens its details beside the map. The one thing written here is
 * the short room's "Move X to Y until Z arrives" — a cover put on today's
 * roster (App\Services\FloorCover), director only.
 */
class FloorPlanController extends Controller
{
    public function index(Request $request, FloorPlan $plan)
    {
        $view = $request->input('view') === 'afternoon' ? 'afternoon' : 'morning';

        return view('floor-plan.index', [
            'plan' => $plan->for($request->user(), $view),
            'open' => $request->input('room'),
        ]);
    }

    public function move(Request $request, FloorCover $cover)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'teacher')],
            'to' => ['required', 'string', Rule::in(ClassroomAssignment::rooms())],
            'for' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'teacher')],
        ]);

        $person = User::findOrFail($data['user_id']);
        $for = isset($data['for']) ? User::find($data['for']) : null;

        try {
            $cover->move($person, $data['to'], $for, $request->user());
        } catch (\DomainException|\InvalidArgumentException $e) {
            return redirect()->route('floor-plan.index', ['room' => $data['to']])->with('error', $e->getMessage());
        }

        $first = fn (User $u) => explode(' ', trim($u->name))[0];

        return redirect()->route('floor-plan.index', ['room' => $data['to']])
            ->with('floor', $first($person).' is in '.$data['to'].' from '.now()->timezone(config('app.timezone'))->format('g:i A')
                .($for ? ' until '.$first($for).' clocks in.' : ' for the rest of the shift.'));
    }
}
