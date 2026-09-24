<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hour a day to come is booked for.
 *
 * The register could already say that a child is expected on Thursday. It
 * could not say when — and "expected" with no hour is half a booking, because
 * a room that knows six children are coming and not that four of them arrive
 * at seven cannot staff the morning.
 *
 * It is deliberately on the slot and not in `attendances`. An attendance row
 * means somebody arrived; writing one for Thursday would put a child into
 * Thursday's headcount, its ratios and its bill before Thursday happened. This
 * is the plan, it lives with the rest of the plan, and it is replaced by the
 * real arrival time the moment there is one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_slots', function (Blueprint $table) {
            // Null is a real answer and the commonest one: booked, hour not
            // agreed. The child's own drop_off_time stands in where it matters.
            $table->time('planned_time')->nullable()->after('is_scheduled');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_slots', function (Blueprint $table) {
            $table->dropColumn('planned_time');
        });
    }
};
