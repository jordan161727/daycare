<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — the register's tap (27 Oct 2026, Jordan).
 *
 * Marking a day Absent takes its times off the register. Tapping on from
 * Absent to Coming used to leave the day bare; Jordan wants the times back
 * as they were. So the Absent mark remembers the rows it replaced — session,
 * in, out — and Coming puts them back when nothing else has been typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dss_day_marks', function (Blueprint $table) {
            $table->json('kept')->nullable()->after('mark');
        });
    }

    public function down(): void
    {
        Schema::table('dss_day_marks', function (Blueprint $table) {
            $table->dropColumn('kept');
        });
    }
};
