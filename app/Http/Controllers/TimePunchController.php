<?php

namespace App\Http\Controllers;

use App\Models\TimePunch;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\TimeClock;
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
