<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PairsStaffDevice;
use App\Models\StaffDevice;
use App\Models\User;
use App\Services\CentreSettings;
use App\Services\StaffKiosk;
use App\Services\StaffPresence;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The tablet that hangs in a classroom (HANDOFF §5.3).
 *
 * Same PIN as the staff time clock, same pairing, a different question: not
 * "are you working?" but "are you in this room?". It makes room check-ins,
 * which the ratios are counted from, and it never clocks anybody out —
 * leaving for the day is done at the door, where the payroll record is made.
 *
 * Which room it is in is a fact about the tablet, keyed into it with the
 * room's code from Settings → Rooms & hours. Still no URL: a tablet carried
 * from Toddler to PreK is told so at the tablet. But since QA K4 (16 Nov
 * 2026) not by anybody — the gear asks for an administrator's PIN before it
 * shows the room-code pad, and checks it again when the code arrives.
 */
class RoomTabletController extends Controller
{
    use PairsStaffDevice;

    public function __construct(private StaffKiosk $kiosk, private StaffPresence $presence, private CentreSettings $centre) {}

    public function index(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $token = $request->query('token');

        if (filled($token)) {
            return $this->pairFromToken($request, $token, 'room.kiosk');
        }

        // The Teachers / Children / Ratio strip that sat under the masthead
        // was taken off again (Jordan, 29 Oct 2026: "remove this").
        // Nothing about anybody is on the page before a PIN: the room's name,
        // the room colours, and the lengths below. Who is in the room is on
        // the director's Who's where, behind a login.
        return view('room.kiosk', [
            'device' => $this->pairedDevice($request),
            // From config/kiosk.php (QA K1 and K3, 16 Nov 2026).
            'pinLength' => (int) config('kiosk.pin_length'),
            'codeLength' => (int) config('kiosk.room_code_length'),
            'idleSeconds' => (int) config('kiosk.idle_clear_seconds'),
        ]);
    }

    /**
     * A PIN keyed at the tablet.
     *
     * Somebody not on the clock is clocked in and placed here at once — the
     * owner's choice — and the screen says so and stops; there is nothing
     * for them to pick. Everybody else gets the room screen and a ticket.
     */
    public function identify(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $data = $request->validate([
            'card' => ['nullable', 'string', 'max:120'],
            // The staff PIN's length, the clock's (QA K1, 16 Nov 2026).
            'pin' => ['nullable', 'digits:'.(int) config('kiosk.pin_length')],
        ]);

        $device = $this->pairedDevice($request);

        if ($device === null) {
            return response()->json(['status' => 'unpaired'], 200);
        }

        $found = $this->kiosk->identify($data['card'] ?? null, $data['pin'] ?? null);

        if ($found['status'] !== 'ok') {
            return response()->json(['status' => $found['status']], 200);
        }

        $arrival = $this->kiosk->tabletArrival($found['user'], $device, $found['method'], $request->ip());

        if ($arrival['status'] === 'no_room') {
            return response()->json(['status' => 'no_room'], 200);
        }

        if ($arrival['status'] === 'auto_clock_in') {
            return response()->json([
                'status' => 'done',
                'done' => $arrival['done'],
                'staff' => $this->kiosk->tabletScreenFor($found['user'], $device),
            ]);
        }

        $ticket = (string) Str::uuid();

        $request->session()->put('room_tablet.ticket', [
            'token' => $ticket,
            'user' => $found['user']->id,
            'method' => $found['method'],
            'until' => now()->addSeconds(StaffClockController::TICKET_SECONDS)->timestamp,
        ]);

        return response()->json([
            'status' => 'ok',
            'ticket' => $ticket,
            'staff' => $this->kiosk->tabletScreenFor($found['user'], $device),
        ]);
    }

