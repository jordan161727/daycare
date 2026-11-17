<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One PIN, one person, parents included (QA K2, 16 Nov 2026), and the length
 * each PIN was set at (QA K1).
 *
 * people.pin_index is already an HMAC of the PIN keyed with APP_KEY — the same
 * HMAC as users.kiosk_pin_index — never the PIN, so the unique index goes on
 * that. NULLs do not collide. A staff PIN that equals a parent's cannot be
 * held by an index across two tables; App\Rules\UniqueKioskPin refuses it
 * when either is set, and `kiosk:duplicate-pins` lists any already there.
 *
 * The lengths: a PIN cannot be read back out of its hash, so there is no
 * telling how long an existing one is. Every PIN set from here on records its
 * length; one set before is NULL, which `kiosk:duplicate-pins` lists as
 * "set before the length change" when config('kiosk.pin_length') is not 4
 * for staff or 6 for parents — the lengths the screens used until now.
 *
 * Like the staff migration before it, this will not run over two parents on
 * one PIN: it stops, changes nothing, and says how to find them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $shared = DB::table('people')
            ->whereNotNull('pin_index')
            ->groupBy('pin_index')
            ->havingRaw('count(*) > 1')
            ->pluck('pin_index')
            ->count();

        if ($shared > 0) {
            throw new RuntimeException(
                $shared.' door '.($shared === 1 ? 'PIN is' : 'PINs are').' shared by more than one parent, so parent PINs cannot be made unique yet. '
                .'Nothing has been changed. Run `php artisan kiosk:duplicate-pins` to see who, give all but one person in each group a new PIN, '
                .'then run `php artisan migrate` again.'
            );
        }

        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['pin_index']);
            $table->unique('pin_index');
            $table->unsignedTinyInteger('pin_length')->nullable()->after('pin_hash');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('kiosk_pin_length')->nullable()->after('kiosk_pin_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kiosk_pin_length');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('pin_length');
            $table->dropUnique(['pin_index']);
            $table->index('pin_index');
        });
    }
};
