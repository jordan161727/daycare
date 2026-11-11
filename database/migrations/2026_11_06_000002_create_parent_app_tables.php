<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parent app's side of Messages (Jordan, 6 Nov 2026) — see
 * docs/parent-app-api.md.
 *
 * A parent signs in with a code texted to their mobile, so there is no
 * password to keep: parent_login_codes holds the codes, hashed, for ten
 * minutes. parent_devices is each phone that wants a push when the centre
 * writes. parent_thread_reads is how far each parent has read, kept apart
 * from message_reads, which is staff accounts'. And every message now says
 * which way it travelled: a text (sms) or the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('parent_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('push_token')->unique();
            $table->string('platform', 16)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('parent_thread_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->timestamp('read_at')->nullable();
            $table->unique(['person_id', 'thread_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('channel', 8)->default('sms')->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
        Schema::dropIfExists('parent_thread_reads');
        Schema::dropIfExists('parent_devices');
        Schema::dropIfExists('parent_login_codes');
    }
};
