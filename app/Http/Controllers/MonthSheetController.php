<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\SymptomCode;
use App\Services\ClassroomAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The month on one page: the paper sheet the centre already keeps.
 *
 * Four lines per child — in, the check taken then, out, the check taken then —
 * and a column per day of the month. It is laid out to match the form it
 * replaces, because the people reading it have read that form for years and
 * the whole value of a printout is that it can be handed to somebody who was
 * not there.
 *
 * One in and one out per child per day, even in a room that books a morning
 * and an afternoon separately: the paper has one pair of lines and so does
 * this. What it shows is the first arrival and the last departure, which is
 * the honest reading of "when was this child here today".
 *
 * Read-only. Corrections are made on the screens that record who made them;
 * a month grid somebody could type into is a month grid with no audit behind
 * it.
 */
class MonthSheetController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        validator($request->only('month', 'year'), [
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ])->validate();

        $month = (int) ($request->input('month') ?: today()->month);
        $year = (int) ($request->input('year') ?: today()->year);

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        // Room decides which block a child is filtered into, so it has to be
        // current before anything is grouped.
        ClassroomAssignment::syncAll();

        $children = Child::visibleTo($user)
            ->where('status', 'Active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $records = Attendance::whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))
            ->get()
            ->groupBy([
                fn (Attendance $row) => $row->child_id,
                fn (Attendance $row) => $row->attendance_date->toDateString(),
            ]);

        $days = collect(range(1, $end->day))->map(fn (int $day) => $start->copy()->setDay($day));
        $timezone = config('app.timezone');
        $today = today()->toDateString();

        $rows = $children->map(function (Child $child) use ($records, $days, $timezone) {
            $byDay = [];

            foreach ($days as $day) {
                $iso = $day->toDateString();
                $onDay = $records[$child->id][$iso] ?? collect();

                if ($onDay->isEmpty()) {
                    continue;
                }

                // First in and last out, which is what one pair of lines can
                // honestly say about a day that may hold two sessions.
                $first = $onDay->sortBy('signed_in_at')->first();
                $last = $onDay->filter(fn (Attendance $row) => $row->signed_out_at !== null)
                    ->sortBy('signed_out_at')->last();

                $byDay[$iso] = [
                    'in' => self::clock($first->signed_in_at, $timezone),
                    'in_code' => $first->health_in_code,
                    'out' => $last ? self::clock($last->signed_out_at, $timezone) : null,
                    'out_code' => $last?->health_out_code,
                ];
            }

            return [
                'id' => $child->id,
                'name' => $child->last_name.', '.$child->first_name,
                'lan' => $child->lan,
                'room' => $child->classroom ?: 'Unassigned',
                'animal' => ClassroomAssignment::animal($child->classroom),
                // The full range here, not the compact one: this column has the
                // width for it and a parent reading a printout should not have
                // to decode "800-500".
                'hours' => $child->scheduleLabel(),
                'byDay' => $byDay,
            ];
        });

        /*
         * The numbers over the sheet, all about today.
         *
         * A month sheet is read at the end of a month, but it is opened during
         * one — and the question somebody has while looking at it is the same
         * one the register answers: who is here right now, and is anybody
         * unwell.
         */
        $todayRows = $rows->map(fn (array $row) => $row['byDay'][$today] ?? null);

        $stats = [
            'enrolled' => $rows->count(),
            'in' => $todayRows->filter(fn (?array $day) => $day !== null && $day['in'] !== null)->count(),
            'sick' => $todayRows->filter(fn (?array $day) => $day !== null
                && ((($day['in_code'] ?? null) !== null && $day['in_code'] !== 0)
                    || (($day['out_code'] ?? null) !== null && $day['out_code'] !== 0)))->count(),
        ];

        $stats['not_in'] = $stats['enrolled'] - $stats['in'];

        // A chip per room, with how many of its children are in today. Built
        // from the children themselves so a room nobody is in does not get a
        // chip that filters to nothing.
        $roomChips = $rows->groupBy('room')->map(fn ($group, $room) => [
            'room' => $room,
            'animal' => ClassroomAssignment::animal($room),
            'total' => $group->count(),
            'in' => $group->filter(fn (array $row) => ($row['byDay'][today()->toDateString()]['in'] ?? null) !== null)->count(),
        ])->sortBy('room')->values();

        // The two totals the paper form carries: how many children were in on
        // each day, and what those add up to over the month.
        $perDay = [];

        foreach ($days as $day) {
            $iso = $day->toDateString();
            $perDay[$iso] = $rows->filter(fn (array $row) => ($row['byDay'][$iso]['in'] ?? null) !== null)->count();
        }

        return view('attendance.month-sheet', [
            'rows' => $rows,
            'days' => $days,
            'perDay' => $perDay,
            'monthTotal' => array_sum($perDay),
            'month' => $month,
            'year' => $year,
            'start' => $start,
            'stats' => $stats,
            'roomChips' => $roomChips,
            'codes' => SymptomCode::active(),
        ]);
    }

    /**
     * A time as the sheet shows it — "8:12a", "5:25p".
     *
     * One letter rather than two: a column here is a few millimetres wide and
     * "AM" is a character a cell across nearly eight thousand of them. The
     * same clock Child::timeShort writes everywhere else in the app, so a
     * time on this sheet reads like a time on any other screen.
     */
    private static function clock(?Carbon $at, string $timezone): ?string
    {
        return Child::timeShort($at?->timezone($timezone));
    }
}
