<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Services\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The status lifecycle on the child record — Phase 2.2. Director only (see
 * the route group): a place on the roll is the office's to give and take.
 * Archive and restore are ChildArchiveController, which predates this.
 */
class ChildStatusController extends Controller
{
    public function __construct(private Enrollment $enrollment) {}

    /** Pending → Active. */
    public function start(Request $request, Child $child)
    {
        abort_unless($child->isPending(), 422, 'Only a pending child can be started.');

        $data = $request->validate(['first_day' => ['required', 'date']]);

        if ($refused = $this->incompleteForActive($child)) {
            return $refused;
        }

        $this->enrollment->start($child, Carbon::parse($data['first_day']), $request->user());

        $first = Carbon::parse($data['first_day']);

        return redirect()->route('children.show', $child)->with('success', $first->lte(Carbon::today())
            ? $child->first_name.' is enrolled from '.$first->format('M j').'.'
            : $child->first_name.' starts on '.$first->format('l, M j').' — the record turns Active that morning.');
    }

    /** Pending → Withdrawn, never started. */
    public function cancel(Request $request, Child $child)
    {
        abort_unless($child->isPending(), 422, 'Only a pending child can be cancelled.');

        $data = $request->validate([
            'reason' => ['required', Rule::in(Child::CANCEL_REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->enrollment->cancel($child, $data['reason'], $data['note'] ?? null);

        return redirect()->route('children.show', $child)->with('success', $child->first_name.'\'s enrollment was cancelled. They are under Children → Withdrawn.');
    }

    /** Active → Withdrawn, from a last day. */
    public function withdraw(Request $request, Child $child)
    {
        abort_unless($child->status === 'Active', 422, 'Only an active child can be withdrawn.');

        // Already leaving: a second Withdraw from a tab opened before the first
        // would quietly replace the last day, reason and note the office gave.
        abort_if($child->withdrawn_on !== null, 422, $child->first_name.' is already withdrawing — last day '.$child->withdrawn_on?->format('M j, Y').'.');

        // Every field is required (client test plan): the last day, the
        // reason, whether they could come back and the note all go on the DSS
        // page and the enrollment history. The last day cannot come before the
        // first.
        $data = $request->validate([
            'last_day' => array_filter(['required', 'date', $child->enrolled_on ? 'after_or_equal:'.$child->enrolled_on->toDateString() : null]),
            'reason' => ['required', Rule::in(Child::WITHDRAWAL_REASONS)],
            'could_return' => ['required', Rule::in(Child::COULD_RETURN)],
            'note' => ['required', 'string', 'max:500'],
        ], [
            'last_day.after_or_equal' => 'The last day cannot be before the first day ('.$child->enrolled_on?->format('M j, Y').').',
        ]);

        $last = Carbon::parse($data['last_day']);

        $this->enrollment->withdraw($child, $last, $data['reason'], $data['could_return'] ?? null, $data['note'] ?? null);

        return redirect()->route('children.show', $child)->with('success', $last->lt(Carbon::today())
            ? $child->first_name.' is withdrawn. Nothing is deleted — the record, attendance and notes all stay.'
            : $child->first_name.'\'s last day is '.$last->format('l, M j').'. They stay on check-in until then and come off the roll the morning after.');
    }

    /** Withdrawn → Active. */
    public function reenroll(Request $request, Child $child)
    {
        abort_unless($child->isWithdrawn(), 422, 'Only a withdrawn child can be re-enrolled.');

        if ($refused = $this->incompleteForActive($child)) {
            return $refused;
        }

        $this->enrollment->reenroll($child);

        return redirect()->route('children.show', $child)->with('success', $child->first_name.' is back on the roll with the same LAN and family.');
    }

    /**
     * Start and Re-enroll both make the child Active, so both need what
     * Active needs: a date of birth, a guardian and an emergency contact
     * (Child::ACTIVE_REQUIRES). Refused back to the record, which shows the
     * message at the top, naming exactly what is missing. A future first day
     * is refused too — the nightly command would otherwise make an
     * incomplete record Active on its own.
     */
    private function incompleteForActive(Child $child)
    {
        $missing = $child->loadMissing('personLinks')->missingForActive();

        if ($missing === []) {
            return null;
        }

        return redirect()->route('children.show', $child)
            ->withErrors(['status' => Child::activeRequirementMessage($missing, $child->first_name)])
            ->withInput();
    }
}
