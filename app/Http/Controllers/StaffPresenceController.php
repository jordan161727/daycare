<?php

namespace App\Http\Controllers;

use App\Models\KioskEvent;
use App\Services\StaffPresence;
use Illuminate\Http\Request;

/**
 * Who's where right now, and the flags the office has to answer.
 *
 * The right-hand column of the kiosk mock-up (HANDOFF §5.3.1), as a page of
 * its own: room by room, on break, in the office, not clocked in, and the
 * feed. The dashboard's ratio cards and the floor plan read the same
 * service; this is the plain list behind them.
 */
class StaffPresenceController extends Controller
{
    public function __construct(private StaffPresence $presence) {}

    public function board()
    {
        return view('staff.presence', [
            'board' => $this->presence->board(),
        ]);
    }

    /** A flag seen to: it stays in the feed, and stops being red. */
    public function resolve(Request $request, KioskEvent $event)
    {
        abort_unless($event->isFlag(), 404);

        $event->resolve($request->user());

        return redirect()->route('staff.presence')->with('status', 'Noted.');
    }
}
