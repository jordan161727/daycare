<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving a child.
 *
 * Archived is a third status beside Active and Inactive, not a deletion: the
 * record, the attendance and the history all stay. What it changes is where
 * the child is seen — nowhere on the Children page, or on any roster, until
 * somebody restores them from Settings › Children.
 *
 * The three columns are what Undo needs: when it happened, who did it, and
 * what the child was before, so that putting them back puts them back as
 * they were rather than as a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->string('status_before_archive')->nullable()->after('archived_by');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['archived_at', 'status_before_archive']);
        });
    }
};
