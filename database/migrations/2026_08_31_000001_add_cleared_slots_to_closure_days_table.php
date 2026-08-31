<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a closure took off the board, so reopening the day can put it back.
     *
     * Closing a day unticks every child on it. Without a record of which ticks
     * those were, reopening leaves an empty column and the director has to
     * rebuild the day from memory — so the closure carries its own undo.
     *
     * It lives on the closure rather than in a table of its own because it is
     * only ever read by the closure that wrote it, and dies with it.
     */
    public function up(): void
    {
        Schema::table('closure_days', function (Blueprint $table) {
            $table->json('cleared_slots')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('closure_days', function (Blueprint $table) {
            $table->dropColumn('cleared_slots');
        });
    }
};
