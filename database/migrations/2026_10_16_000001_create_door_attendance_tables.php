<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The door's own record.
 *
 * Teacher Attendance (the door screen) and Director Attendance (the register)
 * used to write to one table, so a clock-in at the door appeared on the
 * register and a sign-in on the register appeared at the door. The centre
 * asked for the two to be kept apart: each screen its own record, nothing
 * flowing between them. So the door gets its own tables — the same shape as
 * the register's, because the same code reads them — and the comments, which
 * are a door feature, hang off the door's rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('session', 4)->default('FULL');
            $table->dateTime('signed_in_at')->nullable();
            $table->dateTime('signed_out_at')->nullable();
            $table->unsignedTinyInteger('health_in_code')->nullable();
            $table->string('health_in_note', 120)->nullable();
            $table->unsignedTinyInteger('health_out_code')->nullable();
            $table->string('health_out_note', 120)->nullable();
            $table->timestamps();

            $table->unique(['child_id', 'attendance_date', 'session']);
            $table->index('attendance_date');
        });

        Schema::create('door_attendance_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('door_attendances')->cascadeOnDelete();
            $table->dateTime('left_at');
            $table->dateTime('returned_at');
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('door_health_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('door_attendances')->cascadeOnDelete();
            $table->string('direction', 3);
            $table->unsignedTinyInteger('old_code')->nullable();
            $table->unsignedTinyInteger('new_code')->nullable();
            $table->string('note', 120)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        // Comments are a door feature and were added the day before this
        // split, nowhere but a local database; they are remade against the
        // door's rows rather than migrated.
        Schema::dropIfExists('attendance_comments');
        Schema::create('attendance_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('door_attendances')->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tag', 20);
            $table->text('body');
            $table->timestamps();

            $table->index(['attendance_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_comments');
        Schema::dropIfExists('door_health_audits');
        Schema::dropIfExists('door_attendance_returns');
        Schema::dropIfExists('door_attendances');
    }
};
