<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What used to be written on the back of the paper sign-in sheet.
 *
 * A comment belongs to one attendance row — one child, one day, one session
 * — so a later day starts clean, and it keeps the child's id as well so a
 * report can list a child's comments without walking the attendance table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('attendances')->cascadeOnDelete();
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
    }
};
