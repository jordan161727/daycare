<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use App\Models\Setting;
use App\Models\StaffDevice;
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
class SettingController extends Controller
{
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

    /** Who may open the director's screens, and who issued their card. */
    public function administrators()
    {
        return view('settings.administrators', [
            'tab' => 'administrators',
            'admins' => User::where('role', 'admin')->orderBy('name')->get(),
        ]);
    }

    /**
     * The screens staff punch at — set up here and nowhere else.
     *
     * This used to be its own top-level page with a link in the sidebar, which
     * put a screen opened twice a year beside the ones opened every morning.
     * The old routes still carry the actions; only the page moved.
     */
    public function devices()
    {
        return view('settings.devices', [
            'tab' => 'devices',
            'devices' => StaffDevice::orderByDesc('is_active')->orderBy('name')->get(),
            // Shown once, immediately after pairing, and never again — see
            // StaffDevice::issueToken.
            'issued' => session('issued_device'),
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
            'events' => LoginEvent::with('user')->latest('created_at')->latest('id')->take(self::HISTORY_ROWS)->get(),
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
