<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a working member of staff is standing.
 *
 * A punch says somebody is on the clock; it does not say which room they are
 * in, and the ratios are counted per room. So beside the punches there is
 * this: one open row per working person, naming the room (or no room — the
 * office, the kitchen, a meeting), opened when they pick a room and closed
 * when they move, take a break, clock out, or the centre closes around them.
 *
 * Kept as a history rather than a column on the user, because "who was in
 * Infant at 10:15" is the question a licensing visit asks, and a column only
 * answers "who is there now".
 *
 * HANDOFF §5.1: at any moment a person is off, working in a room, working
 * with no room, or on a break. The first three are read from the punches
 * plus this table; a break remembers the room it was taken from through the
 * row it closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_room_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Null is "Office / no room": on the clock, not counted anywhere.
            $table->string('classroom')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            // How the row was opened — 'door' (the clock's room picker),
            // 'tablet' (a room tablet), 'auto' (a room tablet clocked them in
            // because they were not), 'return' (End Break put them back).
            $table->string('started_via', 20);

            // Why it closed — 'move', 'break', 'clock_out', 'closing'.
            $table->string('ended_reason', 20)->nullable();

            // Which screen opened and closed it. Nulled rather than cascaded:
            // retiring a tablet must not lose where people were.
            $table->foreignId('started_device_id')->nullable()->constrained('staff_devices')->nullOnDelete();
            $table->foreignId('ended_device_id')->nullable()->constrained('staff_devices')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'ended_at']);
            $table->index(['classroom', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_room_checkins');
    }
};
