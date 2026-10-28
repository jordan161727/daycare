<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PairsStaffDevice;
use App\Models\User;
use App\Services\CentreSettings;
use App\Services\StaffKiosk;
use App\Services\StaffPresence;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The tablet that hangs in a classroom (HANDOFF §5.3).
 *
 * Same PIN as the front door, same pairing, a different question: not "are
 * you working?" but "are you in this room?". It makes room check-ins, which
 * the ratios are counted from, and it never clocks anybody out — leaving for
 * the day is done at the door, where the payroll record is made.
 *
 * Which room it is in is a fact about the tablet, keyed into it with the
 * room's four-digit code from Settings → Rooms & hours. No administrator and
 * no URL: a tablet carried from Toddler to PreK is told so at the tablet.
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

        $device = $this->pairedDevice($request);

        return view('room.kiosk', [
            'device' => $device,
            'summary' => $this->strip($device?->classroom),
        ]);
    }

    /**
     * The tablet's own room in three numbers: teachers, children, ratio
     * (Jordan's "Kiosks — full-width layout" mock-up, 29 Oct 2026). Polled
     * by the tablet, and asked again after every press that changes them.
     * Counts only — no names — because anybody can read a tablet.
     */
    public function summary(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        return response()->json($this->strip($this->pairedDevice($request)?->classroom) ?? ['room' => null]);
    }

    private function strip(?string $room): ?array
    {
        if (blank($room)) {
            return null;
        }

        $s = $this->presence->roomSummary($room);

        return [
            'room' => $s['room'],
            'teachers' => $s['teachers'],
            'children' => $s['children'],
            'ratio' => $s['children'] === 0 ? 'No children' : ($s['short'] ? 'Short '.($s['needed'] - $s['teachers']) : 'In ratio'),
            'tone' => $s['children'] === 0 ? 'mut' : ($s['short'] ? 'bad' : 'ok'),
        ];
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
            'pin' => ['nullable', 'digits:4'],
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

    /**
     * The gear: a room code keyed in, and the tablet is that room from now on.
     *
     * Throttled like a PIN, because it is one — four digits that a stranger
     * at the tablet could work through. RULE: codes never equal a staff PIN
     * (checked when they are set), so a PIN keyed here by mistake does not
     * move the tablet, and a code keyed at the door does not clock anyone in.
     */
    public function setup(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $data = $request->validate(['code' => ['required', 'digits:4']]);

        $device = $this->pairedDevice($request);

        if ($device === null) {
            return response()->json(['status' => 'unpaired'], 200);
        }

        $room = $this->centre->roomForTabletCode($data['code']);

        if ($room === null) {
            return response()->json(['status' => 'bad_code'], 200);
        }

        $was = $device->classroom;
        $device->setRoom($room);
        $device->touchSeen();

        // An ordinary grey line, not a flag: a tablet being moved is a
        // thing that happens, not a thing the office must act on.
        $this->presence->logTablet($device, 'Room tablet set to '.$room.($was && $was !== $room ? ' (was '.$was.')' : ''));

        return response()->json(['status' => 'ok', 'room' => $room, 'was' => $was]);
    }
}
