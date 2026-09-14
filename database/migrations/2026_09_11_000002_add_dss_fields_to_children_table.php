<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two numbers the state knows a subsidised child by.
 *
 * A voucher letter carries both: the case number, which belongs to the family
 * and covers every child on it, and the CIN — the client identification number
 * — which belongs to this child alone. The centre bills against them, so they
 * are read off the record far more often than they are typed into it.
 *
 * Strings, not integers: both mix letters and digits (S1177706D, HB14137F), and
 * a leading zero in either is part of the number rather than a rounding error.
 * Nullable, because most children are not on a subsidy at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('dss_case_no')->nullable()->after('lan');
            $table->string('dss_cin')->nullable()->after('dss_case_no');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn(['dss_case_no', 'dss_cin']);
        });
    }
};
