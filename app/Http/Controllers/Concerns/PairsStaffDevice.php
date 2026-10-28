<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\StaffClockController;
use App\Models\StaffDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * How a signed-out screen proves which tablet it is.
 *
 * Shared by the front door clock and the room tablets. Each kind keeps its
 * own cookie and session key, so one browser can be both at once — the
 * Kiosks page shows the clock and a room tablet side by side, and they must
 * not turn into the same device. The PIN or card is what authenticates.
 */
trait PairsStaffDevice
{
    /**
     * A visit carrying ?token=…: pair, then drop the token from the address.
     *
     * Still honoured for tablets set up before pairing went (29 Oct 2026).
     * The cookie outlives the session by years — see
     * StaffClockController::PAIRED_DAYS for why.
     */
    protected function pairFromToken(Request $request, string $token, string $route)
    {
        $device = StaffDevice::forToken($token);

        abort_if($device === null, 403, 'That pairing token does not match a device.');

        $request->session()->put(StaffDevice::sessionKeyFor($device->kind), $device->id);

        return redirect()->route($route)->withCookie($this->deviceCookie($device->kind, $token));
    }

    /**
     * Which device this is, pairing it on the spot if it never was.
     *
     * No pairing step any more (Jordan, 29 Oct 2026: Settings → Devices and
     * its QR are off). A browser that opens /clock or /room is given a device
     * row and the long cookie on its first visit, so the punches and the room
     * still say which screen they came from.
     */
    protected function pairedDevice(Request $request): ?StaffDevice
    {
        $kind = $request->routeIs('room.*') ? StaffDevice::KIND_ROOM : StaffDevice::KIND_CLOCK;
        $device = $this->knownDevice($request, $kind);

        if ($device !== null) {
            return $device;
        }

        $device = new StaffDevice([
            'name' => StaffDevice::KINDS[$kind],
            'kind' => $kind,
            'is_active' => true,
        ]);
        // Saves it too: the row cannot exist without its token.
        $token = $device->issueToken();

        $request->session()->put(StaffDevice::sessionKeyFor($kind), $device->id);
        Cookie::queue($this->deviceCookie($kind, $token));

        return $device;
    }

    /**
     * The device of this kind this browser already is, if any and active.
     *
     * The session first, because it is a cheap integer lookup. The cookie is
     * the fallback and costs a hash check per device, so a hit on it re-seeds
     * the session and the expensive path runs once a session rather than once
     * a press. A room tablet paired before the kinds had their own cookie
     * still carries the clock's, so /room looks there too, for a room device.
     */
    private function knownDevice(Request $request, string $kind): ?StaffDevice
    {
        $id = $request->session()->get(StaffDevice::sessionKeyFor($kind));

        if (filled($id)) {
            $device = StaffDevice::where('is_active', true)->where('kind', $kind)->find($id);

            if ($device !== null) {
                return $device;
            }
        }

        $device = StaffDevice::forToken($request->cookie(StaffDevice::cookieFor($kind)));

        if ($device === null && $kind === StaffDevice::KIND_ROOM) {
            $device = StaffDevice::forToken($request->cookie(StaffDevice::COOKIE));
        }

        if ($device === null || $device->kind !== $kind) {
            return null;
        }

        $request->session()->put(StaffDevice::sessionKeyFor($kind), $device->id);

        return $device;
    }

    private function deviceCookie(string $kind, string $token)
    {
        return cookie(StaffDevice::cookieFor($kind), $token, StaffClockController::PAIRED_DAYS * 24 * 60, null, null, null, true, false, 'lax');
    }
}
