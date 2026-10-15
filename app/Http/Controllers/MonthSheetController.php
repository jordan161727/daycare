<?php

namespace App\Http\Controllers;

use App\Exports\MonthSheetExport;
use App\Models\Attendance;
use App\Models\AttendanceComment;
use App\Models\DoorAttendance;
use App\Models\Child;
use App\Models\HealthAudit;
use App\Models\SymptomCode;
use App\Models\User;
use App\Services\ClassroomAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The month on one page: the paper sheet the centre already keeps.
 *
 * Four lines per child — in, the check taken then, out, the check taken then —
 * and a column per day of the month. It is laid out to match the form it
 * replaces, because the people reading it have read that form for years and
 * the whole value of a printout is that it can be handed to somebody who was
 * not there.
 *
 * One in and one out per child per day on the screen, even in a room that
 * books a morning and an afternoon separately: the paper has one pair of
 * lines and so does this. What it shows is the first arrival and the last
 * departure, which is the honest reading of "when was this child here today".
 *
 * Underneath that, every entry. A child who left for the dentist and came
 * back, or a School Age child's morning and afternoon, is two clock-ins and
 * two clock-outs, each with the check taken at it — and the row only keeps
 * the latest of those checks, the earlier living on in the audit. So each
 * day also carries its entries in order, each with its own codes, which is
 * what the export prints: nothing a second arrival overwrote is lost.
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
        // Which record the page reads: the register (the paper form) or the door.
        $source = $request->input('source') === 'door' ? 'door' : 'register';

        $sheet = $this->month($user, $month, $year, $source);

        return view('attendance.month-sheet', $sheet + [
            'month' => $month,
            'year' => $year,
            'source' => $source,
            'codes' => SymptomCode::active(),
        ]);
    }

    /**
     * The month as the paper form, as a workbook — a sheet per room.
     *
     * Same rows the page is drawn from, so the file and the screen cannot
     * disagree; ?room= narrows it to one room's sheet, which is how the form
     * is actually kept.
     */
    public function export(Request $request)
    {
        validator($request->only('month', 'year', 'room'), [
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'room' => ['nullable', 'string', 'max:60'],
        ])->validate();

        $month = (int) ($request->input('month') ?: today()->month);
        $year = (int) ($request->input('year') ?: today()->year);
        $room = trim((string) $request->input('room')) ?: null;

        // One sheet per room, named for the room, as the paper form is kept.
        // The two records are laid over each other for it: the register's
        // day where it has one, and the door's filling anything the register
        // left blank — which for the health lines is nearly everything, since
        // the door is where the check is taken.
        $sheet = $this->month($request->user(), $month, $year, 'register');
        $door = $this->month($request->user(), $month, $year, 'door');
        $sheet['rows'] = self::overlay($sheet['rows'], $door['rows']);

        $name = 'attendance-'.$sheet['start']->format('Y-m').($room ? '-'.str_replace(' ', '-', strtolower($room)) : '').'.xlsx';

        // The month's comments, oldest first, for the Comments sheet — the
        // room's alone when a room was asked for.
        $childIds = $sheet['rows']->when($room !== null, fn ($rows) => $rows->where('room', $room))->pluck('id');
        $comments = AttendanceComment::with(['attendance', 'child', 'author'])
            ->whereIn('child_id', $childIds)
            ->whereHas('attendance', fn ($query) => $query->whereBetween('attendance_date', [$sheet['start']->toDateString(), $sheet['start']->copy()->endOfMonth()->toDateString()]))
            ->orderBy('created_at')
            ->get();

        return Excel::download(
            new MonthSheetExport($sheet['rows'], $sheet['days'], $sheet['perDay'], $month, $year, $room, $comments),
            $name,
        );
    }

    /**
     * The register's rows with the door's laid underneath.
     *
     * Both are built from the same children in the same order, so the rows
     * pair by child id. Day by day, each of the four values keeps what the
     * register says and takes the door's only where the register has
     * nothing — a time the register never took, or a health code it never
     * asked for. A day only the door knows about comes through whole.
     *
     * @param  \Illuminate\Support\Collection<int, array>  $register
     * @param  \Illuminate\Support\Collection<int, array>  $door
     * @return \Illuminate\Support\Collection<int, array>
     */
    public static function overlay($register, $door)
    {
        $doorByChild = $door->keyBy('id');

        return $register->map(function (array $row) use ($doorByChild) {
            $doorDays = $doorByChild[$row['id']]['byDay'] ?? [];

            foreach ($doorDays as $iso => $doorDay) {
                $day = $row['byDay'][$iso] ?? [];

                foreach (['in', 'in_code', 'out', 'out_code'] as $key) {
                    if (($day[$key] ?? null) === null && ($doorDay[$key] ?? null) !== null) {
                        $day[$key] = $doorDay[$key];
                    }
                }

                // The entries are one record's or the other's, whole: the
                // register's where it has any, else the door's. Two lists
                // of the same day interleaved would count every arrival twice.
                if (empty($day['entries']) && ! empty($doorDay['entries'])) {
                    $day['entries'] = $doorDay['entries'];
                }

                $row['byDay'][$iso] = $day + ['in' => null, 'in_code' => null, 'out' => null, 'out_code' => null, 'entries' => []];
            }

            return $row;
        });
    }

    /**
     * The month, built once for the page and the file.
     *
     * Public so the export and its tests can ask for it directly.
     *
     * @return array{rows: \Illuminate\Support\Collection, days: \Illuminate\Support\Collection, perDay: array<string, int>, monthTotal: int, start: Carbon, stats: array, roomChips: \Illuminate\Support\Collection}
     */
    public function month(User $user, int $month, int $year, string $source = 'register'): array
    {
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

        // The register's rows or the door's: two records that do not meet.
        $records = ($source === 'door' ? DoorAttendance::query() : Attendance::query())
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))
            // The trips out and back, and the audit, are what the entries
            // are rebuilt from — see entries().
            ->with(['returns', 'healthAudits'])
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
                    // Every clock-in and clock-out of the day, in order, each
                    // with the check taken at it.
                    'entries' => self::entries($onDay, $timezone),
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

        $monthTotal = array_sum($perDay);

        return compact('rows', 'days', 'perDay', 'monthTotal', 'start', 'stats', 'roomChips');
    }

    /**
     * Every clock-in and clock-out of a child's day, oldest first.
     *
     * A row holds the first arrival and the latest departure; its returns
     * hold each [left, back] between. Read together they are the day's
     * entries, and a day with two sessions is both rows' entries in turn.
     *
     * The codes are the harder part. The row keeps only the latest arrival
     * check — a child clocked in again is checked again, and that check
     * replaces the column — and the leaving check comes off when the child
     * is back in. The earlier ones live in the audit, so each entry's codes
     * are read from there: the latest change of each direction written
     * before the next arrival. The last entry's are the row's own columns,
     * which is what the screen shows and what any later correction edits.
     *
     * @param  \Illuminate\Support\Collection<int, Attendance>  $onDay  one child's rows on one day
     * @return list<array{in: ?string, in_code: ?int, out: ?string, out_code: ?int}>
     */
    private static function entries($onDay, string $timezone): array
    {
        $entries = [];

        foreach ($onDay->sortBy('signed_in_at') as $row) {
            if ($row->signed_in_at === null) {
                continue;
            }

            $arrivals = [$row->signed_in_at];
            $departures = [];

            foreach ($row->returns->sortBy('returned_at') as $trip) {
                $departures[] = $trip->left_at;
                $arrivals[] = $trip->returned_at;
            }

            $departures[] = $row->signed_out_at;

            $audits = $row->healthAudits->sortBy('id')->values();
            $count = count($arrivals);

            foreach ($arrivals as $index => $arrivedAt) {
                if ($index === $count - 1) {
                    $inCode = $row->health_in_code;
                    $outCode = $row->health_out_code;
                } else {
                    // The audit lines that belong to this entry: written
                    // before the next arrival, and — except for the first,
                    // whose arrival may have been typed in after its check —
                    // not before this one.
                    $window = $audits->filter(fn (HealthAudit $audit) => $audit->created_at < $arrivals[$index + 1]
                        && ($index === 0 || $audit->created_at >= $arrivedAt));

                    $inCode = $window->where('direction', HealthAudit::IN)->last()?->new_code;
                    $outCode = $window->where('direction', HealthAudit::OUT)->last()?->new_code;
                }

                $entries[] = [
                    'in' => self::clock($arrivedAt, $timezone),
                    'in_code' => $inCode === null ? null : (int) $inCode,
                    'out' => self::clock($departures[$index], $timezone),
                    'out_code' => $outCode === null ? null : (int) $outCode,
                ];
            }
        }

        return $entries;
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
