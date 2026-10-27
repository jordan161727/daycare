<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4.2 — the floor plan's "Move X to Y until Z arrives" button.
 *
 * A cover made from the floor plan is an ordinary FLOAT shift on today's
 * roster, so the schedule, the timesheet and the ratio check all see it.
 * The one thing the roster did not know is who the cover stands in for:
 * this column says so, and the moment that person clocks in the float is
 * closed and the person goes back to their own room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_shifts', function (Blueprint $table) {
            $table->foreignId('covers_user_id')->nullable()->after('role')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff_shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('covers_user_id');
        });
    }
};
