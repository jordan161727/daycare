<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Staff → Activity: everything anyone did, one day at a time.
 *
 * Ray asked for it (28 Oct 2026): "every time someone does something it is
 * logged". The lines are written by App\Services\ActivityLog off the
 * models' own events; this page only reads them, a day at a time, with a
 * person and a word to narrow by.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $date = trim((string) $request->input('date')) ?: today()->toDateString();
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $day = Carbon::parse($date, config('app.timezone'));
        $who = trim((string) $request->input('who', ''));
        $q = trim((string) $request->input('q', ''));

        $lines = Activity::onDay($day)
            ->when($who !== '', fn ($query) => $query->by($who))
            ->search($q)
            ->newestFirst()
            ->get();

        // Who did anything that day, for the filter. A kiosk or the system
        // has no account, so the value is the name itself.
        $people = Activity::onDay($day)
            ->select('actor_id', 'actor_name')
            ->distinct()
            ->orderBy('actor_name')
            ->get()
            ->map(fn (Activity $a) => ['value' => (string) ($a->actor_id ?: $a->actor_name), 'name' => $a->actor_name])
            ->unique('value')
            ->values();

        return view('staff.activity', [
            'day' => $day,
            'who' => $who,
            'q' => $q,
            'lines' => $lines,
            'people' => $people,
            'total' => Activity::onDay($day)->count(),
        ]);
    }
}
