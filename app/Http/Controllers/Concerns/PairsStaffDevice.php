<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\StaffClockController;
use App\Models\StaffDevice;
use Illuminate\Http\Request;

/**
 * How a signed-out screen proves which tablet it is.
 *
 * Shared by the front door clock and the room tablets, because they are the
 * same pairing: an administrator opens the device's link once on the tablet,
 * the token moves into a long cookie, and the address bar is left plain. The
 * two screens differ in what they do with a PIN, not in how they are trusted.
 */
trait PairsStaffDevice
{
    /**
     * A visit carrying ?token=…: pair, then drop the token from the address.
     *
     * The cookie is what remembers, and it outlives the session by years —
     * see StaffClockController::PAIRED_DAYS for why the session alone was
     * not enough.
     */
    protected function pairFromToken(Request $request, string $token, string $route)
    {
        $device = StaffDevice::forToken($token);

        abort_if($device === null, 403, 'That pairing token does not match a device.');

        $request->session()->put(StaffDevice::SESSION_KEY, $device->id);

        return redirect()->route($route)->withCookie(
            cookie(StaffDevice::COOKIE, $token, StaffClockController::PAIRED_DAYS * 24 * 60, null, null, null, true, false, 'lax')
        );
    }

    /**
     * Which device this is, if it has been paired and is still active.
     *
     * The session first, because it is a cheap integer lookup. The cookie is
     * the fallback and costs a hash check per device, so a hit on it re-seeds
     * the session and the expensive path runs once a session rather than once
     * a press.
     */
    protected function pairedDevice(Request $request): ?StaffDevice
    {
        $id = $request->session()->get(StaffDevice::SESSION_KEY);

        if (filled($id)) {
            $device = StaffDevice::where('is_active', true)->find($id);

            if ($device !== null) {
                return $device;
            }
        }

        $device = StaffDevice::forToken($request->cookie(StaffDevice::COOKIE));

        if ($device === null) {
            return null;
        }

        $request->session()->put(StaffDevice::SESSION_KEY, $device->id);

        return $device;
    }
}
