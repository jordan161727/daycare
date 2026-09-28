<?php

namespace App\Http\Controllers;

use App\Models\TimePunch;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Models\StaffShift;
use App\Services\TimeClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The supervisor's side of the clock: one day, every punch on it, and what was
 * done to them.
 *
 * A correction here is never an edit. Putting a punch right means voiding the
 * old one and writing a new one that points back at it, both stamped with who
 * and why — so the day always shows what the employee originally pressed as
 * well as what it was changed to. An audit trail that can be made to agree
 * with the answer is not one.
 *
 * The reason is required rather than encouraged, because the question this
 * screen exists to answer is asked months later by somebody who was not there,
 * and "corrected by the director" without a why answers none of it.
 */
class TimePunchController extends Controller
{
    public function __construct(private TimeClock $clock) {}

    /** One person, one day: the punches, the arithmetic, and the history. */
    /**
     * The correction screen for one person on one day, found by date alone.
     *
     * The week grid knows who and when, and nothing about pay periods — it is
     * read every morning and a period is a fortnightly thing nobody thinks
     * about while looking at a Tuesday. So it links here, and this works out
     * which period the day falls in and hands over.
     *
     * forDate creates the period if the fortnight has not been opened yet,
     * which is the usual case when the thing being fixed happened today.
     */
    public function find(User $user, string $date)
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        return redirect()->route('timesheets.day', [
            'period' => TimesheetPeriod::forDate($date),
            'user' => $user,
            'date' => $date,
        ]);
    }
    public function show(TimesheetPeriod $period, User $user, string $date)
    {
        abort_unless($period->range()->contains($date), 404);

        $punches = $this->clock->punches($user->id, $date, withVoided: true);

        return view('timesheets.day', [
            'period' => $period,
            'range' => $period->range(),
            'staff' => $user,
            'date' => Carbon::parse($date),
            'day' => $this->clock->day($user->id, $date, $punches),
            'history' => $punches->sortBy([['punched_at', 'asc'], ['id', 'asc']]),
            'entry' => TimesheetEntry::where('user_id', $user->id)->where('work_date', $date)->first(),
            'types' => TimePunch::ACTIONS,
        ]);
    }

    /**
     * Add a punch somebody did not make.
     *
     * Not held to the state machine the employee's clock enforces: the whole
     * point is to insert the 5pm clock-out that is missing, and by the time
     * anybody notices there are usually punches on both sides of the gap. The
     * day is walked afterwards and says for itself whether it now adds up.
     */
    public function store(Request $request, TimesheetPeriod $period, User $user, string $date)
    {
        abort_unless($period->range()->contains($date), 404);

        if ($period->isApproved()) {
            return back()->with('warning', 'That period has been approved and can no longer be changed.');
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(TimePunch::LABELS))],
            'at' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:160'],
        ]);

        $punch = $this->clock->punch(
            user: $user,
            type: $data['type'],
            at: $this->moment($date, $data['at']),
            source: TimePunch::SOURCE_SUPERVISOR,
            by: $request->user(),
            reason: $data['reason'],
            ip: $request->ip(),
        );

        return back()->with('success', 'Added: '.$punch->label().' at '.$punch->time().'. The day has been rebuilt from its punches.');
    }

    /**
     * Void a punch, or replace it with a corrected one.
     *
     * Both are the same act as far as the record is concerned — the original
     * stops counting and never stops being visible. A replacement additionally
     * points back at what it replaced, so the chain reads in the order somebody
     * changed their mind.
     */
    /*
     * The edit panel on the week grid.
     *
     * The day screen above corrects one punch per request. The panel is the
     * same job done the way somebody actually does it: open the day, fix the
     * two or three things wrong with it, say why once, save once. Every change
     * still lands on the record as a void plus a replacement — the panel is a
     * different door onto the same audit trail, not a new one.
     */

    /** The day as data: what is on it, what it comes to, and what went before. */
    public function panel(Request $request, User $user, string $date)
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        return response()->json($this->panelPayload($request, $user, $date));
    }

    /**
     * Every change the panel made, applied together.
     *
     * Three kinds — move a punch, add one, take one off — and one reason for
     * the lot. Checked twice: once by the panel as it is typed, so the row
     * turns red before Save is pressed, and once here by walking the day the
     * changes would produce through the clock's own reckoning. A sequence the
     * clock cannot pay is refused whole, so a half-applied day is not a thing
     * that can exist.
     */
    public function savePanel(Request $request, User $user, string $date)
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        if (TimesheetPeriod::forDate($date)->isApproved()) {
            return response()->json(['message' => 'That period has been approved and can no longer be changed.'], 422);
        }

        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(TimePunch::REASONS))],
            // 120, so that label + colon + note stays inside the record's 160.
            'note' => ['nullable', 'string', 'max:120', 'required_if:reason,other'],
            'changes' => ['required', 'array', 'min:1', 'max:40'],
            'changes.*.op' => ['required', Rule::in(['move', 'add', 'remove'])],
            'changes.*.id' => ['required_if:changes.*.op,move,remove', 'nullable', 'integer'],
            'changes.*.type' => ['required_if:changes.*.op,add', 'nullable', Rule::in(array_keys(TimePunch::LABELS))],
            'changes.*.at' => ['required_if:changes.*.op,move,add', 'nullable', 'date_format:H:i'],
        ], [
            'note.required_if' => 'Say what happened — "Other" needs a note.',
        ]);

        $live = $this->clock->punches($user->id, $date)->keyBy('id');

        // Every id must be one of this person's live punches on this day. A
        // stale panel — somebody else fixed the day first — fails here rather
        // than voiding a punch that was already replaced.
        foreach ($data['changes'] as $change) {
            if (in_array($change['op'], ['move', 'remove'], true) && ! $live->has($change['id'])) {
                return response()->json(['message' => 'The day changed underneath you. Reload and try again.'], 409);
            }

            // The day starts with the clock-in, so it can be moved but never
            // taken off — the row it leaves behind would be a day that never
            // started with hours on it.
            if ($change['op'] === 'remove' && $live[$change['id']]->type === TimePunch::IN) {
                return response()->json(['message' => 'Clock-in can be moved but not removed.'], 422);
            }
        }

        // What the day would be. Unsaved models are enough for walk(): it reads
        // type and time and nothing else, so the same code that decides what a
        // day pays decides whether these changes are allowed.
        $proposed = $live->map(fn (TimePunch $punch) => $punch->replicate()->forceFill(['id' => $punch->id]));

        foreach ($data['changes'] as $change) {
            if ($change['op'] === 'move') {
                $proposed[$change['id']]->punched_at = $this->moment($date, $change['at']);
            } elseif ($change['op'] === 'remove') {
                $proposed->forget($change['id']);
            } else {
                $proposed->push(new TimePunch([
                    'user_id' => $user->id,
                    'work_date' => $date,
                    'punched_at' => $this->moment($date, $change['at']),
                    'type' => $change['type'],
                ]));
            }
        }

        $dry = $this->clock->day($user->id, $date, $proposed->values());

        if ($dry['broken']) {
            return response()->json([
                'message' => 'The punches are out of order: '.implode('; ', $dry['problems']).'.',
                'problems' => $dry['problems'],
            ], 422);
        }

        $reason = TimePunch::reasonText($data['reason'], $data['note'] ?? null);
        $by = $request->user();
        $ip = $request->ip();

        DB::transaction(function () use ($data, $live, $user, $date, $reason, $by, $ip) {
            foreach ($data['changes'] as $change) {
                if ($change['op'] === 'remove') {
                    $this->clock->void($live[$change['id']], $by, $reason);

                    continue;
                }

                if ($change['op'] === 'move') {
                    $original = $live[$change['id']];
                    $this->clock->void($original, $by, $reason);
                    $this->clock->punch(
                        user: $user,
                        type: $original->type,
                        at: $this->moment($date, $change['at']),
                        source: TimePunch::SOURCE_SUPERVISOR,
                        by: $by,
                        reason: $reason,
                        ip: $ip,
                        corrects: $original,
                    );

                    continue;
                }

                $this->clock->punch(
                    user: $user,
                    type: $change['type'],
                    at: $this->moment($date, $change['at']),
                    source: TimePunch::SOURCE_SUPERVISOR,
                    by: $by,
                    reason: $reason,
                    ip: $ip,
                );
            }
        });

        return response()->json(['saved' => true] + $this->panelPayload($request, $user, $date));
    }

    /**
     * Everything the panel draws, in one shape, whether it was just opened or
     * just saved — so the panel after a save is the panel as it would be if
     * somebody opened it fresh, with nothing carried over from before.
     */
    private function panelPayload(Request $request, User $user, string $date): array
    {
        $all = $this->clock->punches($user->id, $date, withVoided: true);
        $day = $this->clock->day($user->id, $date, $all);
        $when = Carbon::parse($date);

        $shifts = StaffShift::where('user_id', $user->id)->whereDate('shift_date', $date)->orderBy('starts_at')->get();
        $hm = fn (?int $minutes) => $minutes === null ? null : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

        // Newest first: the entry just written is the one being looked for.
        $history = $all
            ->filter(fn (TimePunch $punch) => $punch->isCorrection() || $punch->isVoided())
            ->sortByDesc(fn (TimePunch $punch) => ($punch->voided_at ?? $punch->created_at)->timestamp)
            ->values()
            ->map(function (TimePunch $punch) {
                // A replacement carries its predecessor, so it tells the whole
                // story in one line; a void with no replacement is a removal;
                // anything else a supervisor wrote was an addition. A voided
                // punch that was replaced is told by its replacement's line.
                if ($punch->corrects) {
                    return [
                        'who' => $punch->recorder?->name,
                        'when' => $punch->created_at->diffForHumans(),
                        'reason' => $punch->reason,
                        'what' => $punch->label().' '.$punch->corrects->time().' → '.$punch->time(),
                    ];
                }

                if ($punch->isVoided()) {
                    if (TimePunch::where('corrects_id', $punch->id)->exists()) {
                        return null;
                    }

                    return [
                        'who' => $punch->voider?->name,
                        'when' => $punch->voided_at->diffForHumans(),
                        'reason' => $punch->void_reason,
                        'what' => $punch->label().' '.$punch->time().' removed',
                    ];
                }

                return [
                    'who' => $punch->recorder?->name,
                    'when' => $punch->created_at->diffForHumans(),
                    'reason' => $punch->reason,
                    'what' => $punch->label().' missing → '.$punch->time(),
                ];
            })
            ->filter()
            ->values();

        // The grid cell this panel was opened from, painted by the grid's own
        // code, and the row's total for the days the grid is showing.
        $grid = app(StaffTimesheetController::class);
        $from = $request->query('from', $when->copy()->startOfWeek()->toDateString());
        $to = $request->query('to', $when->copy()->endOfWeek()->toDateString());

        return [
            'staff' => [
                'id' => $user->id,
                'name' => $user->name,
                'staff_id' => $user->staffId(),
                'role' => $user->jobRole(),
            ],
            'date' => $date,
            'date_label' => $when->format('l, M j'),
            'locked' => TimesheetPeriod::forDate($date)->isApproved(),
            'today' => $when->isToday(),
            'punches' => $day['punches']->map(fn (TimePunch $punch) => [
                'id' => $punch->id,
                'type' => $punch->type,
                'label' => $punch->label(),
                'at' => $punch->punched_at->format('H:i'),
                'edited' => $punch->isCorrection(),
            ])->values(),
            'labels' => TimePunch::LABELS,
            'state' => $day['state'],
            'totals' => [
                'worked' => $day['worked'],
                'so_far' => $day['so_far'],
                'paid_break' => $day['paid_break'],
                'unpaid_break' => $day['unpaid_break'],
                'scheduled' => (int) $shifts->sum(fn (StaffShift $shift) => $shift->minutes()),
                'open' => $day['open'],
                'broken' => $day['broken'],
                'problems' => $day['problems'],
            ],
            'shift' => $shifts->isEmpty() ? null : [
                'starts_at' => $hm((int) $shifts->min('starts_at')),
                'ends_at' => $hm((int) $shifts->max('ends_at')),
            ],
            'paid_break_cap' => (int) config('daycare.timesheet.clock.paid_break_cap'),
            'reasons' => TimePunch::REASONS,
            'history' => $history,
            'cell' => $grid->cell($user, $date, $day),
            'hours' => $grid->hoursBetween($user, $from, $to),
        ];
    }
    public function amend(Request $request, TimesheetPeriod $period, User $user, TimePunch $punch)
    {
        abort_unless($punch->user_id === $user->id, 404);

        if ($period->isApproved()) {
            return back()->with('warning', 'That period has been approved and can no longer be changed.');
        }

        if ($punch->isVoided()) {
            return back()->with('warning', 'That punch has already been voided. Voiding it twice would say nothing new.');
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['void', 'correct'])],
            'at' => ['required_if:action,correct', 'nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:160'],
        ]);

        $original = $punch->time();
        $this->clock->void($punch, $request->user(), $data['reason']);

        if ($data['action'] === 'void') {
            return back()->with('success', 'Voided: '.$punch->label().' at '.$original.'. It stays on the day, struck through, with your reason.');
        }

        $replacement = $this->clock->punch(
            user: $user,
            type: $punch->type,
            at: $this->moment($punch->work_date->toDateString(), $data['at']),
            source: TimePunch::SOURCE_SUPERVISOR,
            by: $request->user(),
            reason: $data['reason'],
            ip: $request->ip(),
            corrects: $punch,
        );

        return back()->with('success', $punch->label().' moved from '.$original.' to '.$replacement->time().'. Both are on the record.');
    }

    /** A date and a wall-clock time, in the centre's own timezone. */
    private function moment(string $date, string $time): Carbon
    {
        return Carbon::parse($date.' '.$time.':00');
    }
}
