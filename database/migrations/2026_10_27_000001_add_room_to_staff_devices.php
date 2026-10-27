<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of screen now, and one of them stands in a room.
 *
 * The front door clock makes payroll punches. A room tablet makes room
 * check-ins — "I am in PreK now" — which is what the ratios are counted
 * from. Both are paired the same way and both take the same PIN; the only
 * difference is which page the tablet opens and, for a room tablet, which
 * room it is currently standing in.
 *
 * The room is on the device rather than on a URL so that a tablet carried
 * from Toddler to PreK can be told so with a four-digit room code at the
 * tablet itself (HANDOFF §5.3), with no administrator involved.
 *
 * Both additive: every device paired before this is a clock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_devices', function (Blueprint $table) {
            // 'clock' (the front door) or 'room' (a classroom tablet).
            $table->string('kind', 20)->default('clock')->after('location');

            // Which room a room tablet is in right now. Null for a clock, and
            // for a room tablet nobody has keyed a room code into yet.
            $table->string('classroom')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('staff_devices', function (Blueprint $table) {
            $table->dropColumn(['kind', 'classroom']);
        });
    }
};
