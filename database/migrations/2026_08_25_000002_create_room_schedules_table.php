<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hours a room runs.
 *
 * Distinct from staff_shifts, which is the solved roster for one particular
 * week and is thrown away whenever it is regenerated. This is the standing
 * arrangement — "Infant runs 8:00 to 5:00" — the thing a parent is told and a
 * child's record is read against. One row per room, no week.
 *
 * Who is in the room is deliberately not here. That changes week to week and
 * is the roster's answer; a room's hours are the thing that holds still.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_schedules', function (Blueprint $table) {
            $table->id();

            // The room name as ClassroomAssignment knows it, which is also what
            // children.classroom holds. Unique: a room runs one way.
            $table->string('room')->unique();

            // Nullable on purpose: a room nobody has set hours for yet is a
            // real state, and one worth telling apart from a room that opens
            // at midnight.
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_schedules');
    }
};
