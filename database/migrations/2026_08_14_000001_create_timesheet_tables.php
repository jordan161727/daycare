<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the centre hands to payroll: one pay period, one row per person per day.
 *
 * The schedule says what was meant to happen. This says what did. The two are
 * kept in separate tables on purpose — correcting a Tuesday here must never
 * reach back and edit the roster that was published, or the record of what the
 * centre planned would quietly become a record of what it wishes it had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_periods', function (Blueprint $table) {
            $table->id();

            // Semi-monthly: the 1st–15th and the 16th–end of month. The start
            // date identifies the period, so there can only ever be one row for
            // it however many times the page is opened.
            $table->date('period_start')->unique();
            $table->date('period_end');

            // draft | approved. Approving freezes every entry inside it: the
            // hours have gone to payroll and people are being paid on them.
            $table->string('status')->default('draft');

            $table->timestamp('seeded_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });

        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');

            // Minutes past midnight, half-open like StaffShift. Null on a day
            // that was leave only, or one nobody has filled in yet.
            $table->unsignedSmallInteger('starts_at')->nullable();
            $table->unsignedSmallInteger('ends_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);

            // Paid but not worked. Held apart from the worked minutes because
            // the two are taxed the same and treated differently by overtime:
            // leave never pushes anybody over 40.
            $table->string('leave_code')->nullable();
            $table->unsignedSmallInteger('leave_minutes')->default(0);

            // 'schedule' — copied from the published roster and not yet looked
            // at. 'manual' — somebody said this is what happened. The
            // difference is the whole point of the approve step: it says how
            // much of the period is still a guess.
            $table->string('source')->default('schedule');

            $table->string('note')->nullable();

            $table->timestamps();

            // One row per person per day. A split shift becomes one row with a
            // break in the middle, which is also how it is paid.
            $table->unique(['user_id', 'work_date']);
            $table->index(['timesheet_period_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_entries');
        Schema::dropIfExists('timesheet_periods');
    }
};
