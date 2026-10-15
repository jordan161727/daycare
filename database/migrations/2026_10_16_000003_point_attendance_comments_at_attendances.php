<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The door reads the register's record again, for now.
 *
 * On 16 Oct 2026 the door got tables of its own (door_attendances and
 * friends); the same day the centre asked for the teacher screen to read
 * what the director's screen writes — one record, attendances and
 * attendance_returns, as before. The models point back at the shared
 * tables; this moves the comments' foreign key with them, since a comment
 * on an attendances row cannot reference door_attendances.
 *
 * The door_* tables are left standing, empty or nearly so, for the day the
 * split is wanted again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_comments', function (Blueprint $table) {
            $table->dropForeign(['attendance_id']);
            $table->foreign('attendance_id')->references('id')->on('attendances')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_comments', function (Blueprint $table) {
            $table->dropForeign(['attendance_id']);
            $table->foreign('attendance_id')->references('id')->on('door_attendances')->cascadeOnDelete();
        });
    }
};
