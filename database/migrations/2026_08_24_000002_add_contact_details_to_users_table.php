<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of what a staff member keeps on their own profile: who to call in an
 * emergency, their birthday, and how they get to work.
 *
 * Guarded column by column, like the photo migration before it: the staff
 * profile migration on the payroll line adds these same columns, and either may
 * arrive first depending on which line a database was built from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'emergency_contact')) {
                $table->string('emergency_contact')->nullable()->after('phone');
            }

            if (! Schema::hasColumn('users', 'emergency_phone')) {
                $table->string('emergency_phone')->nullable()->after('emergency_contact');
            }

            if (! Schema::hasColumn('users', 'dob')) {
                $table->date('dob')->nullable()->after('emergency_phone');
            }

            if (! Schema::hasColumn('users', 'transport')) {
                $table->string('transport')->nullable()->after('dob');
            }
        });

        // The photo migration sized `phone` at 30, narrower than the 40 the
        // forms accept and the 255 the payroll line stores. Widen it so a long
        // number is not truncated on its way in.
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['emergency_contact', 'emergency_phone', 'dob', 'transport'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
