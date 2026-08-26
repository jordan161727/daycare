<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A room's schedule stopped naming a teacher.
 *
 * Who is in a room is the roster's answer and changes week to week; the room's
 * hours are what holds still, and that is all this table is for now. The
 * create migration no longer adds the column at all, so this catches up the
 * databases that ran it while it did — hence the guard, which makes this a
 * no-op on anything installed since.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('room_schedules', 'teacher_id')) {
            return;
        }

        Schema::table('room_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('room_schedules', 'teacher_id')) {
            return;
        }

        Schema::table('room_schedules', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
