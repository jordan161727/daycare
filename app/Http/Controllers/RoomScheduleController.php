<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\RoomSchedule;
use App\Services\ClassroomAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Each room's day: the hours it runs, and the children who are in it.
 *
 * This is the room-by-room reading of the roll. The children's page lists every
 * child and says which room they are in; this one turns that round and shows a
 * room at a time, which is how a director asks the question — what does Infant
 * look like, who is in it, and are their hours inside the room's.
 *
 * Every room is on the one page and the hours are saved in one go. Rooms are a
 * fixed, short list that changes about never, so a card per room with an index
 * and an update is the whole of it — a create/edit/delete cycle would be three
 * screens for a table nobody adds to.
 */
class RoomScheduleController extends Controller
{
    public function index()
    {
        $schedules = RoomSchedule::byRoom();

        // Every active child once, grouped by the room they are in. One query
        // for the page rather than one per room.
        $children = Child::query()
            ->where('status', 'Active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->groupBy('classroom');

        return view('room-schedule.index', [
            'rooms' => collect(ClassroomAssignment::rooms())->map(fn (string $room) => [
                'name' => $room,
                'schedule' => $schedules[$room] ?? null,
                // What the room is actually holding, so a room being given
                // hours is being given them for children whose own hours are
                // on the screen next to them.
                'children' => $children[$room] ?? collect(),
            ]),
        ]);
    }

    public function update(Request $request)
    {
        $rooms = ClassroomAssignment::rooms();

        // Times arrive from <input type="time"> as H:i, but a value read back
        // out of MySQL is H:i:s. Both are cut to H:i before the rules see them,
        // the same way the child form does it.
        $request->merge(['rooms' => collect($request->input('rooms', []))
            ->map(fn ($row) => array_merge($row, [
                'opens_at' => $this->normalizeTime($row['opens_at'] ?? null),
                'closes_at' => $this->normalizeTime($row['closes_at'] ?? null),
            ]))
            ->all()]);

        $validated = $request->validate([
            'rooms' => ['array'],
            'rooms.*.room' => ['required', 'string', Rule::in($rooms)],
            'rooms.*.opens_at' => ['nullable', 'date_format:H:i'],
            'rooms.*.closes_at' => ['nullable', 'date_format:H:i', 'after:rooms.*.opens_at'],
        ]);

        foreach ($validated['rooms'] ?? [] as $row) {
            RoomSchedule::updateOrCreate(
                ['room' => $row['room']],
                [
                    'opens_at' => $row['opens_at'] ?? null,
                    'closes_at' => $row['closes_at'] ?? null,
                ]
            );
        }

        return redirect()->route('room-schedule.index')->with('success', 'Room hours saved.');
    }

    private function normalizeTime(?string $value): ?string
    {
        if (blank($value)) return null;
        try {
            return Carbon::parse(trim($value))->format('H:i');
        } catch (\Throwable) {
            return $value;
        }
    }
}
