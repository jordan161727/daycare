<?php

namespace App\Http\Controllers;

use App\Models\StaffDevice;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;

/**
 * Registering the screens staff punch at.
 *
 * Director only. A device is a thing that can record hours somebody is paid
 * for, so adding one is the same kind of act as adding a member of staff.
 */
class StaffDeviceController extends Controller
{
    /**
     * The page moved into Settings; the actions did not.
     *
     * Kept as a redirect rather than deleted, because a director's bookmark to
     * /devices should land on the devices page rather than on a 404 — and the
     * pairing instructions that went out with the tablets say /devices.
     */
    public function index()
    {
        return redirect()->route('settings.devices');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
        ]);

        $device = new StaffDevice($data);
        $device->created_by = $request->user()->id;
        $device->token_hash = '';
        $device->save();

        $token = $device->issueToken();

        // Through the session rather than the URL: a pairing link in an address
        // bar is one in a history, a proxy log and a shared screen.
        return redirect()->route('settings.devices')->with('issued_device', [
            'id' => $device->id,
            'name' => $device->name,
            'url' => route('clock.kiosk', ['token' => $token]),
        ]);
    }

    public function update(Request $request, StaffDevice $device)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $device->fill($data)->save();

        return redirect()->route('settings.devices')->with('success', $device->name.' updated.');
    }

    /**
     * The pairing link for one device, and a QR of it.
     *
     * Asked for rather than listed: the links are not on the index because a
     * page of live kiosk addresses is a page somebody screenshots. Pressing
     * "Show link" is the deliberate act, and it does not disturb the tablet
     * that is already paired — which is what re-pairing would do.
     */
    public function link(StaffDevice $device)
    {
        abort_if(blank($device->token), 404, 'This device was paired before links could be shown again. Re-pair it to get one.');

        return view('devices.link', [
            'device' => $device,
            'url' => $device->pairingUrl(),
        ]);
    }

    /** The same address as something a tablet camera can read. */
    public function qr(StaffDevice $device)
    {
        abort_if(blank($device->token), 404);

        $qr = new QrCode(
            data: $device->pairingUrl(),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 320,
            margin: 12,
        );

        return response((new SvgWriter)->write($qr)->getString())
            ->header('Content-Type', 'image/svg+xml')
            // It is the pairing link: never kept by a browser or a proxy.
            ->header('Cache-Control', 'no-store, private');
    }

    /** A new token, which un-pairs whatever tablet held the old one. */
    public function repair(Request $request, StaffDevice $device)
    {
        $token = $device->issueToken();

        return redirect()->route('settings.devices')->with('issued_device', [
            'id' => $device->id,
            'name' => $device->name,
            'url' => route('clock.kiosk', ['token' => $token]),
        ]);
    }

    /**
     * Retired, not deleted.
     *
     * The punches it recorded still name it, and a device row that disappeared
     * would leave months of hours recorded by nothing.
     */
    public function destroy(StaffDevice $device)
    {
        $device->forceFill(['is_active' => false])->save();

        return redirect()->route('settings.devices')->with('success', $device->name.' has been retired.');
    }
}
