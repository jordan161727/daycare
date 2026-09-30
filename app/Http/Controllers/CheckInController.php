<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceReturn;
use App\Models\Child;
use App\Models\HealthAudit;
use App\Services\ClassroomAssignment;
use App\Services\HealthScreening;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        /*
         * The day the roster is about.
         *
         * Today, unless a director has asked for a day already gone — the
         * way the register lets a week be chosen — to put its symptom codes
         * right. Anybody else is shown today whatever the address says: a
         * past day here is for correcting, and correcting is the director's.
         */
        $requested = (string) $request->input('date');
        $date = $user->isAdmin() && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) && strtotime($requested) !== false
            ? Carbon::parse($requested)->toDateString()
            : $today;

        // Never a day that has not happened: there is nothing on it to edit,
        // and a roster of a future day would invite arrivals that are guesses.
        if ($date > $today) {
            $date = $today;
        }

        $anchor = Carbon::parse($date);

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

        // Built around the day being looked at, so its rows are always here.
        [$start, $end] = $span === self::WEEK
            ? [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)]
            : [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()];

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
            ->with('returns')
            ->get()
            ->groupBy([
                fn (Attendance $row) => $row->child_id,
                fn (Attendance $row) => $row->attendance_date->toDateString(),
            ]);

        $timezone = config('app.timezone');

        $rows = $children->map(function (Child $child) use ($records, $days, $timezone) {
            $byDay = [];
            // The same days, a session at a time: what the dialog edits. A
            // School Age child's morning and afternoon are two rows on the
            // register, and the door clocks each of them in and out.
            $bySession = [];

            // The sheet's own clock: "7:42a", "5:25p". Short enough for a
            // column a month wide, and unambiguous, which twenty-four hour
            // was but nobody at a door reads.
            $slot = fn (Attendance $row) => [
                'id' => $row->id,
                'session' => $row->session ?? 'FULL',
                'in' => Child::timeShort($row->signed_in_at?->timezone($timezone)),
                'in_code' => $row->health_in_code,
                'in_note' => $row->health_in_note,
                'out' => Child::timeShort($row->signed_out_at?->timezone($timezone)),
                'out_code' => $row->health_out_code,
                'out_note' => $row->health_out_note,
                // Every time the child left and came back on this session,
                // oldest first: [[left, back], ...]. With the row's own in
                // and out these are the whole day's clock, and nothing a
                // second arrival overwrote.
                'trips' => self::trips($row, $timezone),
            ];

            foreach ($days as $day) {
                $iso = $day->toDateString();
                $onDay = $records[$child->id][$iso] ?? collect();

                if ($onDay->isEmpty()) {
                    continue;
                }

                foreach ($onDay->sortBy('signed_in_at') as $row) {
                    $bySession[$iso][$row->session ?? 'FULL'] = $slot($row);
                }

                // First in and last out: one pair of lines per day, as on the
                // paper sheet, even where a room books a morning and an
                // afternoon separately. No "out" at all while any session is
                // still open: a child clocked out of the morning and into the
                // afternoon is on the premises, and the roster says so.
                $first = $onDay->sortBy('signed_in_at')->first();
                $open = $onDay->contains(fn (Attendance $row) => $row->signed_out_at === null);
                $last = $open ? null : $onDay->sortBy('signed_out_at')->last();

                $byDay[$iso] = [
                    'id' => $first->id,
                    'in' => Child::timeShort($first->signed_in_at?->timezone($timezone)),
                    'in_code' => $first->health_in_code,
                    'in_note' => $first->health_in_note,
                    'out' => Child::timeShort($last?->signed_out_at?->timezone($timezone)),
                    'out_code' => $last?->health_out_code,
                    'out_note' => $last?->health_out_note,
                    // The trips out and back already on the day, in order:
                    // [[left, back], ...]. First in, last out, and these between.
                    'trips' => $onDay->flatMap(fn (Attendance $row) => self::trips($row, $timezone))->values()->all(),
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
                // has one pair of lines and books the child's first session;
                // the dialog offers every session, each clocked on its own.
                'session' => $child->sessions()[0] ?? 'FULL',
                'sessions' => $child->sessions() ?: ['FULL'],
                'byDay' => $byDay,
                'bySession' => $bySession,
                // For the roster cards: the name the way a card says it, and
                // the child's own avatar — their photograph, or the drawn face
                // the rest of the app gives them — rendered once here so the
                // cards are markup the browser only has to place.
                'first_name' => $child->first_name,
                'display' => trim($child->first_name.' '.$child->last_name),
                'avatar' => preg_replace('/>\s+</', '><', trim(view('components.child-avatar', [
                    'child' => $child,
                    'size' => 'h-24 w-24',
                ])->render())),
            ];
        });

        $roomChips = $rows->groupBy('room')->map(fn ($group, $room) => [
            'room' => $room,
            'animal' => ClassroomAssignment::animal($room),
            'total' => $group->count(),
            'in' => $group->filter(fn (array $row) => ($row['byDay'][today()->toDateString()]['in'] ?? null) !== null)->count(),
        ]);

        // Youngest room first, the way the centre says them, with a child
        // nobody has placed at the end — see ClassroomAssignment::inOrder.
        $roomChips = ClassroomAssignment::inOrder($roomChips->keys())
            ->map(fn (string $room) => $roomChips[$room])
            ->values();

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
            // The day the roster shows. Today unless a director chose one.
            'date' => $date,
            // The roster is what the screen opens on: one card a child, today
            // only, worked from a door. The four-line sheet is a tab away for
            // the glance sideways — did she come yesterday, is he in tomorrow.
            'view' => $request->input('view') === 'sheet' ? 'sheet' : 'today',
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
     * A child clocked out who has come back.
     *
     * The client's rule: every clock-in and clock-out of the day is kept.
     * So the departure is not forgotten to let the child in again — it
     * becomes the left-at of a return, now is its returned-at, and the row's
     * own departure is cleared until the next clock-out sets it. The row
     * still reads first in, last out; the returns hold everything between.
     * Same record the register's own "back in" writes.
     *
     * A code is required, as on the first arrival: somebody is standing in
     * front of the child again, and a child back from the dentist at one
     * can be a different child from the one who arrived at eight.
     */
    public function back(Request $request, Attendance $attendance)
    {
        $data = $request->validate([
            'health_code' => ['required', 'integer', 'between:0,255'],
            'health_note' => ['nullable', 'string', 'max:120'],
        ]);

        $this->guard($request, $attendance);

        abort_if(
            $attendance->signed_out_at === null,
            422,
            $attendance->child->displayName().' has not been clocked out, so there is nothing to come back from.',
        );

        // Checked before anything is written, so a refused code does not
        // leave a return behind it.
        $this->screening->validate($data['health_code'], $data['health_note'] ?? null);

        DB::transaction(function () use ($attendance, $request) {
            $attendance->returns()->create([
                'left_at' => $attendance->signed_out_at,
                'returned_at' => now(),
                'performed_by' => $request->user()?->id,
            ]);

            // The leaving check went with the departure it was taken at; a
            // new one is taken at the next. The audit keeps the old one.
            $attendance->forceFill([
                'signed_out_at' => null,
                'health_out_code' => null,
                'health_out_note' => null,
            ])->save();
        });

        $this->screening->record(
            $attendance,
            HealthAudit::IN,
            $data['health_code'],
            $data['health_note'] ?? null,
            $request->user(),
        );

        return $this->row($attendance);
    }

    /** The row's trips out and back as the screen shows them: [[left, back], ...]. */
    private static function trips(Attendance $attendance, string $timezone): array
    {
        return $attendance->returns
            ->sortBy('returned_at')
            ->map(fn (AttendanceReturn $trip) => [
                Child::timeShort($trip->left_at->timezone($timezone)),
                Child::timeShort($trip->returned_at->timezone($timezone)),
            ])
            ->values()
            ->all();
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
        $attendance->refresh()->load('returns');

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
            'trips' => self::trips($attendance, $timezone),
        ]);
    }
}
