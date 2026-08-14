<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staff time clock: one row per punch, and the row is never unwritten.
 *
 * A punch is a claim about a moment that has already passed, so correcting one
 * cannot mean editing it — that would leave the corrected version looking
 * exactly like a punch nobody ever touched. Every correction here is a void
 * plus a replacement, both carrying who did it and why, which is why there is
 * no separate audit table: this one already cannot forget.
 *
 * The timesheet entry a day rolls up to is derived from these rows and can be
 * rebuilt from them at any time. These are the evidence; the entry is the
 * arithmetic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The business day the punch is filed under, kept separately from
            // the moment itself so a day can be gathered with one indexed
            // lookup rather than a range scan over timestamps.
            $table->date('work_date');
            $table->dateTime('punched_at');

            // IN | OUT | LUNCH_START | LUNCH_END | BREAK_START | BREAK_END.
            // Lunch and break are different types rather than one "away"
            // because they are paid differently — see the clock config.
            $table->string('type');

            // 'clock' — the employee pressed the button themselves.
            // 'supervisor' — somebody put it right afterwards, and then the
            // reason is not optional.
            $table->string('source')->default('clock');
            $table->string('reason')->nullable();

            // Who caused this row to exist. Usually the employee; on a
            // correction, the supervisor. Kept even when it is the same person
            // as user_id, so the trail never has to be inferred.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();

            // The punch this one replaces, when a correction is an amendment
            // rather than an insertion. Reading a day's history backwards
            // through this gives the whole chain of what somebody thought
            // happened, in the order they thought it.
            $table->foreignId('corrects_id')->nullable()->constrained('time_punches')->nullOnDelete();

            // Voided, never deleted. A voided punch still shows on the day,
            // struck through, with the name and the reason next to it.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'work_date']);
            $table->index('work_date');
        });

        Schema::table('timesheet_entries', function (Blueprint $table) {
            // Who said "this is what happened", and when. The source column
            // says a person confirmed the day; these say which person, which is
            // the difference between a record and a rumour.
            $table->foreignId('confirmed_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('confirmed_at');
        });

        Schema::dropIfExists('time_punches');
    }
};
