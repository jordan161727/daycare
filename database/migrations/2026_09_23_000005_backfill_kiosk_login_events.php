<?php

use App\Models\LoginEvent;
use App\Models\TimePunch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The kiosk identifications the app already has evidence of.
 *
 * Login History started recording on the day it was built, so it opened empty
 * while a month of kiosk activity sat in time_punches. Every punch made at the
 * kiosk was preceded by a card or PIN that the clock recognised — the ticket
 * that authorises a punch is issued by that identification and nothing else —
 * so each of those punches is evidence that an identification happened, for
 * that person, at that moment, by that method, from that address.
 *
 * That is the whole of what is written here. Nothing is invented: only punches
 * that carry a kiosk method are used, and the person, the time, the method and
 * the address all come off the punch itself. The identification and the punch
 * are two requests seconds apart from one tablet, so the punch's address is
 * the address the card was presented at.
 *
 * Punches from the employee's web clock screen have no method and are skipped:
 * no card or PIN was presented for them, and a row claiming otherwise would be
 * a false one.
 *
 * Successes only. A failed identification leaves no punch behind, so the
 * failures before this table existed are simply gone — and inferring them
 * would be inventing them.
 *
 * Idempotent: a second run adds nothing, because it skips any moment already
 * recorded for that person.
 */
return new class extends Migration
{
    public function up(): void
    {
        $punches = $this->kioskPunches();

        $existing = LoginEvent::whereIn('outcome', [LoginEvent::KIOSK, LoginEvent::KIOSK_FAILED])
            ->get()
            ->map(fn (LoginEvent $event) => $event->user_id.'@'.$event->created_at?->toDateTimeString())
            ->flip();

        $rows = [];

        foreach ($punches as $punch) {
            $at = $punch->punched_at->toDateTimeString();

            if ($existing->has($punch->user_id.'@'.$at)) {
                continue;
            }

            $rows[] = [
                'user_id' => $punch->user_id,
                'email' => null,
                'outcome' => LoginEvent::KIOSK,
                'method' => $punch->method,
                'ip_address' => $punch->ip_address,
                // Not recorded on a punch, and the one field that would have to
                // be guessed. Left as it is: unknown.
                'user_agent' => null,
                'created_at' => $at,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('login_events')->insert($chunk);
        }
    }

    /**
     * Take back only what this put in.
     *
     * Matched against the punches it was built from rather than by a marker
     * column, so it cannot take a row that was logged live — and so the rows
     * it writes are ordinary ones with nothing tacked on to identify them.
     */
    public function down(): void
    {
        foreach ($this->kioskPunches() as $punch) {
            LoginEvent::where('outcome', LoginEvent::KIOSK)
                ->where('user_id', $punch->user_id)
                ->where('created_at', $punch->punched_at->toDateTimeString())
                ->limit(1)
                ->delete();
        }
    }

    /** The punches that can only have been made after an identification. */
    private function kioskPunches()
    {
        return TimePunch::whereNotNull('method')
            ->whereNotNull('user_id')
            ->orderBy('punched_at')
            ->get();
    }
};
