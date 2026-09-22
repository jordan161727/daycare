<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the pairing token where it can be read back.
 *
 * It was hashed, like a password, so the link could be shown exactly once and
 * never again — a tablet that lost its bookmark had to be paired afresh, which
 * silently stopped whichever screen still held the old token.
 *
 * That was the wrong weight for what this is. A pairing token opens the clock
 * screen and nothing else: it still takes a card or a PIN to record a single
 * minute, so possessing one is not possessing anybody's hours. Weighed against
 * an administrator standing at a tablet unable to see the address they are
 * meant to type, keeping it readable is the better trade.
 *
 * Encrypted rather than plain, so a copied database is not a list of live
 * kiosk links, and the hash stays beside it — that is still what a request is
 * checked against, so the pairing path is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_devices', function (Blueprint $table) {
            $table->text('token')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('staff_devices', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};
