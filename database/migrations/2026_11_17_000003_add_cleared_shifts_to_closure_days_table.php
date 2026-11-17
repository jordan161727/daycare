<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staff shifts a closure took off the roster, kept on the closure the way
 * cleared_slots keeps the children's ticks, so reopening the day can put them
 * back (QA, 17 Nov 2026: closing a day left six teachers' shifts on it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('closure_days', function (Blueprint $table) {
            $table->json('cleared_shifts')->nullable()->after('cleared_slots');
        });
    }

    public function down(): void
    {
        Schema::table('closure_days', function (Blueprint $table) {
            $table->dropColumn('cleared_shifts');
        });
    }
};
