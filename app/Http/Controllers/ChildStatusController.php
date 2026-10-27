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

        $data = $request->validate([
            'last_day' => ['required', 'date'],
            'reason' => ['required', Rule::in(Child::WITHDRAWAL_REASONS)],
            'could_return' => ['nullable', Rule::in(Child::COULD_RETURN)],
            'note' => ['nullable', 'string', 'max:500'],
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

        $this->enrollment->reenroll($child);

        return redirect()->route('children.show', $child)->with('success', $child->first_name.' is back on the roll with the same LAN and family.');
    }
}
