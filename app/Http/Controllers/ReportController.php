<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Child;
use App\Services\ClassroomAssignment;
use App\Services\Reports;
use App\Services\UpkEmail;
use App\Services\UpkSheetPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Reports — Phase 8.2 of docs/work.md, as Reports.dc.html draws it.
 *
 * A picker, the filters for the report chosen, then the sheet: a month of
 * children's attendance by room, a stretch of staff hours, the UPK-4 sheet
 * in the school district's layout with its monthly email, and the weekly
 * sign-in sheet this page was before, kept as the fourth report because
 * the register links to it and teachers print it.
 */
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

    public function __construct(private Reports $reports, private UpkEmail $email) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $report = $this->reportOf($request);
        [$year, $month] = $this->monthOf($request);

        $data = [
            'report' => $report,
            'isAdmin' => $user->isAdmin(),
            'month' => sprintf('%04d-%02d', $year, $month),
            'months' => $this->months(),
            'rooms' => ClassroomAssignment::inOrder($user->isAdmin() ? ClassroomAssignment::rooms() : $user->assignedClassrooms()),
            // The sheet is drawn for one room at a time, UPK-4 unless another is asked for.
            'room' => $report === 'upk' ? $this->sheetRoomOf($request) : $this->roomOf($request, $user),
            'mode' => $request->input('mode') === 'marks' ? 'marks' : 'times',
            'range' => $this->rangeOf($request),
            'ranges' => $this->reports->staffRanges(),
        ];

        return view('reports.index', $data + match ($report) {
            'child' => ['ca' => $this->reports->childMonth($user, $year, $month, $data['room'], $data['mode'])],
            'staff' => ['sa' => $this->staffReport($data['range'])],
            'upk' => ['up' => $this->reports->upkSheet($year, $month, $data['room']), 'em' => $this->emailPanel()],
            default => $this->weekSheet($request),
        });
    }

    /** The report on screen, as a file. */
    public function export(Request $request)
    {
        $user = $request->user();

        // The file is asked for by name: a teacher asking for the director's
        // reports is refused, not handed a different one.
        abort_if(in_array($request->input('report'), ['staff', 'upk'], true) && ! $user->isAdmin(), 403);

        $report = $this->reportOf($request);
        [$year, $month] = $this->monthOf($request);

        $built = match ($report) {
            'child' => $this->reports->childMonth($user, $year, $month, $this->roomOf($request, $user)),
            'staff' => $this->staffReport($this->rangeOf($request)),
            'upk' => $this->reports->upkSheet($year, $month, $this->sheetRoomOf($request)),
            default => null,
        };

        abort_if($built === null, 404);

        if ($report === 'upk' && $request->input('format') === 'pdf') {
            return response(app(UpkSheetPdf::class)->render($built), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$built['file'].'.pdf"',
            ]);
        }

        $name = $report === 'upk' ? $built['file'].'.csv' : $built['file'];

        return response(Reports::csv($built['csv']), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /* ---- the monthly email ---- */

    public function saveEmail(Request $request)
    {
        $data = $request->validate([
            'on' => ['required', 'boolean'],
            'to' => ['present', 'array', 'max:20'],
            'to.*' => ['email:rfc'],
            'when' => ['required', Rule::in(['first', 'weekday'])],
            'time' => ['required', Rule::in(array_keys(UpkEmail::TIMES))],
            'csv' => ['required', 'boolean'],
            'hold' => ['required', 'boolean'],
        ], ['to.*.email' => 'One of the addresses does not look like an email address.']);

        $data['to'] = array_values(array_unique(array_map(fn ($a) => strtolower(trim($a)), $data['to'])));
        $this->email->save($data);

        return response()->json($this->emailPanel());
    }

    /** The month that just ended, to the signed-in director only. */
    public function testEmail(Request $request)
    {
        $last = today()->subMonthNoOverflow();
        $this->email->send($last->year, $last->month, [$request->user()->email], $this->email->settings()['csv']);

        return response()->json(['flash' => 'Test sent to '.$request->user()->email.' only.'] + $this->emailPanel());
    }

    /** The month that just ended, to everyone on the list, now. */
    public function sendEmail(Request $request)
    {
        $last = today()->subMonthNoOverflow();
        $n = $this->email->send($last->year, $last->month, $this->email->settings()['to'], $this->email->settings()['csv']);

        if ($n === 0) {
            return response()->json(['message' => 'Add at least one email address first.'], 422);
        }

        $this->email->log($last->format('F').' · '.$n.' '.($n === 1 ? 'person' : 'people').' · sent by hand', 'Delivered', '#15803d');

        return response()->json(['flash' => $last->format('F').' sheet sent to '.$n.' '.($n === 1 ? 'person' : 'people').'.'] + $this->emailPanel());
    }

    private function emailPanel(): array
    {
        $last = today()->subMonthNoOverflow();
        $draft = $this->email->draft($last->year, $last->month);

        return [
            'settings' => $this->email->settings(),
            'times' => UpkEmail::TIMES,
            'next' => $this->email->nextSend()->format('D, M j'),
            'nextMonth' => today()->format('F'),
            'lastMonth' => $last->format('F'),
            'subject' => $draft['subject'],
            'body' => $draft['body'],
            'pdfName' => $draft['pdfName'],
            'csvName' => $draft['csvName'],
            'missing' => $draft['missing'],
            'from' => config('mail.from.address'),
        ];
    }

    /* ---- what was asked for ---- */

    private function reportOf(Request $request): string
    {
        $report = (string) $request->input('report', '');

        if (in_array($report, ['child', 'staff', 'upk', 'week'], true)) {
            // The staff report and the school district's sheet are the director's.
            return in_array($report, ['staff', 'upk'], true) && ! $request->user()->isAdmin() ? 'child' : $report;
        }

        // The addresses the register and the old links use: a week by date.
        return $request->hasAny(['date', 'classroom']) ? 'week' : 'child';
    }

    /** @return array{0: int, 1: int} */
    private function monthOf(Request $request): array
    {
        $month = (string) $request->input('month', today()->format('Y-m'));
        $at = preg_match('/^\d{4}-\d{2}$/', $month) ? Carbon::createFromFormat('Y-m', $month) : today();

        return [(int) $at->year, (int) $at->month];
    }

    /** This month and the five before it, newest first. */
    private function months(): array
    {
        return collect(range(0, 5))->map(fn ($n) => ['key' => today()->subMonthsNoOverflow($n)->format('Y-m'), 'label' => today()->subMonthsNoOverflow($n)->format('F Y')])->all();
    }

    private function roomOf(Request $request, $user): ?string
    {
        $room = trim((string) $request->input('room', ''));

        if ($room === '' || $room === 'All') {
            return $user->isAdmin() ? null : ($user->assignedClassrooms()[0] ?? null);
        }

        return $user->canAccessClassroom($room) ? $room : null;
    }

    /**
     * A day pressed on the attendance sheet (27 Oct 2026, Jordan: "make it
     * dynamic"): ✓ becomes A, A becomes blank (not scheduled), blank becomes
     * ✓ at the child's agreed times. The same write the DSS page makes, so
     * the register, the DSS page and the child report all agree with the
     * sheet. The whole sheet comes back, totals and all.
     */
    public function sheetCell(Request $request, \App\Services\DssAttendance $dss)
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer', 'exists:children,id'],
            'date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'state' => ['required', Rule::in(['pres', 'abs', 'none'])],
        ]);

        $child = Child::findOrFail($data['child_id']);
        $state = ['st' => $data['state']];

        if ($data['state'] === 'pres') {
            // Present at the agreed times; a child with none gets the room's ordinary day.
            $segs = $dss->agreedSegments($child) ?: [[7 * 60 + 30, 16 * 60 + 30]];
            $state['segs'] = array_map(fn ($g) => ['in' => sprintf('%02d:%02d', intdiv($g[0], 60), $g[0] % 60), 'out' => sprintf('%02d:%02d', intdiv($g[1], 60), $g[1] % 60)], $segs);
        }

        try {
            $dss->apply($child, $data['date'], $state, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        [$year, $month] = $this->monthOf($request);
        $sheet = $this->reports->upkSheet($year, $month, $this->sheetRoomOf($request));

        return response()->json([
            'saved' => true,
            'missing' => $sheet['missing'],
            'rows' => array_values(array_map(fn ($r) => ['child_id' => $r['child_id'], 'tot' => $r['tot'], 'cells' => array_map(fn ($c) => $c['v'], $r['cells'])], array_filter($sheet['rows'], fn ($r) => $r['child_id'] !== null))),
            'grand' => array_map(fn ($g) => $g['v'], $sheet['grand']),
        ]);
    }

    /**
     * The room the attendance sheet is drawn for: a real room, else the first
     * on the plan — Infant (Jordan, 27 Oct 2026: "first I view is infant").
     * The monthly email still sends UPK-4; that is UpkEmail's own call.
     */
    private function sheetRoomOf(Request $request): string
    {
        $room = trim((string) $request->input('room', ''));

        return in_array($room, ClassroomAssignment::rooms(), true) ? $room : ClassroomAssignment::rooms()[0];
    }

    private function rangeOf(Request $request): string
    {
        $range = (string) $request->input('range', 'last');

        return array_key_exists($range, $this->reports->staffRanges()) ? $range : 'last';
    }

    private function staffReport(string $range): array
    {
        $r = $this->reports->staffRanges()[$range];

        return $this->reports->staffRange($r['from'], $r['to'], $r['label']);
    }

    /* ---- the weekly sign-in sheet, as it was ---- */

    private function weekSheet(Request $request): array
    {
        $user = $request->user();

        // Empty form fields arrive as null (ConvertEmptyStringsToNull), so normalise
        // before comparing — a null classroom used to filter on "classroom IS NULL".
        $selectedDate = trim((string) $request->input('date')) ?: today()->toDateString();
        validator(['date' => $selectedDate], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        // Room decides which block a child is printed under and which total they
        // land in, so it has to be current before anything is grouped.
        ClassroomAssignment::syncAll();

        $startOfWeek = Carbon::parse($selectedDate)->startOfWeek(Carbon::MONDAY);
        $dates = collect(range(0, 4))->map(fn ($offset) => $startOfWeek->copy()->addDays($offset));

        $classrooms = ClassroomAssignment::inOrder($user->isAdmin()
            ? Child::visibleTo($user)->where('status', 'Active')->distinct()->pluck('classroom')
            : $user->assignedClassrooms());

        $selectedClassroom = trim((string) $request->input('classroom'));

        if (! $user->isAdmin() && $selectedClassroom === '' && $classrooms->isNotEmpty()) {
            $selectedClassroom = $classrooms->first();
        }

        $children = Child::visibleTo($user)
            ->where('status', 'Active')
            ->when($selectedClassroom !== '', fn ($query) => $query->where('classroom', $selectedClassroom))
            ->orderByRoom()
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
            // A date of birth outside every band leaves a child with no room.
            // They still get printed — an unassigned child is one to chase, not
            // one to drop off the sheet.
            'room' => $room === '' ? 'Unassigned' : $room,
            'splitsSessions' => in_array($room, self::SESSION_ROOMS, true),
            'children' => $byRoom[$room],
            'totals' => $this->dailyTotals($byRoom[$room], $dates, $presence),
            'sessionTotals' => $this->sessionTotals($byRoom[$room], $dates, $presence),
            'combined' => $this->combinedTotalFor($room, $rooms, $byRoom, $dates, $presence),
        ]);

        return [
            'blocks' => $blocks,
            'dates' => $dates,
            'presence' => $presence,
            'children' => $children,
            'classrooms' => $classrooms,
            'selectedClassroom' => $selectedClassroom,
            'selectedDate' => $selectedDate,
            'centerTotals' => $this->dailyTotals($children, $dates, $presence),
        ];
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
        foreach (self::COMBINED_TOTALS as $combined) {
            if ($combined['after'] !== $room) {
                continue;
            }

            $members = collect($combined['rooms'])->intersect($rooms);

            if ($members->count() < 2) {
                return null;
            }

            $children = $members->flatMap(fn ($member) => $byRoom[$member]);

            return [
                'label' => $combined['label'],
                'totals' => $this->dailyTotals($children, $dates, $presence),
            ];
        }

        return null;
    }
}
