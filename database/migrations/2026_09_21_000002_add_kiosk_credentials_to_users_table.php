<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a member of staff proves who they are at a kiosk.
 *
 * Not their password. The screen is in a lobby, the queue is three people
 * long at ten past seven, and a password typed on a shared tablet in front of
 * a queue is a password that stops being one. So the clock gets credentials of
 * its own, which open nothing else: a card to scan, and four digits for the
 * morning somebody left the card in a coat.
 *
 * Both are stored the way the door's PINs are — an HMAC index to find the row
 * by, and a hash to prove it with — so the table on its own is neither a list
 * of card numbers nor a list of PINs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The card. Long and random: it is scanned, never typed, so there
            // is no reason for it to be short enough to guess.
            $table->string('card_index')->nullable()->index();
            $table->string('card_hash')->nullable();

            // When it was issued, so a reprint can be told from the original
            // and a card reported lost has a date beside it.
            $table->timestamp('card_issued_at')->nullable();

            // The fallback. Four digits is what somebody will actually key in
            // with a queue behind them; the lockout below is what makes four
            // digits defensible.
            $table->string('kiosk_pin_index')->nullable()->index();
            $table->string('kiosk_pin_hash')->nullable();

            $table->unsignedInteger('kiosk_failed_attempts')->default(0);
            $table->timestamp('kiosk_locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'card_index', 'card_hash', 'card_issued_at',
                'kiosk_pin_index', 'kiosk_pin_hash',
                'kiosk_failed_attempts', 'kiosk_locked_until',
            ]);
        });
    }
};
