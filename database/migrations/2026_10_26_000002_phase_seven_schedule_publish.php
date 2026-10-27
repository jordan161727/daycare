<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — the Schedule page: a week is a draft until the director
 * publishes it.
 *
 * Generating and editing happen in private; staff see the week only once it
 * is published, and the header counts the edits made since, so "publish
 * again" is a visible step rather than something that happens by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_schedule_weeks', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('warnings');
            $table->foreignId('published_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();

            // Changes by hand since the week was generated or last published.
            $table->unsignedInteger('edits')->default(0)->after('published_by');
        });
    }

    public function down(): void
    {
        Schema::table('staff_schedule_weeks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn(['published_at', 'edits']);
        });
    }
};
