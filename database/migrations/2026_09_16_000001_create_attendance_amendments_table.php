<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every hand-made change to a day already gone.
 *
 * The register used to take today's arrivals and nothing else, which made it
 * self-evidently a record of what happened: a row existed because somebody
 * tapped a cell while a child stood in front of them. Correcting earlier days
 * is genuinely needed — a child was here on Monday and nobody tapped — but it
 * means rows can now appear, and disappear, long after the fact and in weeks
 * the centre has already reported and billed from.
 *
 * So each one is written down here: who, when, which cell, and which way. It is
 * append-only and it outlives the row it describes, which is the whole point —
 * a deletion leaves nothing behind in `attendances` to ask about.
 *
 * Today's ordinary sign-ins are not logged. They are not amendments, and a log
 * that fills with three hundred routine arrivals a week is one nobody reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_amendments', function (Blueprint $table) {
            $table->id();

            // No foreign key to attendances: the row this describes may already
            // be gone, and for a removal it always is.
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('session')->default('FULL');

            // 'added' or 'removed'. A string rather than a boolean because a
            // third kind of correction is easy to imagine and "action = false"
            // would not survive it.
            $table->string('action');

            // The time that was on the row, so a removal can be described — and
            // undone by hand — from this table alone.
            $table->dateTime('signed_in_at')->nullable();

            // Nullable so the record survives the staff member leaving.
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The two questions asked of it: what happened to this cell, and
            // what was changed in this week.
            $table->index(['child_id', 'attendance_date']);
            $table->index('attendance_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_amendments');
    }
};
