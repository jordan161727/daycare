<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Staff (docs/work.md).
 *
 * One table: the one-off changes to a person's day that the Time off tab
 * keeps — out all day, out until a time, leaving at a time, only available
 * between two times, or an extra day they do not usually work. Rules say
 * what is always true of somebody; these say what is true on one date, and
 * the schedule treats each as a Must for that date only.
 *
 * Separate from leave_requests on purpose: a leave request is asked for by
 * the person and decided by a director against a balance. These are the
 * director's own notes about a date and are true the moment they are saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // A stretch of days (vacation) runs to here; a single day leaves it null.
            $table->date('until')->nullable();
            // all | until | leave | window | extra
            $table->string('kind', 12)->default('all');
            // Minutes past midnight, used by until (from), leave (to), window and extra (both).
            $table->unsignedSmallInteger('from_minute')->nullable();
            $table->unsignedSmallInteger('to_minute')->nullable();
            // sick | vacation | appointment | personal | family | extra | other
            $table->string('reason', 20)->default('other');
            $table->boolean('paid')->default(false);
            // Director only.
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_time_off');
    }
};
