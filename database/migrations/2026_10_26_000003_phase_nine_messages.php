<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — Messages: texts to families.
 *
 * A thread is one family about one child (Room or Office), or one
 * announcement to everyone. A message is one text in it, out or in, with
 * who sent it and — for a group send — who got it and who was skipped.
 * Who gets a text at all is decided on the child's record (the "Texts"
 * tick on each person), not here; this only remembers what was sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            // room | office | ann
            $table->string('kind', 10);
            $table->foreignId('child_id')->nullable()->constrained()->cascadeOnDelete();
            // The room the thread belonged to when it was opened — what a
            // teacher's access is checked against, so a child moving rooms
            // does not hand their old teachers the new room's conversation.
            $table->string('classroom', 60)->nullable();
            $table->string('title')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'child_id']);
            $table->index('last_message_at');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            // out (the centre) | in (a parent)
            $table->string('direction', 3);
            $table->text('body')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            // [{kind: photo|video, path, name, size, duration}]
            $table->json('media')->nullable();
            // One send to a room or to everyone lands in many threads; the
            // key ties the copies together for the counts.
            $table->string('broadcast_key', 40)->nullable()->index();
            $table->unsignedInteger('delivered')->default(0);
            // [{name, why}]
            $table->json('skipped')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('message_reads', function (Blueprint $table) {
            $table->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            // The newest message they had seen when they last opened it.
            // Unread is "a parent's text with a higher id than this" — an
            // id, not a time, because two things can happen in one second.
            $table->unsignedBigInteger('last_message_id')->default(0);

            $table->primary(['thread_id', 'user_id']);
        });

        // A parent who replied STOP is skipped from then on, by law and by
        // courtesy; the date is kept so the office can see when.
        Schema::table('people', function (Blueprint $table) {
            $table->timestamp('texts_stopped_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('people', fn (Blueprint $table) => $table->dropColumn('texts_stopped_at'));
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
    }
};
