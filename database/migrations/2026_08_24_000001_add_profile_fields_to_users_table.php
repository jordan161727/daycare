<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The photo and the phone number a staff member keeps on their own profile.
 *
 * Guarded column by column: the staff profile migration on the payroll line
 * adds `phone` too, and it always lands first on a database built from this
 * repository — unguarded, this migration fails outright on a fresh install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('phone');
            }
        });
    }

    public function down(): void
    {
        // Only `avatar_path` goes: `phone` belongs to the staff profile
        // migration, which is still in place underneath this one.
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'avatar_path')) {
                $table->dropColumn('avatar_path');
            }
        });
    }
};
