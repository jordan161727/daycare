<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One uploaded payroll run, split into a slip per employee.
 *
 * The batch keeps the original combined PDF on a private disk; a slip is the
 * pages of it belonging to one person plus where that copy was sent. Send
 * status is stored rather than inferred so a half-finished run can be picked
 * up later without anyone being paid twice the email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_batches', function (Blueprint $table) {
            $table->id();
            $table->string('period_label')->nullable();
            $table->string('original_filename');
            $table->string('path');
            $table->unsignedSmallInteger('page_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_batch_id')->constrained()->cascadeOnDelete();

            // Null when no staff record matched the name on the page. The slip
            // still exists so the director can see the page and assign it by
            // hand, rather than it vanishing from a run that looks complete.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The legal name actually read off the page, kept even after a
            // reassignment so a bad match can be traced back.
            $table->string('matched_name')->nullable();

            // Zero-based page indexes into the combined PDF.
            $table->json('pages');

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('check_date')->nullable();

            // pending | sent | failed
            $table->string('status')->default('pending');
            $table->string('sent_to')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['payroll_batch_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_slips');
        Schema::dropIfExists('payroll_batches');
    }
};
