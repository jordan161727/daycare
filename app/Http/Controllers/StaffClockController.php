<?php

namespace App\Http\Controllers;

use App\Models\StaffDevice;
use App\Models\User;
use App\Services\StaffKiosk;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The time clock on the wall.
 *
 * Signed out by design, like the door kiosk beside it: there is nobody to log
 * in at a screen twelve people share, and a staff login left open on a lobby
 * tablet would be worse than none. What stands in for it is the card or the
 * PIN, checked on every press, plus the device's own pairing token.
 *
 * Nothing about anybody else is ever on this screen. It answers with one
 * person's name and their own hours, and forgets them the moment the
 * confirmation clears — the next person in the queue starts from a blank
 * screen rather than from the last person's day.
 */
class StaffClockController extends Controller
{
    /**
     * How long the buttons stay live after a card is presented.
     *
     * Long enough to read three tiles and press one; short enough that the
     * next person in the queue cannot press a button belonging to whoever
     * walked away without pressing anything.
     */
    public const TICKET_SECONDS = 45;

    public function __construct(private StaffKiosk $kiosk) {}

    /**
     * The screen itself.
     *
     * Paired by visiting once with ?token=… — the tablet is set up by an
     * administrator who has the token in front of them, and it is kept in the
     * device's session afterwards so the URL can be a plain bookmark.
     */
    public function index(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $token = $request->query('token');

        if (filled($token)) {
            $device = StaffDevice::forToken($token);

            abort_if($device === null, 403, 'That pairing token does not match a device.');

            $request->session()->put('staff_clock.device', $device->id);

            // Dropped from the address bar so a bookmark, a screenshot or a
            // shoulder does not carry the token around with it. The cookie is
            // what remembers instead, and it outlives the session by years —
            // see PAIRED_DAYS.
            return redirect()->route('clock.kiosk')->withCookie(
                cookie('staff_clock_device', $token, self::PAIRED_DAYS * 24 * 60, null, null, null, true, false, 'lax')
            );
        }

        return view('clock.kiosk', [
            'device' => $this->device($request),
        ]);
    }

    /**
     * A card scanned or a PIN keyed: who it is, and what they may press.
     *
     * Nothing is recorded here. The screen that follows shows only the actions
     * their state allows, so an impossible one is never on screen to be tapped
     * by mistake — and the same check runs again on the way in, because the
     * screen may have been read a minute ago by somebody else.
     */
    public function identify(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $data = $request->validate([
            'card' => ['nullable', 'string', 'max:120'],
            'pin' => ['nullable', 'digits:4'],
        ]);

        if ($this->device($request) === null) {
            return response()->json(['status' => 'unpaired'], 200);
        }

        $found = $this->kiosk->identify($data['card'] ?? null, $data['pin'] ?? null);

        if ($found['status'] !== 'ok') {
            return response()->json(['status' => $found['status']], 200);
        }

        // Held for the few seconds the buttons are on screen. The token is
        // what the press comes back with, so a punch cannot be posted for
        // somebody whose card was never presented at this screen.
        $ticket = (string) Str::uuid();

        $request->session()->put('staff_clock.ticket', [
            'token' => $ticket,
            'user' => $found['user']->id,
            'method' => $found['method'],
            'until' => now()->addSeconds(self::TICKET_SECONDS)->timestamp,
        ]);

        return response()->json([
            'status' => 'ok',
            'ticket' => $ticket,
            'staff' => $this->kiosk->screenFor($found['user']),
        ]);
    }

    /** One of the buttons that state offered, pressed. */
    public function punch(Request $request)
    {
        abort_unless(config('daycare.kiosk.enabled'), 404);

        $data = $request->validate([
            'ticket' => ['required', 'string'],
            'action' => ['required', 'string', 'max:40'],
        ]);

        $device = $this->device($request);

        // A screen that has not been paired takes no punches. Otherwise the
        // address on its own is a time clock anybody on the network can press.
        if ($device === null) {
            return response()->json(['status' => 'unpaired'], 200);
        }

        $ticket = $request->session()->get('staff_clock.ticket');

        // Expired, or never issued, or issued to somebody else: the person
        // whose card was scanned has walked away, and the next press belongs
        // to whoever is standing there now.
        if (! is_array($ticket)
            || ! hash_equals((string) $ticket['token'], $data['ticket'])
            || $ticket['until'] < now()->timestamp) {
            $request->session()->forget('staff_clock.ticket');

            return response()->json(['status' => 'expired'], 200);
        }

        $user = User::find($ticket['user']);

        if ($user === null) {
            return response()->json(['status' => 'expired'], 200);
        }

        $result = $this->kiosk->punch(
            user: $user,
            action: $data['action'],
            method: $ticket['method'],
            device: $device,
            ip: $request->ip(),
        );

        // One press per card presented: the ticket is spent whether the punch
        // landed or the screen turned out to be stale.
        $request->session()->forget('staff_clock.ticket');

        if ($result['status'] !== 'ok') {
            return response()->json([
                'status' => $result['status'],
                'staff' => $this->kiosk->screenFor($user),
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'action' => $result['action'],
            'title' => StaffKiosk::CONFIRMATIONS[$result['action']] ?? 'Recorded',
            'at' => $result['at'],
            'staff' => $this->kiosk->screenFor($user),
        ]);
    }

    /** The device this screen is, if it has been paired and is still active. */
    /**
     * How long a paired tablet stays paired.
     *
     * The session alone was not enough. It expires after SESSION_LIFETIME of
     * idleness — eight hours here — and a clock on a wall is idle from Friday
     * evening to Monday morning. Every kiosk in the building would have come
     * up unpaired on Monday, and somebody would have had to walk round with
     * the pairing links again. Weekly.
     *
     * So the pairing gets its own cookie, well over a year of it, holding the
     * token. Laravel encrypts cookies, so it is not readable off the tablet,
     * and it is no more of a secret than the token already on that device.
     * Deactivating the device still cuts it off at once, because the lookup
     * only ever considers active ones.
     */
    public const PAIRED_DAYS = 400;

    /**
     * Which device this is.
     *
     * The session first, because it is a cheap integer lookup. The cookie is
     * the fallback and costs a hash check per device, so a hit on it re-seeds
     * the session and the expensive path runs once a session rather than once
     * a press.
     */
    private function device(Request $request): ?StaffDevice
    {
        $id = $request->session()->get('staff_clock.device');

        if (filled($id)) {
            $device = StaffDevice::where('is_active', true)->find($id);

            if ($device !== null) {
                return $device;
            }
        }

        $device = StaffDevice::forToken($request->cookie('staff_clock_device'));

        if ($device === null) {
            return null;
        }

        $request->session()->put('staff_clock.device', $device->id);

        return $device;
    }
}
