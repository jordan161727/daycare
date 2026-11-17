<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Floor plan QA G5 — a cover made with the floor plan's "Move X to Y" button
 * keeps who approved it; created_at already says when. Nullable, so every
 * shift already on the roster (and every shift the schedule generates) is
 * left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('staff_shifts', 'moved_by')) {
            return;
        }

        Schema::table('staff_shifts', function (Blueprint $table) {
            $table->foreignId('moved_by')->nullable()->after('covers_user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('staff_shifts', 'moved_by')) {
            return;
        }

        Schema::table('staff_shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moved_by');
        });
    }
};
