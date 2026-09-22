<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a punch was made, and what was presented to make it.
 *
 * `source` already says which of the three ways it arrived — the employee's own
 * clock, a supervisor filling a gap, and now a kiosk. These two say the rest of
 * it: which screen, and whether they scanned a card or keyed the PIN.
 *
 * Worth recording because it is what a dispute turns on. "I did clock in" is
 * answerable when the row says the card was scanned at the front desk at 7:52
 * and not otherwise. It is also the only way to notice that every wrong time
 * this fortnight came from the same tablet.
 *
 * Both nullable: every punch already in the table was made before either
 * existed, and backfilling a guess would be inventing evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_punches', function (Blueprint $table) {
            // Nulled rather than cascaded on delete: retiring a tablet must not
            // delete the record of the mornings it clocked people in on.
            $table->foreignId('staff_device_id')->nullable()->after('source')
                ->constrained('staff_devices')->nullOnDelete();

            // 'card' or 'pin'. Free text rather than an enum so a later reader
            // — a badge, a fingerprint — does not need a migration to be
            // recordable.
            $table->string('method')->nullable()->after('staff_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('time_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_device_id');
            $table->dropColumn('method');
        });
    }
};
