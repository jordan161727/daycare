<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Prefer rules a generated week could not keep, and why.
 *
 * Keyed by rule id: {"12": {"days": ["MON", "TUE"], "reason": "…"}}. Written by
 * StaffSchedule::generate() alongside the warnings it already keeps, so the
 * Rules tab can say "Not met this week" against the rule itself. Nullable and
 * additive: weeks generated before this exist without it and read as "none
 * recorded", not as "all kept".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_schedule_weeks', function (Blueprint $table) {
            $table->json('rule_misses')->nullable()->after('warnings');
        });
    }

    public function down(): void
    {
        Schema::table('staff_schedule_weeks', function (Blueprint $table) {
            $table->dropColumn('rule_misses');
        });
    }
};
