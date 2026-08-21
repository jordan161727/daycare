<?php

namespace App\Http\Controllers;

use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(private TimeClock $clock) {}

    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The provided credentials do not match our records.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        $arrival = $this->clockIn($request->user(), $request->ip());

        if ($arrival === null) {
            return redirect()->intended(route('dashboard'));
        }

        // Straight to the clock, not the dashboard: they have just been clocked
        // in without pressing anything, so the screen that says so — and holds
        // the lunch, break and clock-out buttons — is the one to land on.
        return redirect()->intended(route('clock.index'))->with('success', $arrival);
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Signing in for the day is clocking in.
     *
     * Teachers reach for a login, not a clock — asking them to press both is
     * how a shift ends up with no start time on it. So the first sign-in of
     * the day writes the IN punch at the moment they signed in, and the clock
     * screen takes over from there for lunch, breaks and going home.
     *
     * Only the *first* sign-in, and only when nothing has been punched yet
     * today: somebody who clocked out at one and logs back in that evening to
     * look at next week's roster is not starting a second shift.
     *
     * @return ?string What to tell them, if anything was recorded.
     */
    private function clockIn(User $user, ?string $ip): ?string
    {
        if (! config('daycare.timesheet.clock.enabled') || $user->role !== 'teacher') {
            return null;
        }

        $today = today()->toDateString();

        if ($this->clock->punches($user->id, $today)->isNotEmpty()) {
            return null;
        }

        // A clock that cannot write must never be a locked door. The sign-in
        // stands, the punch is raised for somebody to look at, and the teacher
        // can still press Clock in themselves.
        try {
            $punch = $this->clock->punch(
                user: $user,
                type: TimePunch::IN,
                at: now(),
                ip: $ip,
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return 'Clocked in at '.$punch->time().'. Use the time clock for lunch, breaks and going home.';
    }
}
