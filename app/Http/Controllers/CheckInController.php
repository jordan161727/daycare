<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\HealthAudit;
use App\Services\ClassroomAssignment;
use App\Services\HealthScreening;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The door screen: today's children, one row each, checked in and out.
 *
 * Deliberately not the week sheet. That one is a register — five days wide,
 * read across and scrolled through, and it exists to answer "who was here this
 * week". This is worked standing up, one child at a time, while a parent waits
 * and somebody's coat is half off. It shows today and nothing else, because a
 * screen that can also show last Tuesday is a screen somebody eventually
 * checks a child in on last Tuesday.
 *
 * The health check is the reason it is separate. On the week sheet a symptom
 * code is a small mark in a dense grid; here it is the second half of the act
 * of checking a child in, and the screen will not complete one without it.
 */
class CheckInController extends Controller
{
    /** How much of the record the grid draws at once. */
    public const WEEK = 'week';

    public const MONTH = 'month';

    public function __construct(private HealthScreening $screening) {}

    /**
     * The week as a grid, with today's column the one that takes a press.
     *
     * The other days are there for context and nothing else: a person at the
     * door glances left to see whether this child came yesterday, and that is
     * worth the columns. They are not editable here — a check typed into a day
     * already gone is one nobody performed, and corrections belong on the
     * register where they are written to attendance_amendments.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $today = today()->toDateString();

        // Room decides which block a child is filtered into, so it has to be
        // current before anything is grouped.
        ClassroomAssignment::syncAll();

        /*
         * This week or this month, as asked for.
         *
         * The week is what it opens on. This screen is worked standing up at
         * a door, and the columns either side of today are the ones somebody
         * glances at — did she come yesterday, is he in tomorrow. A month is
         * thirty-one columns to scroll through before the useful ones, and it
         * is one press away when the question really is about the month.
         *
         * Either way only today's column takes a press; the rest is what
         * happened.
         */
        $span = $request->input('span') === self::MONTH ? self::MONTH : self::WEEK;

        [$start, $end] = $span === self::WEEK
            ? [today()->startOfWeek(Carbon::MONDAY), today()->endOfWeek(Carbon::SUNDAY)]
            : [today()->startOfMonth(), today()->endOfMonth()];

        $days = collect(range(0, $start->diffInDays($end)))
            ->map(fn (int $offset) => $start->copy()->addDays($offset));

