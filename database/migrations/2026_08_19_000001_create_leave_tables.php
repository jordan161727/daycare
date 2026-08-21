<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sick and vacation time: what was asked for, what was decided, and what that
 * left on the balance.
 *
 * The balance is a ledger rather than a column on the user, for the same reason
 * a bank statement is not a single number. Somebody who is told they have
 * eleven hours of vacation will ask where the twelfth went, and a running total
 * that can only be recalculated cannot answer. Every row here says how many
 * hours moved, when, why, and who caused it.
 *
 * Requests and the ledger are separate tables on purpose: a request is what
 * somebody asked for and a ledger entry is what it cost. A denied request costs
 * nothing and still has to survive as a record of having been asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // VACATION | SICK — see config('daycare.leave.types'). Held as the
            // leave vocabulary, not payroll's, and mapped on the way out.
            $table->string('leave_type');

            $table->date('starts_on');
            $table->date('ends_on');

            // What one day of this request is worth. A half day is a request
            // for four hours across one date, not half a row.
            $table->decimal('hours_per_day', 5, 2);

            // pending | approved | denied | cancelled. Nothing is ever deleted:
            // "I asked in April and was turned down" is exactly the thing an
            // employee comes back about, and a missing row cannot answer it.
            $table->string('status')->default('pending');

            $table->string('reason')->nullable();

            // Filled at the decision. Paid and unpaid are split because a
            // request can outrun the balance behind it: the days the balance
            // covers are paid leave, and the rest is an approved absence that
            // is not paid. Recorded rather than refused, so nobody discovers it
            // on a payslip.
            $table->decimal('paid_hours', 6, 2)->default(0);
            $table->decimal('unpaid_hours', 6, 2)->default(0);

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('decision_note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'starts_on']);
        });

        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('leave_type');

            // Signed: accrual adds, leave taken subtracts, an adjustment does
            // either. The balance is the sum, and there is nowhere else for it
            // to be wrong.
            $table->decimal('hours', 7, 2);

            $table->date('effective_on');

            // ACCRUAL | TAKEN | RESTORED | ADJUSTMENT.
            $table->string('source');

            /**
             * What this entry is about, in its source's own terms: the pay
             * period that earned it, or the request that spent it.
             *
             * Paired with the unique index below, this is what makes posting
             * idempotent — running accrual for August twice cannot pay the
             * accrual twice, whatever the button is clicked. An adjustment has
             * no reference and is therefore never deduplicated, which is right:
             * a director granting four hours twice meant it both times.
             */
            $table->string('reference')->nullable();

            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['user_id', 'leave_type', 'source', 'reference']);
            $table->index(['user_id', 'leave_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger_entries');
        Schema::dropIfExists('leave_requests');
    }
};
