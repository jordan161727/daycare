<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How somebody identified themselves, not just whether it worked.
 *
 * The table was built for the sign-in form, where there is only one way in and
 * the column would have said "password" on every row. It now also carries the
 * kiosk, where a card and a PIN are different acts with different risks — a
 * PIN is four digits and can be guessed, a card is forty characters and can be
 * lost — so "three failures" means something different depending on which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            $table->string('method', 20)->nullable()->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
