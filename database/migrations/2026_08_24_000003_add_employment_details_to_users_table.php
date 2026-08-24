<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employment facts a staff member reads on their own profile but does not
 * set: what they were hired as, the room they lead, the name payroll prints,
 * when they started, and their ASPIRE number.
 *
 * Guarded like the two before it, so a database built from the payroll line —
 * where the staff profile migration already added these — is left alone. The
 * money columns are deliberately not among them: nothing on a self-service page
 * needs a pay rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'employment')) {
                $table->string('employment')->nullable()->after('role');
            }

            if (! Schema::hasColumn('users', 'title')) {
                $table->string('title')->nullable()->after('employment');
            }

            if (! Schema::hasColumn('users', 'legal_name')) {
                $table->string('legal_name')->nullable()->after('title');
            }

            if (! Schema::hasColumn('users', 'start_date')) {
                $table->date('start_date')->nullable()->after('emergency_phone');
            }

            if (! Schema::hasColumn('users', 'aspire_id')) {
                $table->string('aspire_id')->nullable()->after('transport');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['employment', 'title', 'legal_name', 'start_date', 'aspire_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
