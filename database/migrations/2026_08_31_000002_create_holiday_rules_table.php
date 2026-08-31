<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holidays that come back every year — Christmas, New Year's Day.
     *
     * The rule is the thing entered once; the closures it produces are ordinary
     * rows in closure_days, written years ahead. Everything that already reads
     * a closure — the attendance board, the projection, the staff roster, leave
     * — therefore needs no idea that annual holidays exist.
     *
     * Fixed dates only. A holiday that moves (Easter, or a fourth-Thursday
     * rule) is not expressible here and has to be entered year by year.
     */
    public function up(): void
    {
        Schema::create('holiday_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->string('reason');
            // The last year written out. A top-up fills the years after it and
            // never revisits one already done — so a single day reopened by
            // hand stays open instead of being resurrected on the next visit.
            $table->unsignedSmallInteger('materialised_through')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['month', 'day']);
        });

        Schema::table('closure_days', function (Blueprint $table) {
            // Which rule produced this day, so deleting the rule can take its
            // future days with it and the page can mark them as annual.
            $table->foreignId('holiday_rule_id')->nullable()->after('cleared_slots')
                ->constrained('holiday_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('closure_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holiday_rule_id');
        });

        Schema::dropIfExists('holiday_rules');
    }
};
