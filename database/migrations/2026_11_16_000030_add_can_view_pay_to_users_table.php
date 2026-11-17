<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pay access as a permission rather than a person.
 *
 * Until now pay rates and direct deposit were shown to the director alone —
 * an admin account whose job title is exactly "Director" (User::isDirector).
 * This adds a flag the owner can grant on a staff profile, and sets it for
 * exactly the accounts the old rule let in, so nobody gains or loses pay
 * access on deploy. Additive only: one new column, and the only rows written
 * are the director accounts that get the flag switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'can_view_pay')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('can_view_pay')->default(false);
            });
        }

        // The old rule, word for word: role admin and job title Director.
        DB::table('users')
            ->where('role', 'admin')
            ->where('job_role', 'Director')
            ->update(['can_view_pay' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'can_view_pay')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('can_view_pay');
            });
        }
    }
};
