<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything anyone does, one line each, in the words the office reads.
 *
 * Ray (28 Oct 2026): "every time someone does something it is logged —
 * 11:00 AM = Farhana checked Saroya in @ 11:00 AM; 11:05 AM = Patrick
 * modified Evana check in time for Sept 15 from Nothing to 10:00 AM".
 *
 * The records themselves live in their own tables and keep their own
 * audit columns; this is the one place they are all told as a story, so
 * the office can ask "what happened today?" without knowing which table
 * to open. Written by App\Services\ActivityLog off the models' own events,
 * so there is no path that saves a record without a line here.
 *
 * The actor's name is frozen in actor_name: a line about what Patrick did
 * must still say Patrick after his account is renamed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 120);

            // What the line is about, so a child's or a staff member's own
            // history can be pulled out of the whole.
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_name', 160)->nullable();

            // 'created', 'updated' or 'deleted' on the underlying record.
            $table->string('event', 20);

            $table->string('text', 400);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('occurred_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
