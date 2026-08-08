<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Child;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReportController extends Controller
{
    /** Rooms are printed in this order; anything unlisted follows alphabetically. */
    private const ROOM_ORDER = ['Infant', 'Transition', 'Toddler', 'PreK', 'UPK-4', 'School Age'];

    /** Rooms signed in by half day rather than a single full-day stamp. */
    private const SESSION_ROOMS = ['School Age'];

    /** Subtotals printed under the last room of each set, as on the centre's sheet. */
    private const COMBINED_TOTALS = [
        ['label' => 'Tr / Toddler total', 'after' => 'Toddler', 'rooms' => ['Transition', 'Toddler']],
        ['label' => 'PreK & UPK-4 total', 'after' => 'UPK-4', 'rooms' => ['PreK', 'UPK-4']],
    ];

    public function index(Request $request)
    {
        $user = $request->user();

        // Empty form fields arrive as null (ConvertEmptyStringsToNull), so normalise
        // before comparing — a null classroom used to filter on "classroom IS NULL".
        $selectedDate = trim((string) $request->input('date')) ?: today()->toDateString();

        validator(['date' => $selectedDate], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $startOfWeek = Carbon::parse($selectedDate)->startOfWeek(Carbon::MONDAY);
        $dates = collect(range(0, 4))->map(fn ($offset) => $startOfWeek->copy()->addDays($offset));

        $classrooms = $user->isAdmin()
            ? Child::visibleTo($user)->where('status', 'Active')->distinct()->orderBy('classroom')->pluck('classroom')
            : collect($user->assignedClassrooms());

        $selectedClassroom = trim((string) $request->input('classroom'));

        if (! $user->isAdmin() && $selectedClassroom === '' && $classrooms->isNotEmpty()) {
            $selectedClassroom = $classrooms->first();
        }

        $children = Child::visibleTo($user)
            ->where('status', 'Active')
            ->when($selectedClassroom !== '', fn ($query) => $query->where('classroom', $selectedClassroom))
            ->orderBy('classroom')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $presence = $this->presenceMap($children, $dates);

        $byRoom = $children->groupBy('classroom');
        $rooms = collect(self::ROOM_ORDER)
            ->intersect($byRoom->keys())
            ->merge($byRoom->keys()->diff(self::ROOM_ORDER)->sort())
            ->values();

        $blocks = $rooms->map(fn ($room) => [
            'room' => $room,
            'splitsSessions' => in_array($room, self::SESSION_ROOMS, true),
            'children' => $byRoom[$room],
            'totals' => $this->dailyTotals($byRoom[$room], $dates, $presence),
            'sessionTotals' => $this->sessionTotals($byRoom[$room], $dates, $presence),
            'combined' => $this->combinedTotalFor($room, $rooms, $byRoom, $dates, $presence),
        ]);

        return view('reports.index', [
            'blocks' => $blocks,
            'dates' => $dates,
            'presence' => $presence,
            'children' => $children,
            'classrooms' => $classrooms,
            'selectedClassroom' => $selectedClassroom,
            'selectedDate' => $selectedDate,
            'centerTotals' => $this->dailyTotals($children, $dates, $presence),
        ]);
    }

    /** [child id][date][session] => true */
    private function presenceMap(Collection $children, Collection $dates): array
    {
        $records = Attendance::query()
            ->whereBetween('attendance_date', [$dates->first()->toDateString(), $dates->last()->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))
            ->get();

        $presence = [];

        foreach ($records as $record) {
            $presence[$record->child_id][$record->attendance_date->toDateString()][$record->session ?: 'FULL'] = true;
        }

        return $presence;
    }

    /** Head count per day — a child signed in for both AM and PM still counts once. */
    private function dailyTotals(Collection $children, Collection $dates, array $presence): array
    {
        $totals = [];

        foreach ($dates as $date) {
            $key = $date->toDateString();
            $totals[$key] = $children->filter(fn ($child) => filled($presence[$child->id][$key] ?? null))->count();
        }

        return $totals;
    }

    /** Separate AM and PM counts per day, for the half-day rooms. */
    private function sessionTotals(Collection $children, Collection $dates, array $presence): array
    {
        return $dates->mapWithKeys(function ($date) use ($children, $presence) {
            $key = $date->toDateString();

            return [$key => [
                'AM' => $children->filter(fn ($child) => $this->isPresent($presence, $child->id, $key, 'AM'))->count(),
                'PM' => $children->filter(fn ($child) => $this->isPresent($presence, $child->id, $key, 'PM'))->count(),
            ]];
        })->all();
    }

    /** A full-day stamp covers both halves of the day. */
    private function isPresent(array $presence, int $childId, string $date, string $session): bool
    {
        $sessions = $presence[$childId][$date] ?? [];

        return isset($sessions[$session]) || isset($sessions['FULL']);
    }

    private function combinedTotalFor(string $room, Collection $rooms, Collection $byRoom, Collection $dates, array $presence): ?array
    {
        foreach (self::COMBINED_TOTALS as $combination) {
            if ($combination['after'] !== $room) continue;
            if (collect($combination['rooms'])->diff($rooms)->isNotEmpty()) continue;

            $group = collect($combination['rooms'])->flatMap(fn ($name) => $byRoom[$name]);

            return ['label' => $combination['label'], 'totals' => $this->dailyTotals($group, $dates, $presence)];
        }

        return null;
    }
}