    /** "Check into PreK", one of the other rooms, or "Taking a break". */
    public function punch(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $data = $request->validate([
            'ticket' => ['required', 'string'],
            'action' => ['required', 'string', 'max:40'],
            'room' => ['nullable', 'string', 'max:60'],
        ]);

        $device = $this->pairedDevice($request);

        if ($device === null) {
            return response()->json(['status' => 'unpaired'], 200);
        }

        $ticket = $request->session()->get('room_tablet.ticket');

        if (! is_array($ticket)
            || ! hash_equals((string) $ticket['token'], $data['ticket'])
            || $ticket['until'] < now()->timestamp) {
            $request->session()->forget('room_tablet.ticket');

            return response()->json(['status' => 'expired'], 200);
        }

        $user = User::find($ticket['user']);
        $request->session()->forget('room_tablet.ticket');

        if ($user === null) {
            return response()->json(['status' => 'expired'], 200);
        }

        $result = $this->kiosk->tabletPunch(
            user: $user,
            action: $data['action'],
            room: $data['room'] ?? null,
            method: $ticket['method'],
            device: $device,
            ip: $request->ip(),
        );

        if ($result['status'] !== 'ok') {
            return response()->json([
                'status' => $result['status'],
                'staff' => $this->kiosk->tabletScreenFor($user, $device),
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'action' => $result['action'],
            'done' => $result['done'],
            'staff' => $this->kiosk->tabletScreenFor($user, $device),
        ]);
    }

    /** Where the gear's go-ahead is held between the two presses. */
    private const SETUP_SESSION_KEY = 'room_tablet.setup';

    /**
     * The gear, step one: an administrator's PIN (QA K4, 16 Nov 2026).
     *
     * A room code alone used to move the tablet, so anybody who had seen one
     * keyed could carry a tablet's check-ins into another room. Now the gear
     * asks for a director first — a staff PIN whose account is an admin —
     * and only then shows the room-code pad.
     *
     * The PIN is looked up the way the clock looks one up, so the lockout is
     * the shared one. What comes back is a short-lived go-ahead held in this
     * device's session, and setup() refuses a code without it: the pad
     * appearing on screen is a convenience, not the check.
     */
    public function authorizeSetup(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $data = $request->validate([
            'admin_pin' => ['required', 'digits:'.(int) config('kiosk.pin_length')],
        ]);

        $device = $this->pairedDevice($request);

        if ($device === null) {
            return response()->json(['status' => 'unpaired'], 200);
        }

        $found = $this->kiosk->identifyAdmin($data['admin_pin']);

        if ($found['status'] !== 'ok') {
            $request->session()->forget(self::SETUP_SESSION_KEY);

            return response()->json(['status' => $found['status']], 200);
        }

        $token = Str::random(40);

        $request->session()->put(self::SETUP_SESSION_KEY, [
            'token' => hash('sha256', $token),
            'user' => $found['user']->id,
            'device' => $device->id,
            'until' => now()->addSeconds((int) config('kiosk.setup_token_seconds'))->timestamp,
        ]);

        return response()->json([
            'status' => 'ok',
            'setup_token' => $token,
            'first_name' => $found['user']->firstName(),
        ]);
    }

    /**
     * The gear, step two: a room code keyed in, and the tablet is that room
     * from now on.
     *
     * Only with the go-ahead from authorizeSetup(), for this device, still in
     * date, from somebody who is still an administrator, still holds a PIN
     * and is not locked out — checked here again rather than trusted from the
     * screen (QA K4, 16 Nov 2026). Throttled like a PIN, because it is one —
     * a few digits a stranger at the tablet could work through — and on the
     * gear's own five-in-ten-minutes. RULE: codes never equal a staff PIN
     * (checked when they are set), so a PIN keyed here by mistake does not
     * move the tablet, and a code keyed at the door does not clock anyone in.
     * Every pairing, and every wrong code, goes into login history.
     */
    public function setup(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $data = $request->validate([
            'code' => ['required', 'digits:'.(int) config('kiosk.room_code_length')],
            'setup_token' => ['nullable', 'string', 'max:100'],
        ]);

        $device = $this->pairedDevice($request);

        if ($device === null) {
            return response()->json(['status' => 'unpaired'], 200);
        }

        $admin = $this->setupAdmin($request, $device, $data['setup_token'] ?? null);

        if ($admin === null) {
            $request->session()->forget(self::SETUP_SESSION_KEY);
            $this->kiosk->recordPairing(false, null, 'room_code');

            return response()->json(['status' => 'needs_admin'], 200);
        }

        $room = $this->centre->roomForTabletCode($data['code']);

        if ($room === null) {
            $this->kiosk->recordPairing(false, $admin, 'room_code');

            return response()->json(['status' => 'bad_code'], 200);
        }

        // One go-ahead, one pairing.
        $request->session()->forget(self::SETUP_SESSION_KEY);

        $was = $device->classroom;
        $device->setRoom($room);
        $device->touchSeen();

        // An ordinary grey line, not a flag: a tablet being moved is a
        // thing that happens, not a thing the office must act on. Who moved
        // it is on the login-history row beside it, the administrator whose
        // PIN opened the gear.
        $this->presence->logTablet($device, 'Room tablet set to '.$room.($was && $was !== $room ? ' (was '.$was.')' : ''));
        $this->kiosk->recordPairing(true, $admin, 'room_code');

        return response()->json(['status' => 'ok', 'room' => $room, 'was' => $was]);
    }

    /** The administrator behind this device's go-ahead, if it still stands. */
    private function setupAdmin(Request $request, StaffDevice $device, ?string $token): ?User
    {
        $held = $request->session()->get(self::SETUP_SESSION_KEY);

        if (! is_array($held) || blank($token)
            || ! hash_equals((string) $held['token'], hash('sha256', $token))
            || (int) $held['device'] !== (int) $device->id
            || $held['until'] < now()->timestamp) {
            return null;
        }

        $admin = User::find($held['user']);

        if ($admin === null || ! $admin->isAdmin() || ! $admin->hasKioskPin() || $admin->kioskIsLocked()) {
            return null;
        }

        return $admin;
    }
}