        $children = Child::visibleTo($user)
            ->where('status', 'Active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            /*
             * Everyone on the roll, drawn the same.
             *
             * Nothing here keys off a child's registered days. The door takes
             * whoever comes through it, and a child arriving on a day they
             * were not booked for is precisely the arrival somebody needs to
             * be able to record — a screen that hid them would send that
             * morning unrecorded.
             *
             * Children who have not started yet, or who have left, are still
             * left out: those are not schedule, they are whether the centre
             * has this child at all.
             */
            ->filter(fn (Child $child) => $child->isEnrolledOn($today))
            ->values();

        $records = Attendance::whereBetween('attendance_date', [
                $days->first()->toDateString(),
                $days->last()->toDateString(),
            ])
            ->whereIn('child_id', $children->pluck('id'))
            ->get()
            ->groupBy([
                fn (Attendance $row) => $row->child_id,
                fn (Attendance $row) => $row->attendance_date->toDateString(),
            ]);

        $timezone = config('app.timezone');

        $rows = $children->map(function (Child $child) use ($records, $days, $timezone) {
            $byDay = [];

            foreach ($days as $day) {
                $iso = $day->toDateString();
                $onDay = $records[$child->id][$iso] ?? collect();

                if ($onDay->isEmpty()) {
                    continue;
                }

                // First in and last out: one pair of lines per day, as on the
                // paper sheet, even where a room books a morning and an
                // afternoon separately.
                $first = $onDay->sortBy('signed_in_at')->first();
                $last = $onDay->filter(fn (Attendance $row) => $row->signed_out_at !== null)
                    ->sortBy('signed_out_at')->last();

                $byDay[$iso] = [
                    'id' => $first->id,
                    // The sheet's own clock: "7:42a", "5:25p". Short enough for
                    // a column a month wide, and unambiguous, which twenty-four
                    // hour was but nobody at a door reads.
                    'in' => Child::timeShort($first->signed_in_at?->timezone($timezone)),
                    'in_code' => $first->health_in_code,
                    'in_note' => $first->health_in_note,
                    'out' => Child::timeShort($last?->signed_out_at?->timezone($timezone)),
                    'out_code' => $last?->health_out_code,
                    'out_note' => $last?->health_out_note,
                ];
            }

            // The days the standing arrangement puts this child here. Read
            // off the child's registered pattern rather than the week's
            // ticks: this says who is expected, not who was planned for by
            // somebody filling in a rota.
            $booked = [];

            foreach ($days as $day) {
                $booked[$day->toDateString()] = $child->attendsOn($day);
            }

            return [
                'id' => $child->id,
                'booked' => $booked,
                'name' => $child->last_name.', '.$child->first_name,
                'lan' => $child->lan,
                'room' => $child->classroom ?: 'Unassigned',
                'animal' => ClassroomAssignment::animal($child->classroom),
                'hours' => $child->scheduleLabel(),
                // A School Age child books a morning and an afternoon. The grid
                // has one pair of lines, so a check-in from here books the
                // child's first session and the rest is done on the register.
                'session' => $child->sessions()[0] ?? 'FULL',
                'byDay' => $byDay,
            ];
        });

        $roomChips = $rows->groupBy('room')->map(fn ($group, $room) => [
            'room' => $room,
            'animal' => ClassroomAssignment::animal($room),
            'total' => $group->count(),
            'in' => $group->filter(fn (array $row) => ($row['byDay'][today()->toDateString()]['in'] ?? null) !== null)->count(),
        ])->sortBy('room')->values();

        $todayRows = $rows->map(fn (array $row) => $row['byDay'][$today] ?? null);

        $stats = [
            'enrolled' => $rows->count(),
            'in' => $todayRows->filter(fn (?array $day) => $day !== null && $day['in'] !== null)->count(),
            'sick' => $todayRows->filter(fn (?array $day) => $day !== null
                && ((($day['in_code'] ?? null) !== null && $day['in_code'] !== 0)
                    || (($day['out_code'] ?? null) !== null && $day['out_code'] !== 0)))->count(),
        ];

        $stats['not_in'] = $stats['enrolled'] - $stats['in'];

        // Booked today and not here yet. "Not in" counts the whole roll,
        // most of whom were never coming; this is the number somebody is
        // actually chasing at half past eight.
        $stats['awaited'] = $rows->filter(fn (array $row) => ($row['booked'][$today] ?? false)
            && ($row['byDay'][$today]['in'] ?? null) === null)->count();

        return view('attendance.check-in', [
            'rows' => $rows,
            'days' => $days,
            'today' => $today,
            'codes' => $this->screening->codes(),
            'roomChips' => $roomChips,
            'stats' => $stats,
            // Who may put a day already gone right. Checked again on the
            // way in — this only decides whether the switch is offered.
            'canAmend' => $user->isAdmin(),
            'span' => $span,
        ]);
    }
    /**
     * A child arrives.
     *
     * The code is required here, unlike on the week sheet and unlike the door
     * kiosk: this screen is worked by somebody standing in front of the child,
     * which is the only circumstance in which a health check means anything.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'child_id' => ['required', 'exists:children,id'],
            'session' => ['required', Rule::in(['AM', 'PM', 'FULL'])],
            'health_code' => ['required', 'integer', 'between:0,255'],
            'health_note' => ['nullable', 'string', 'max:120'],
        ]);

        $child = Child::visibleTo($request->user())->findOrFail($data['child_id']);
        $today = today()->toDateString();

        abort_unless($child->isEnrolledOn($today), 422, $child->displayName().' is not on the roll today.');

        // Checked before the row is written, so a refused code does not leave
        // an arrival behind it.
        $this->screening->validate($data['health_code'], $data['health_note'] ?? null);

        $attendance = Attendance::firstOrCreate(
            ['child_id' => $child->id, 'attendance_date' => $today, 'session' => $data['session']],
            ['signed_in_at' => now()],
        );

        $this->screening->record(
            $attendance,
            HealthAudit::IN,
            $data['health_code'],
            $data['health_note'] ?? null,
            $request->user(),
        );

        return $this->row($attendance);
    }

    /**
     * The arrival check, set or corrected on the day it was taken.
     *
     * Separate from the arrival itself because a child can be checked in and
     * the code changed a minute later — a second look, or the first one having
     * been a mis-tap. The change is audited like any other.
     */
    public function in(Request $request, Attendance $attendance)
    {
        $data = $request->validate([
            'health_code' => ['required', 'integer', 'between:0,255'],
            'health_note' => ['nullable', 'string', 'max:120'],
        ]);

        $this->guard($request, $attendance);

        $this->screening->record(
            $attendance,
            HealthAudit::IN,
            $data['health_code'],
            $data['health_note'] ?? null,
            $request->user(),
        );

        return $this->row($attendance);
    }
    /**
     * A child is collected.
     *
     * The departure and its check go together for the same reason the arrival
     * and its do: the person handing the child over is the one who can say how
     * they were.
     */
    public function out(Request $request, Attendance $attendance)
    {
        $data = $request->validate([
            'health_code' => ['required', 'integer', 'between:0,255'],
            'health_note' => ['nullable', 'string', 'max:120'],
        ]);

        $this->guard($request, $attendance);

        if ($attendance->signed_out_at === null) {
            $attendance->forceFill(['signed_out_at' => now()])->save();
        }

        $this->screening->record(
            $attendance,
            HealthAudit::OUT,
            $data['health_code'],
            $data['health_note'] ?? null,
            $request->user(),
        );

        return $this->row($attendance);
    }

    /**
     * Whose day this is, and whether it is today.
     *
     * Today only, on this screen. A check typed into a day already gone is one
     * nobody performed, and corrections belong on the register where they are
     * written to attendance_amendments.
     */
    private function guard(Request $request, Attendance $attendance): void
    {
        abort_unless(
            Child::visibleTo($request->user())->whereKey($attendance->child_id)->exists(),
            404,
        );

        abort_unless(
            $attendance->attendance_date->toDateString() === today()->toDateString(),
            403,
            'This screen records today. Earlier days are corrected on the attendance sheet.',
        );
    }
    /** One line of the screen, as it should now read. */
    private function row(Attendance $attendance)
    {
        $timezone = config('app.timezone');
        $attendance->refresh();

        return response()->json([
            'success' => true,
            'attendance_id' => $attendance->id,
            'session' => $attendance->session,
            'in_at' => Child::timeShort($attendance->signed_in_at?->timezone($timezone)),
            'out_at' => Child::timeShort($attendance->signed_out_at?->timezone($timezone)),
            'health_in' => $attendance->health_in_code,
            'health_in_note' => $attendance->health_in_note,
            'health_out' => $attendance->health_out_code,
            'health_out_note' => $attendance->health_out_note,
        ]);
    }
}
