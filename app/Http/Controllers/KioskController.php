<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\ChildAttendancePunch;
use App\Models\Person;
use App\Services\GuardianPin;
use App\Services\KioskAttendance;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The screen at the front door.
 *
 * Signed out by design — there is nobody to log in at a door, and a shared staff
 * login left open on a tablet in a lobby would be worse than none. What stands
 * in for authentication is the guardian's PIN, checked per press, plus a rate
 * limiter on the route and a lockout on the row.
 *
 * It is off unless `daycare.kiosk.enabled` says otherwise, so a centre that does
 * not want a public endpoint does not have one.
 *
 * The session holds only which guardian is standing there and for how long. No
 * child data, no staff identity, and it is dropped the moment the family screen
 * times out — the next person at the door starts from the keypad.
 */
class KioskController extends Controller
{
    /** How long a family screen stays open before the next person gets a blank keypad. */
    public const SESSION_SECONDS = 90;

    public function __construct(
        private GuardianPin $pins,
        private KioskAttendance $attendance,
    ) {}

    public function index(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        return view('kiosk.index', [
            'guardian' => $this->currentGuardian($request),
            'sessionSeconds' => self::SESSION_SECONDS,
            'device' => config('daycare.kiosk.device'),
        ]);
    }

    /** Six digits, and possibly the last four of a phone number to break a tie. */
    public function unlock(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $data = $request->validate([
            'pin' => ['required', 'digits:6'],
            'last4' => ['nullable', 'digits:4'],
        ]);

        $result = $this->pins->resolve($data['pin'], $data['last4'] ?? null);

        if ($result['status'] !== 'ok') {
            // "Not found" and "locked" read almost the same on screen: somebody
            // at the door should not learn from the wording whether a PIN exists.
            return response()->json(['status' => $result['status']], 200);
        }

        $request->session()->put('kiosk.guardian', $result['person']->id);
        $request->session()->put('kiosk.until', now()->addSeconds(self::SESSION_SECONDS)->timestamp);

        return response()->json([
            'status' => 'ok',
            'guardian' => ['name' => $result['person']->name],
            'children' => $this->familyOf($result['person']),
        ]);
    }

    /** Sign one child in or out. */
    public function punch(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $person = $this->currentGuardian($request);

        if (! $person) {
            return response()->json(['status' => 'expired'], 200);
        }

        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'direction' => ['required', Rule::in([ChildAttendancePunch::IN, ChildAttendancePunch::OUT])],
        ]);

        $child = Child::find($data['child_id']);

        if (! $child) {
            return response()->json(['status' => 'not_authorised'], 200);
        }

        $result = $this->attendance->punch($child, $data['direction'], $person, config('daycare.kiosk.device'));

        // Each press extends the window: a parent with three children should not
        // be timed out between the second and the third.
        $request->session()->put('kiosk.until', now()->addSeconds(self::SESSION_SECONDS)->timestamp);

        return response()->json($result + [
            'child' => ['id' => $child->id, 'name' => $child->first_name],
            'children' => $this->familyOf($person),
        ]);
    }

    public function lock(Request $request)
    {
        $request->session()->forget(['kiosk.guardian', 'kiosk.until']);

        return response()->json(['status' => 'ok']);
    }

    /** The adult standing there, if their ninety seconds have not run out. */
    private function currentGuardian(Request $request): ?Person
    {
        $until = $request->session()->get('kiosk.until');

        if (! $until || $until < now()->timestamp) {
            $request->session()->forget(['kiosk.guardian', 'kiosk.until']);

            return null;
        }

        return Person::find($request->session()->get('kiosk.guardian'));
    }

    /** Their children, and what the kiosk may offer for each. */
    private function familyOf(Person $person): array
    {
        $today = today()->toDateString();

        return $person->children()->orderBy('first_name')->get()->map(function (Child $child) use ($person, $today) {
            $attendance = $child->attendances()
                ->whereDate('attendance_date', $today)
                ->orderByDesc('signed_in_at')
                ->first();

            $present = $attendance !== null && $attendance->signed_out_at === null;

            return [
                'id' => $child->id,
                'name' => $child->first_name,
                'room' => $child->classroom,
                'animal' => \App\Services\ClassroomAssignment::animal($child->classroom),
                'present' => $present,
                'since' => $present ? $attendance->signed_in_at->format('g:i A') : null,
                // Being on a child's record and being allowed to take them home
                // are different permissions. The tile shows the difference —
                // and a court order set in the office now reaches this line,
                // which it could not while the door read its own table.
                'can_collect' => $person->mayCollect($child),
                'enrolled' => $child->isEnrolledOn($today),
            ];
        })->all();
    }
}
