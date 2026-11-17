<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\LoginEvent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The settings a director may change without a deploy.
 *
 * Director only, and not only because of what is on it: a setting here changes
 * how hours are recorded for everybody, which is a bigger act than editing any
 * one person's record.
 *
 * Four tabs, because they are four different jobs done on four different days
 * — the company details are set once, the administrators list is read when
 * somebody leaves, the devices are opened when a tablet is replaced, and the
 * login history is opened when something looks wrong.
 */
class SettingController extends Controller implements \Illuminate\Routing\Controllers\HasMiddleware
{
    /**
     * The owner's alone (client test plan, roles): the route group lets any
     * admin in, and an office admin is an admin. See User::isOwner.
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, \Closure $next) {
                abort_unless($request->user()?->isOwner(), 403);

                return $next($request);
            },
        ];
    }

    /**
     * How the centre records attendance.
     *
     *   time_tracking   — in and out, and hours come from the difference.
     *   attendance_only — in only. The centre knows who came; it does not
     *                     claim to know how long they stayed.
     *
     * The second is not a lesser version of the first: a centre that only
     * takes an arrival has no honest way to produce hours, so the screens stop
     * asking for a clock-out rather than flagging its absence as a fault.
     */
    public const TIME_TRACKING = 'time_tracking';

    public const ATTENDANCE_ONLY = 'attendance_only';

    /** How much login history is worth keeping on screen. */
    private const HISTORY_ROWS = 100;

    public function company()
    {
        return view('settings.company', [
            'tab' => 'company',
            'companyName' => Setting::get('company.name', config('daycare.company.name')),
            'mode' => Setting::get('attendance.mode', self::TIME_TRACKING),
            'scanner' => Setting::bool('kiosk.scanner', true),
            'sleep' => Setting::bool('kiosk.allow_sleep', false),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:120'],
            'attendance_mode' => ['required', Rule::in([self::TIME_TRACKING, self::ATTENDANCE_ONLY])],
        ]);

        Setting::put('company.name', $data['company_name'] ?? null);
        Setting::put('attendance.mode', $data['attendance_mode']);

        // Unticked boxes are not posted at all, so these are read off the
        // request rather than out of the validated data — which would leave a
        // toggle stuck on the moment somebody turned it off.
        Setting::put('kiosk.scanner', $request->boolean('scanner') ? '1' : '0');
        Setting::put('kiosk.allow_sleep', $request->boolean('allow_sleep') ? '1' : '0');

        return redirect()->route('settings.company')->with('status', 'Settings saved.');
    }

    /**
     * The archived children, and whether the Children page shows them.
     *
     * The only place an archived child is seen without asking for them: the
     * Children page leaves them out of everything — the list, the search,
     * the export and every count — so this is where they are found and put
     * back. Unarchive restores as Inactive, never Active: a record tidied
     * here should not land back in a room's head count by surprise.
     */
    public function children()
    {
        return view('settings.children', [
            'tab' => 'children',
            'showArchived' => ChildController::showsArchived(),
            'archived' => Child::archived()->with('archivedBy')->get(),
        ]);
    }

    /**
     * Settings → Children → Names: how a child's name reads, for everyone.
     * Moved here from the register's ⋯ menu (Jordan, 27 Oct 2026).
     */
    public function updateNames(Request $request)
    {
        $data = $request->validate(['name_format' => ['required', Rule::in(array_keys(User::NAME_FORMATS))]]);
        Setting::put(User::NAME_FORMAT_SETTING, $data['name_format']);

        return redirect()->route('settings.children')->with('status', 'Names now read as '.User::NAME_FORMATS[$data['name_format']].'.');
    }

    /** The one toggle on that page. Unticked boxes are not posted, so read off the request. */
    public function updateChildren(Request $request)
    {
        Setting::put(ChildController::SHOW_ARCHIVED_SETTING, $request->boolean('show_archived') ? '1' : '0');

        return redirect()->route('settings.children')->with('status', 'Settings saved.');
    }

    /** Settings → Rooms & hours — Phase 1.2: each room's figures and hours, and the centre's day. */
    public function rooms(\App\Services\CentreSettings $centre)
    {
        return view('settings.rooms', [
            'tab' => 'rooms',
            'rooms' => $centre->rooms(),
            'centre' => collect(\App\Services\CentreSettings::KEYS)->map(fn ($meta, $key) => $centre->get($key))->all(),
            'warnings' => $centre->warnings(),
        ]);
    }

