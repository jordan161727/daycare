<?php

namespace App\Http\Controllers;

use App\Enums\CommentTag;
use App\Http\Resources\AttendanceCommentResource;
use App\Models\Attendance;
use App\Models\AttendanceComment;
use App\Models\DoorAttendance;
use App\Models\AttendanceReturn;
use App\Models\Child;
use App\Models\HealthAudit;
use App\Services\ClassroomAssignment;
use App\Services\CommentDays;
use App\Services\HealthScreening;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The door screen: today's children, one row each, checked in and out.
 *
 * On its own record. The door and the register (AttendanceController) used
 * to share one table; the centre asked for them kept apart, so everything
 * here reads and writes DoorAttendance — door_attendances and its own
 * returns, health audits and comments — and nothing crosses to the register.
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

    /**
     * A note typed along with a clock-in or clock-out — "has a cough today",
     * "nappies in the red bag" — filed as a comment on the row the moment
     * the row is written, so it reads the same as one added from the
     * dialog. Optional: a clock without words is still a clock.
     */
    private static function commentRules(): array
    {
        return [
            'comment_body' => ['nullable', 'string', 'max:1000'],
            'comment_tag' => ['nullable', Rule::enum(CommentTag::class)],
        ];
    }

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
         * Today, unless a day already gone was asked for. Anybody at the
         * door may look back (20 Oct 2026) — Friday's comments, read on
         * Monday — and a past day opens read-only for everyone: no clock,
         * no live mode. Putting its symptom codes right is still the
         * director's, behind the Edit switch, and the server checks that
         * again on every write.
         */
        $requested = (string) $request->input('date');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) && strtotime($requested) !== false
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

        /*
         * The whole floor, whoever is at the door. A teacher used to see
         * their own room alone here, which fitted the register but not a
         * door: the Infant teacher covering the Toddler room at seven has
         * to be able to record the Toddler arriving. Their own room is
         * where the screen opens — see $defaultRoom — not all it shows.
         * The register (AttendanceController) keeps the per-room scope.
         */
        $children = Child::query()
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

        $records = DoorAttendance::whereBetween('attendance_date', [
                $days->first()->toDateString(),
                $days->last()->toDateString(),
            ])
            ->whereIn('child_id', $children->pluck('id'))
            // The notes ride with the row, so the dialog opens on them with
            // no second request and the card can wear the count.
            ->with(['returns', 'comments.author', 'healthAudits.user'])
            ->get()
            ->groupBy([
                fn (DoorAttendance $row) => $row->child_id,
                fn (DoorAttendance $row) => $row->attendance_date->toDateString(),
            ]);

        $timezone = config('app.timezone');

        // The day each child's latest earlier comment is on, so the dialog
        // can say "Last comment: Fri, Oct 16 · View" without a request.
        $lastCommentOn = CommentDays::lastCommentDays($children->pluck('id'), $date);

        $rows = $children->map(function (Child $child) use ($records, $days, $timezone, $lastCommentOn) {
            $byDay = [];
            // The same days, a session at a time: what the dialog edits. A
            // School Age child's morning and afternoon are two rows on the
            // register, and the door clocks each of them in and out.
            $bySession = [];

            // The sheet's own clock: "7:42a", "5:25p". Short enough for a
            // column a month wide, and unambiguous, which twenty-four hour
            // was but nobody at a door reads.
            $slot = fn (DoorAttendance $row) => [
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
                // The notes on the row, newest first, shaped as the API shapes them.
                'comments' => AttendanceCommentResource::collection($row->comments)->resolve(),
                'comments_count' => $row->comments->count(),
                // Who took the arrival check, and the arrival as the chip says it.
                'in_by' => self::checkedInBy($row),
                'in_at_label' => $row->signed_in_at?->timezone($timezone)->format('g:i A'),
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
                $open = $onDay->contains(fn (DoorAttendance $row) => $row->signed_out_at === null);
                $last = $open ? null : $onDay->sortBy('signed_out_at')->last();

                $byDay[$iso] = [
                    'id' => $first->id,
                    'in' => Child::timeShort($first->signed_in_at?->timezone($timezone)),
                    'in_at_label' => $first->signed_in_at?->timezone($timezone)->format('g:i A'),
                    'in_code' => $first->health_in_code,
                    'in_note' => $first->health_in_note,
                    'out' => Child::timeShort($last?->signed_out_at?->timezone($timezone)),
                    'out_code' => $last?->health_out_code,
                    'out_note' => $last?->health_out_note,
                    // The trips out and back already on the day, in order:
                    // [[left, back], ...]. First in, last out, and these between.
                    'trips' => $onDay->flatMap(fn (DoorAttendance $row) => self::trips($row, $timezone))->values()->all(),
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
                // The day of the child's latest comment before this one, or null.
                'last_comment_on' => $lastCommentOn[$child->id] ?? null,
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
                // The alerts on the child's record — allergy, medical, court
                // order — shown when the child is selected (Phase 0's approved
                // behaviour, drawn from the Phase 2 record). Read-only here.
                'alerts' => array_map(fn (array $a) => ['label' => $a['label'], 'text' => $a['text'], 'severity' => $a['severity'] ?? null], $child->alertList()),
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

        // The room the screen opens on: a teacher's own, every time — the
        // Infant teacher lands on Infant — with the other rooms a chip away.
        // The director opens on everyone. Either can be sent to a room by the
        // address: /check-in?infant, /check-in?upk4, /check-in?school-age —
        // a link the office can put on a tablet's home screen, one a room.
        $defaultRoom = ClassroomAssignment::fromQuery($request) ?? ($user->isAdmin() ? '' : ($user->assignedClassrooms()[0] ?? ''));

        return view('attendance.check-in', [
            'rows' => $rows,
            'defaultRoom' => $defaultRoom,
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
            // The day stepper's neighbours: the school day either side,
            // weekends skipped, and never past today.
            'prevDay' => self::schoolDayFrom($anchor, -1)->toDateString(),
            'nextDay' => $date < $today ? min(self::schoolDayFrom($anchor, 1)->toDateString(), $today) : null,
            // The day's comments, newest first, for the panel beside the cards
            // and the link to the log.
            'dayComments' => $this->dayComments($rows, $date),
        ]);
    }

    /** The next weekday from a day, in either direction. */
    private static function schoolDayFrom(Carbon $day, int $step): Carbon
    {
        $next = $day->copy();

        do {
            $next->addDays($step);
        } while ($next->isWeekend());

        return $next;
    }

    /**
     * Every comment on the day across every child, newest first, with the
     * child's name and room on each: the "Latest comments" list beside the
     * cards reads these, and the count says how many there were.
     */
    private function dayComments($rows, string $date): array
    {
        return $rows
            ->flatMap(fn (array $row) => collect($row['bySession'][$date] ?? [])
                ->flatMap(fn (array $slot) => $slot['comments'] ?? [])
                ->map(fn (array $comment) => $comment + [
                    'child_id' => $row['id'],
                    'child' => $row['display'],
                    'room' => $row['room'],
                ]))
            ->sortByDesc('at')
            ->values()
            ->all();
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
            ...self::commentRules(),
        ]);

        // Any child on the roll: the door shows the whole floor, so it
        // clocks the whole floor — see index().
        $child = Child::findOrFail($data['child_id']);
        $today = today()->toDateString();

        abort_unless($child->isEnrolledOn($today), 422, $child->displayName().' is not on the roll today.');

        // Checked before the row is written, so a refused code does not leave
        // an arrival behind it.
        $this->screening->validate($data['health_code'], $data['health_note'] ?? null);

        $attendance = DoorAttendance::firstOrCreate(
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

        $this->noteComment($request, $attendance, $data);

        return $this->row($attendance);
    }

    /**
     * The arrival check, set or corrected on the day it was taken.
     *
     * Separate from the arrival itself because a child can be checked in and
     * the code changed a minute later — a second look, or the first one having
     * been a mis-tap. The change is audited like any other.
     */
    public function in(Request $request, DoorAttendance $attendance)
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
    public function out(Request $request, DoorAttendance $attendance)
    {
        $data = $request->validate([
            'health_code' => ['required', 'integer', 'between:0,255'],
            'health_note' => ['nullable', 'string', 'max:120'],
            ...self::commentRules(),
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

        $this->noteComment($request, $attendance, $data);

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
    public function back(Request $request, DoorAttendance $attendance)
    {
        $data = $request->validate([
            'health_code' => ['required', 'integer', 'between:0,255'],
            'health_note' => ['nullable', 'string', 'max:120'],
            ...self::commentRules(),
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

        $this->noteComment($request, $attendance, $data);

        return $this->row($attendance);
    }

    /**
     * File the comment typed with a clock-in or clock-out, if there was one.
     *
     * The same shape as one added from the dialog — the row's, the signed-in
     * user's, tagged — so the two are indistinguishable once written. Only a
     * body makes a comment; a tag with nothing to say is nothing to file.
     */
    private function noteComment(Request $request, DoorAttendance $attendance, array $data): void
    {
        $body = trim((string) ($data['comment_body'] ?? ''));

        if ($body === '') {
            return;
        }

        // One note a day: a clock-out typed over a clock-in's note changes
        // it, the same as pressing the note in the dialog would.
        if ($existing = $attendance->comments()->first()) {
            Gate::authorize('update', $existing);
            $existing->fill(['body' => $body]);
            if ($existing->isDirty()) {
                $existing->save();
            }

            return;
        }

        Gate::authorize('create', [AttendanceComment::class, $attendance]);

        $attendance->comments()->create([
            'child_id' => $attendance->child_id,
            'user_id' => $request->user()->id,
            'tag' => $data['comment_tag'] ?? CommentTag::Other->value,
            'body' => $body,
        ]);
    }

    /** The row's trips out and back as the screen shows them: [[left, back], ...]. */
    private static function trips(DoorAttendance $attendance, string $timezone): array
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
    private function guard(Request $request, DoorAttendance $attendance): void
    {
        abort_unless(Child::whereKey($attendance->child_id)->exists(), 404);

        abort_unless(
            $attendance->attendance_date->toDateString() === today()->toDateString(),
            403,
            'This screen records today. Earlier days are corrected on the attendance sheet.',
        );
    }
    /** One line of the screen, as it should now read. */
    /**
     * An arrival typed in Edit — a day already gone, or an hour by hand — on
     * the door's own record. The door used to send these through the
     * register; now it keeps them, like everything else it writes.
     */
    public function signInDay(Request $request)
    {
        $validated = $request->validate([
            'child_id' => 'required|exists:children,id',
            'attendance_date' => ['required', 'date_format:Y-m-d', function ($attribute, $value, $fail) use ($request) {
                if ($value > now()->toDateString()) {
                    $fail(Carbon::parse($value)->format('l, M j').' has not happened yet — '.now()->format('l, M j').' is the last day that can be clocked in.');

                    return;
                }
                $child = Child::find($request->input('child_id'));
                if ($child && ! $child->isEnrolledOn($value)) {
                    $fail($child->displayName().' was not on the roll on '.Carbon::parse($value)->format('l, M j').'.');
                }
            }],
            'session' => ['nullable', 'in:AM,PM,FULL', function ($attribute, $value, $fail) use ($request) {
                $child = Child::find($request->input('child_id'));
                if ($child && ! in_array($value ?? 'FULL', $child->sessions(), true)) {
                    $fail(count($child->sessions()) > 1 ? $child->classroom.' is clocked in by half day — choose AM or PM.' : $child->classroom.' is clocked in once for the whole day, not by half.');
                }
            }],
            'signed_in_time' => ['nullable', 'date_format:H:i'],
            'health_code' => ['nullable', 'integer', 'between:0,255'],
            'health_note' => ['nullable', 'string', 'max:120'],
        ]);
        $this->guardDay($request, $validated['attendance_date']);

        $child = Child::findOrFail($validated['child_id']);
        $session = $validated['session'] ?? 'FULL';
        $timezone = config('app.timezone');
        $signedInAt = match (true) {
            filled($validated['signed_in_time'] ?? null) => Carbon::parse($validated['attendance_date'].' '.$validated['signed_in_time'], $timezone),
            $validated['attendance_date'] === now()->toDateString() => now(),
            default => Carbon::parse($validated['attendance_date'].' '.Child::timeInputValue($child->drop_off_time ?: Child::DAY_OPENS_AT), $timezone),
        };

        $attendance = DoorAttendance::firstOrCreate(
            ['child_id' => $child->id, 'attendance_date' => $validated['attendance_date'], 'session' => $session],
            ['signed_in_at' => $signedInAt],
        );

        if ($attendance->wasRecentlyCreated && $request->filled('health_code')) {
            $this->screening->record($attendance, HealthAudit::IN, (int) $validated['health_code'], $validated['health_note'] ?? null, $request->user());
        }

        return response()->json([
            'success' => true,
            'created' => $attendance->wasRecentlyCreated,
            'attendance_id' => $attendance->id,
            'session' => $attendance->session,
            'time' => Child::timeShort($attendance->signed_in_at->timezone($timezone)),
            'health_in_code' => $attendance->health_in_code,
            'health_in_note' => $attendance->health_in_note,
        ]);
    }

    /** The exact hours, typed in Edit: one or both of the arrival and the departure. */
    public function retime(Request $request)
    {
        $validated = $request->validate([
            'child_id' => 'required|exists:children,id',
            'attendance_date' => ['required', 'date_format:Y-m-d', function ($attribute, $value, $fail) {
                if ($value > now()->toDateString()) {
                    $fail(Carbon::parse($value)->format('l, M j').' has not happened yet, so there is no arrival on it to move.');
                }
            }],
            'session' => 'nullable|in:AM,PM,FULL',
            'signed_in_time' => ['nullable', 'required_without:signed_out_time', 'date_format:H:i'],
            'signed_out_time' => ['nullable', 'required_without:signed_in_time', 'date_format:H:i'],
        ]);
        $this->guardDay($request, $validated['attendance_date']);

        $child = Child::findOrFail($validated['child_id']);
        $attendance = DoorAttendance::where('child_id', $child->id)
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->where('session', $validated['session'] ?? 'FULL')
            ->first();

        if (! $attendance) {
            return response()->json(['message' => $child->displayName().' has no arrival recorded on '.Carbon::parse($validated['attendance_date'])->format('l, M j').' to move.'], 422);
        }

        $timezone = config('app.timezone');
        if (filled($validated['signed_in_time'] ?? null)) {
            $attendance->signed_in_at = Carbon::parse($validated['attendance_date'].' '.$validated['signed_in_time'], $timezone);
        }
        if (filled($validated['signed_out_time'] ?? null)) {
            $attendance->signed_out_at = Carbon::parse($validated['attendance_date'].' '.$validated['signed_out_time'], $timezone);
        }
        if ($attendance->signed_out_at !== null && $attendance->signed_out_at->lt($attendance->signed_in_at->copy()->startOfMinute())) {
            return response()->json(['message' => 'The leaving time has to be after the arrival — '.Child::timeShort($attendance->signed_in_at->timezone($timezone)).'.'], 422);
        }
        $attendance->save();

        return response()->json([
            'success' => true,
            'attendance_id' => $attendance->id,
            'time' => Child::timeShort($attendance->signed_in_at->timezone($timezone)),
            'out_time' => Child::timeShort($attendance->signed_out_at?->timezone($timezone)),
        ]);
    }

    /** Not attending: the arrival comes off the door's record, with everything hung on it. */
    public function remove(Request $request)
    {
        $validated = $request->validate([
            'child_id' => 'required|exists:children,id',
            'attendance_date' => ['required', 'date_format:Y-m-d', function ($attribute, $value, $fail) {
                if ($value > now()->toDateString()) {
                    $fail(Carbon::parse($value)->format('l, M j').' has not happened yet, so there is nothing on it to take off.');
                }
            }],
            'session' => 'nullable|in:AM,PM,FULL',
        ]);
        $this->guardDay($request, $validated['attendance_date']);

        $attendance = DoorAttendance::where('child_id', $validated['child_id'])
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->where('session', $validated['session'] ?? 'FULL')
            ->first();
        $attendance?->delete();

        return response()->json(['success' => true, 'removed' => $attendance ? 1 : 0]);
    }

    /** A check set or corrected in Edit, on the door's row. */
    public function health(Request $request, DoorAttendance $attendance)
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in([HealthAudit::IN, HealthAudit::OUT])],
            'code' => ['present', 'nullable', 'integer', 'between:0,255'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);
        $this->guardDay($request, $attendance->attendance_date->toDateString());

        $attendance = $this->screening->record($attendance, $data['direction'], $data['code'], $data['note'] ?? null, $request->user());

        return response()->json([
            'success' => true,
            'direction' => $data['direction'],
            'code' => $attendance->healthCode($data['direction']),
            'note' => $attendance->healthNote($data['direction']),
            'sick' => $attendance->isSick(),
        ]);
    }

    /** Today is everybody's; a day gone by is the director's. */
    private function guardDay(Request $request, string $date): void
    {
        abort_unless($this->screening->mayRecord($request->user(), $date), 403, 'Only an administrator can change a day that has already gone.');
    }

    private function row(DoorAttendance $attendance)
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
            'comments' => AttendanceCommentResource::collection($attendance->comments()->with('author')->get())->resolve(),
            'comments_count' => $attendance->comments()->count(),
            'in_by' => self::checkedInBy($attendance->load('healthAudits.user')),
            'in_at_label' => $attendance->signed_in_at?->timezone($timezone)->format('g:i A'),
        ]);
    }

    /** The staff member who took the arrival check — the latest IN audit's author. */
    private static function checkedInBy(DoorAttendance $attendance): ?string
    {
        return $attendance->healthAudits
            ->where('direction', \App\Models\HealthAudit::IN)
            ->sortByDesc('id')
            ->first()?->user?->name;
    }
}
