<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A generated staff roster for one week, and the shifts in it.
 *
 * Kept apart from schedule_weeks/schedule_slots, which are the children's
 * booked days. The two share a Monday but nothing else: a child's week is
 * edited a tick at a time and must never be regenerated, while a staff week is
 * thrown away and re-solved whenever a rule changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_schedule_weeks', function (Blueprint $table) {
            $table->id();
            $table->date('week_start')->unique();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            // Everything the solver could not satisfy: ratio shortfalls, broken
            // preferences, people under their required hours. Surfaced verbatim
            // on the schedule screen, because an unexplained gap reads as a bug.
            $table->json('warnings')->nullable();

            $table->timestamps();
        });

        Schema::create('staff_shifts', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('shift_date');
            $table->string('day', 3);

            // Minutes past midnight, half-open [starts_at, ends_at).
            $table->unsignedSmallInteger('starts_at');
            $table->unsignedSmallInteger('ends_at');

            $table->string('classroom');

            // STAFF is the person's own shift. FLOAT and PATCH are cover the
            // solver added to close a ratio gap — worth showing differently,
            // because they are the shifts a director most often wants to redo.
            $table->string('role')->default('STAFF');

            $table->timestamps();

            $table->index(['week_start', 'day']);
            $table->index(['week_start', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_shifts');
        Schema::dropIfExists('staff_schedule_weeks');
    }
};
