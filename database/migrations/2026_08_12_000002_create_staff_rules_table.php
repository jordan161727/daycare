<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The constraints the staff scheduler solves against.
 *
 * One wide table rather than a column per rule type, because the set of rule
 * types is the part that keeps growing — a new one should be a case in the
 * generator, not a migration. See App\Models\StaffRule for the vocabulary and
 * which columns each type actually uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('rule_type');

            // HARD is never broken — the generator will leave a shift unfilled
            // first. SOFT is a preference it reports on when it cannot honour it.
            $table->string('priority')->default('HARD');

            // MON..FRI, or ALL. Null on rules that are not day-specific.
            $table->string('day', 3)->nullable();

            // Minutes past midnight, matching config('daycare.open'). Stored as
            // integers so the generator never parses a time string mid-solve.
            $table->unsignedSmallInteger('time_1')->nullable();
            $table->unsignedSmallInteger('time_2')->nullable();

            $table->decimal('number', 6, 2)->nullable();

            // WEEKLY/BIWEEKLY, a room name, or the other person on a NO_PAIR.
            $table->string('value_text')->nullable();

            // Why this rule exists, in the director's words. Shown beside it so
            // the next person to read it knows whether it still applies.
            $table->string('source_note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'rule_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_rules');
    }
};
