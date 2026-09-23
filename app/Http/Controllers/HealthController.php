<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\HealthAudit;
use App\Services\HealthScreening;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The health code on one end of a day, set or corrected.
 *
 * Separate from the attendance controller beside it because it answers a
 * different question. That one records whether a child was here; this records
 * what the adult at the door saw. They are written at the same moment and they
 * are still two facts — a child can be present with nothing wrong, present
 * with a fever, or present with nobody having looked yet, and only the third
 * is a gap somebody has to close.
 */
class HealthController extends Controller
{
    public function __construct(private HealthScreening $screening) {}

    /** The code list, for the picker and the legend. */
    public function codes()
    {
        return response()->json(['codes' => $this->screening->codes()]);
    }

    /**
     * Set, change or clear a code.
     *
     * POST rather than PATCH for the same reason as the rest of this app's
     * screens: the board talks to the server through postJson, and Laravel
     * reads method spoofing out of form parameters that a JSON body does not
     * populate.
     */
    public function update(Request $request, Attendance $attendance)
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in([HealthAudit::IN, HealthAudit::OUT])],
            // Nullable is the clear. Said explicitly so that "no code" and "a
            // code we could not read" cannot arrive looking the same.
            'code' => ['present', 'nullable', 'integer', 'between:0,255'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        // The child has to be one this person may see at all, before any
        // question of which day it is.
        abort_unless($attendance->child()->whereKey($attendance->child_id)->exists(), 404);

        $date = $attendance->attendance_date->toDateString();

        // Today is everybody's; a day gone by is the director's. A teacher
        // sees no picker on a past day, so this is a boundary rather than
        // something somebody trips over — but it is drawn here, where it
        // counts, because a stale tab is still a tab.
        abort_unless(
            $this->screening->mayRecord($request->user(), $date),
            403,
            'Only an administrator can change a health code on a day that has already gone.',
        );

        $attendance = $this->screening->record(
            $attendance,
            $data['direction'],
            $data['code'],
            $data['note'] ?? null,
            $request->user(),
        );

        return response()->json([
            'success' => true,
            'direction' => $data['direction'],
            'code' => $attendance->healthCode($data['direction']),
            'note' => $attendance->healthNote($data['direction']),
            'sick' => $attendance->isSick(),
        ]);
    }
}
