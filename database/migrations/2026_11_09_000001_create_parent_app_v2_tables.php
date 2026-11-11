<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Version 2 of the parent app (9 Nov 2026). Three things a parent can now
 * tell the centre from the app, each kept on its own so nothing the staff own
 * is written by a parent:
 *
 * - parent_absence_reports: "my child will be away". The register's DSS
 *   marks are a billing decision and stay the director's; the report sits
 *   beside them, and lands in the Room conversation so the teachers see it.
 * - parent_pickup_plans: who is collecting today. The pick-up list itself
 *   (child_people.can_pickup) is unchanged; a one-time person is a note for
 *   the office to check, not a new authorised adult.
 * - parent_notification_prefs: which pushes a parent wants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_absence_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('absent_on');
            $table->string('reason', 16);
            $table->json('symptoms')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();

            $table->unique(['child_id', 'absent_on']);
        });

        Schema::create('parent_pickup_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->date('pickup_on');
            // Someone on the pick-up list, or null for a one-time person.
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('name', 100);
            $table->string('relation', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('set_by')->constrained('people')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();

            $table->unique(['child_id', 'pickup_on']);
        });

        Schema::create('parent_notification_prefs', function (Blueprint $table) {
            $table->foreignId('person_id')->primary()->constrained('people')->cascadeOnDelete();
            $table->boolean('messages')->default(true);
            $table->boolean('announcements')->default(true);
            $table->boolean('daily')->default(true);
            $table->boolean('photos')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_notification_prefs');
        Schema::dropIfExists('parent_pickup_plans');
        Schema::dropIfExists('parent_absence_reports');
    }
};