    public function updateRooms(Request $request, \App\Services\CentreSettings $centre)
    {
        $roomNames = \App\Services\ClassroomAssignment::rooms();
        $trim = fn ($t) => blank($t) ? null : substr((string) $t, 0, 5);
        $request->merge([
            'rooms' => collect($request->input('rooms', []))->map(fn ($row) => array_merge($row, [
                'opens_at' => $trim($row['opens_at'] ?? null), 'closes_at' => $trim($row['closes_at'] ?? null),
            ]))->all(),
            'centre' => collect($request->input('centre', []))->map(fn ($v, $k) => in_array($k, ['longest_shift_hours', 'max_week_hours', 'early_dropoff_room'], true) ? $v : $trim($v))->all(),
        ]);

        $validated = $request->validate([
            'rooms' => ['required', 'array'],
            'rooms.*.room' => ['required', Rule::in($roomNames)],
            'rooms.*.ratio' => ['required', 'integer', 'between:1,30'],
            'rooms.*.capacity' => ['required', 'integer', 'between:0,60'],
            'rooms.*.opens_at' => ['nullable', 'date_format:H:i'],
            'rooms.*.closes_at' => ['nullable', 'date_format:H:i', 'after:rooms.*.opens_at'],
            // The room tablet's code (HANDOFF §5.3). RULES: four digits, one
            // room per code, and never a code that is also somebody's PIN —
            // a PIN keyed at the gear must not move the tablet, and a code
            // keyed at the door must not clock anybody in.
            //
            // The length is config('kiosk.room_code_length') (QA K1, 16 Nov
            // 2026). And one room per code holds across the whole centre, not
            // only across the rows posted: 'distinct' compares the request
            // with itself, so a save carrying only PreK could otherwise give
            // it the code Toddler already has (QA K4, 16 Nov 2026).
            'rooms.*.tablet_code' => ['nullable', 'digits:'.(int) config('kiosk.room_code_length'), 'distinct', function ($attribute, $value, $fail) use ($request) {
                if (filled($value) && User::kioskPinTaken((string) $value)) {
                    $fail('A room code cannot be the same as a staff PIN.');

                    return;
                }

                $posted = collect($request->input('rooms', []))->pluck('room')->filter()->all();
                $holder = filled($value) ? app(\App\Services\CentreSettings::class)->roomForTabletCode((string) $value) : null;

                if ($holder !== null && ! in_array($holder, $posted, true)) {
                    $fail('That code is already '.$holder.'’s. Each room needs its own code.');
                }
            }],
            'centre.centre_opens' => ['required', 'date_format:H:i'],
            'centre.centre_closes' => ['required', 'date_format:H:i', 'after:centre.centre_opens'],
            'centre.earliest_start' => ['required', 'date_format:H:i'],
            'centre.early_dropoff_room' => ['required', Rule::in($roomNames)],
            'centre.school_age_leaves' => ['required', 'date_format:H:i'],
            'centre.school_age_returns' => ['required', 'date_format:H:i'],
            'centre.longest_shift_hours' => ['required', 'integer', 'between:1,16'],
            'centre.max_week_hours' => ['required', 'integer', 'between:1,80'],
        ], [
            'centre.centre_closes.after' => 'The centre has to close after it opens.',
            'rooms.*.closes_at.after' => 'A room has to close after it opens.',
        ]);

        // Room tablet codes have their own table since QA K4 (16 Nov 2026),
        // with a unique index on the code behind the check above. The posted
        // rooms' codes are cleared first so two rooms can swap codes in one
        // save without the index seeing the same code twice in between.
        \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
            \App\Models\RoomTabletCode::whereIn('room', collect($validated['rooms'])->pluck('room'))->delete();

            foreach ($validated['rooms'] as $row) {
                \App\Models\RoomTabletCode::put($row['room'], $row['tablet_code'] ?? null);
            }
        });

        foreach ($validated['rooms'] as $row) {
            Setting::put("rooms.{$row['room']}.ratio", $row['ratio']);
            Setting::put("rooms.{$row['room']}.capacity", $row['capacity']);
            if ($row['opens_at'] && $row['closes_at']) {
                \App\Models\RoomSchedule::updateOrCreate(['room' => $row['room']], ['opens_at' => $row['opens_at'], 'closes_at' => $row['closes_at']]);
            }
        }
        foreach ($validated['centre'] as $key => $value) {
            Setting::put("centre.$key", $value);
        }

        // Saved, and anything that now disagrees is said on the way back.
        $warnings = $centre->warnings();

        return redirect()->route('settings.rooms')->with('status', $warnings === [] ? 'Rooms & hours saved.' : 'Rooms & hours saved — '.count($warnings).' thing'.(count($warnings) === 1 ? '' : 's').' worth a look below.');
    }

    /** Who may open the director's screens, and who issued their card. */
    public function administrators()
    {
        return view('settings.administrators', [
            'tab' => 'administrators',
            'admins' => User::where('role', 'admin')->orderBy('name')->get(),
        ]);
    }

    /**
     * Who signed in, and who tried and failed.
     *
     * The failures are the reason this screen exists, so they are not hidden
     * behind a filter — the newest hundred attempts of every kind, in order,
     * because a run of failures reads as a run only when it is next to the
     * successes it is interleaved with.
     */
    public function history()
    {
        return view('settings.login-history', [
            'tab' => 'history',
            'events' => LoginEvent::with(['user', 'by'])->latest('created_at')->latest('id')->take(self::HISTORY_ROWS)->get(),
            'rows' => self::HISTORY_ROWS,
            // Both kinds: a run of wrong PINs at the lobby screen is as much
            // worth surfacing as a run of wrong passwords, and somebody
            // reading this page is asking "did anything go wrong", not "did
            // anything go wrong at one particular door".
            'failures' => LoginEvent::failures()
                ->where('created_at', '>=', now()->subDay())
                ->count(),
        ]);
    }

    /** Whether the centre records a clock-out at all. */
    public static function tracksTime(): bool
    {
        return Setting::get('attendance.mode', self::TIME_TRACKING) !== self::ATTENDANCE_ONLY;
    }
}
