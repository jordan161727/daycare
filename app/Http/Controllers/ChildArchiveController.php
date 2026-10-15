<?php

namespace App\Http\Controllers;

use App\Models\Child;
use Illuminate\Http\Request;

/**
 * Taking a child off every list, and putting them back.
 *
 * Archiving is not deleting: the record, the attendance and the history all
 * stay. What changes is where the child is seen — nowhere on the Children
 * page, the counts, the export or any roster — until somebody restores them.
 *
 * Both actions answer JSON when asked for it, because the Children page
 * removes the row and corrects its counts without reloading, and they
 * redirect otherwise, so a form with no JavaScript behind it still works.
 *
 * Director only: see the route group. A teacher may mark a child in their
 * room as Inactive; taking one off every list the centre keeps is a bigger
 * act than keeping a room's record.
 */
class ChildArchiveController extends Controller
{
    public function archive(Request $request, Child $child)
    {
        $child->archive($request->user());

        $name = $child->first_name.' '.$child->last_name;
        $message = $name.' was archived and hidden from this list. Find them in Settings › Children.';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'counts' => $this->counts($request),
            ]);
        }

        return redirect()->route('children.index')->with('success', $message);
    }

    /**
     * Back on the Children page.
     *
     * `to=previous` is what Undo on the toast sends — it means "as they
     * were". Everything else restores as Inactive, so that a record tidied
     * in Settings does not land back in a room's head count by surprise.
     */
    public function unarchive(Request $request, Child $child)
    {
        $restorePrevious = $request->input('to') === 'previous';

        $child->unarchive($restorePrevious);

        $name = $child->first_name.' '.$child->last_name;
        $message = $restorePrevious
            ? $name.' was restored as '.$child->status.'.'
            : $name.' is back on the Children page as Inactive.';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'status' => $child->status,
                'counts' => $this->counts($request),
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * The chip counts as the Children page draws them, so the page can
     * correct itself after a row goes without asking for the whole roll.
     */
    private function counts(Request $request): array
    {
        $user = $request->user();

        $byStatus = Child::visibleTo($user)
            ->notArchived()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'all' => (int) $byStatus->sum(),
            'byStatus' => collect(Child::ROLL_STATUSES)->mapWithKeys(fn ($status) => [$status => (int) ($byStatus[$status] ?? 0)])->all(),
            'archived' => Child::visibleTo($user)->archived()->count(),
        ];
    }
}
