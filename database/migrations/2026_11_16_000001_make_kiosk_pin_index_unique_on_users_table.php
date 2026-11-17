<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One staff PIN, one person (QA K2, 16 Nov 2026).
 *
 * The clock and the room tablets identify somebody by what they key in, so
 * two people on one PIN was a screen that had to stop and send them both to
 * the office. The staff profile already refused a PIN somebody else held;
 * this makes the database refuse it too.
 *
 * kiosk_pin_index is already an HMAC of the PIN keyed with APP_KEY, never the
 * PIN itself, so the unique index is on that. NULLs do not collide, so staff
 * without a PIN are unaffected.
 *
 * It will not run over a collision. Rather than choose whose PIN to wipe — a
 * director finding out at seven in the morning, from somebody who cannot
 * clock in — it stops and says what to do: `php artisan kiosk:duplicate-pins`
 * lists who shares a PIN (without printing it), the staff profile gives all
 * but one of them a new one, and the migration is run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $shared = DB::table('users')
            ->whereNotNull('kiosk_pin_index')
            ->groupBy('kiosk_pin_index')
            ->havingRaw('count(*) > 1')
            ->pluck('kiosk_pin_index')
            ->count();

        if ($shared > 0) {
            throw new RuntimeException(
                $shared.' clock '.($shared === 1 ? 'PIN is' : 'PINs are').' shared by more than one member of staff, so staff PINs cannot be made unique yet. '
                .'Nothing has been changed. Run `php artisan kiosk:duplicate-pins` to see who, give all but one person in each group a new PIN '
                .'from their staff profile (Clock PIN → Replace), then run `php artisan migrate` again.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            // The plain index from 2026_09_21 is replaced, not doubled.
            $table->dropIndex(['kiosk_pin_index']);
            $table->unique('kiosk_pin_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['kiosk_pin_index']);
            $table->index('kiosk_pin_index');
        });
    }
};
