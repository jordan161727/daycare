<?php

namespace App\Http\Controllers;

use App\Models\User;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The card a member of staff scans at the clock.
 *
 * The code itself exists exactly once, at the moment it is issued: it is
 * hashed on the way into the row, so nothing here — not this controller, not
 * the database — can read it back out afterwards. That is the point. A card
 * whose number can be recovered is a card that can be cloned by anybody with
 * a database connection, and the hours it stamps are somebody's pay.
 *
 * What that costs is that the image cannot be regenerated on demand: printing
 * a second copy means issuing a new card, which retires the first. That is the
 * right trade — a lost card should stop working — but it is why the picture is
 * held briefly in the cache after issuing, so the print dialogue and the PNG
 * download can both reach it without the code ever going back to the browser
 * in a URL.
 */
class StaffCardController extends Controller
{
    /** Long enough to print and to change your mind; short enough to matter. */
    private const HOLD_MINUTES = 15;

    /**
     * Issue a card and hold its image for the moment it takes to print.
     *
     * Regenerating is the same act: there is no separate "reprint", because
     * there is nothing kept to reprint from.
     */
    public function issue(Request $request, User $staff)
    {
        $code = $staff->issueCard();

        Cache::put($this->key($staff), $code, now()->addMinutes(self::HOLD_MINUTES));

        return back()->with('success', $staff->firstName().'’s card is ready. Print or download it now — it cannot be shown again.');
    }

    /** The QR as SVG, for the page and the print dialogue. */
    public function show(Request $request, User $staff)
    {
        $code = Cache::get($this->key($staff));

        abort_if($code === null, 404, 'That card image is no longer available. Regenerate the card to print it.');

        return response($this->svg($code))
            ->header('Content-Type', 'image/svg+xml')
            // Never stored by a browser or a proxy: it is the card.
            ->header('Cache-Control', 'no-store, private');
    }

    /** The same code as a PNG, which is what gets emailed to a printer. */
    public function download(Request $request, User $staff)
    {
        $code = Cache::get($this->key($staff));

        abort_if($code === null, 404, 'That card image is no longer available. Regenerate the card to download it.');

        $name = str($staff->name)->slug()->value().'-'.strtolower($staff->staffId()).'.png';

        return response($this->png($code))
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Disposition', 'attachment; filename="'.$name.'"');
    }

    /** Set or replace the four digits that stand in for a lost card. */
    public function pin(Request $request, User $staff)
    {
        $data = $request->validate([
            // Confirmed, because a mistyped PIN is not discovered until
            // somebody is standing at the clock unable to start their shift.
            //
            // And nobody else's, because the clock identifies people by what
            // they key in. Two people on one PIN is not a security hole — it
            // is a screen that has to stop and ask which of you this is, every
            // morning, to both of them. Refusing it here is a moment's
            // inconvenience to the one director setting it.
            'kiosk_pin' => [
                'required', 'digits:4', 'confirmed',
                function (string $attribute, mixed $value, \Closure $fail) use ($staff) {
                    if (User::kioskPinTaken((string) $value, $staff)) {
                        $fail('Somebody else already uses that PIN. Pick four different digits.');
                    }
                },
            ],
        ]);

        $staff->setKioskPin($data['kiosk_pin']);

        return back()->with('success', $staff->firstName().'’s clock PIN has been set.');
    }

    /** Whether this staff member's freshly issued card is still printable. */
    public static function pendingFor(User $staff): bool
    {
        return Cache::has('staff-card:'.$staff->id);
    }

    private function key(User $staff): string
    {
        return 'staff-card:'.$staff->id;
    }

    private function qr(string $code): QrCode
    {
        return new QrCode(
            data: $code,
            encoding: new Encoding('UTF-8'),
            // High, because this is printed on a card that lives in a pocket
            // and is read by a cheap scanner in a lobby. A quarter of the code
            // can be creased or scuffed and it still reads.
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 320,
            margin: 12,
        );
    }

    private function svg(string $code): string
    {
        return (new SvgWriter)->write($this->qr($code))->getString();
    }

    private function png(string $code): string
    {
        return (new PngWriter)->write($this->qr($code))->getString();
    }
}
