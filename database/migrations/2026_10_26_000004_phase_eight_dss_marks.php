<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.1 — the DSS attendance page.
 *
 * The times themselves live in `attendances`, as they always have. What the
 * page adds is the two words the county's sheet needs that the register has
 * no column for: a day the child was *absent* (scheduled, did not come,
 * claimable as an absence) and a day that was *not scheduled* after all (not
 * a day, not an absence). One row per child per day, written by the person
 * reconciling the month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dss_day_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->date('marked_on');
            // absent | none
            $table->string('mark', 10);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['child_id', 'marked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dss_day_marks');
    }
};
