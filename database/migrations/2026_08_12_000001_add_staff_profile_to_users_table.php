<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employment side of a staff record.
 *
 * Until now a teacher was a login. The scheduler needs to know what kind of
 * contract they are on, and payroll needs the name printed on the payslip,
 * which is often not the name they go by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // FT, PT, FT_SALARY, SUB, LEAD. Drives default weekly hours and
            // decides who gets pulled in to cover a ratio gap.
            $table->string('employment')->nullable()->after('role');

            // The room this person leads, distinct from `classrooms`, which is
            // the wider set they are allowed to see.
            $table->string('title')->nullable()->after('employment');

            // Payroll prints the legal name, so that is what a payslip page has
            // to be matched on — "Maria G. Santos", not "Maria Santos".
            $table->string('legal_name')->nullable()->after('title');

            $table->string('phone')->nullable()->after('legal_name');
            $table->string('emergency_contact')->nullable()->after('phone');
            $table->string('emergency_phone')->nullable()->after('emergency_contact');
            $table->date('start_date')->nullable()->after('emergency_phone');
            $table->date('dob')->nullable()->after('start_date');
            $table->string('transport')->nullable()->after('dob');
            $table->string('aspire_id')->nullable()->after('transport');
            $table->boolean('direct_deposit')->default(false)->after('aspire_id');

            // Decimal rather than encrypted: it is reported on and summed, and
            // every route that exposes it is already behind role:admin.
            $table->decimal('pay_rate', 8, 2)->nullable()->after('direct_deposit');

            $table->decimal('evaluation_score', 3, 1)->nullable()->after('pay_rate');
            $table->text('staff_notes')->nullable()->after('evaluation_score');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'employment', 'title', 'legal_name', 'phone', 'emergency_contact',
                'emergency_phone', 'start_date', 'dob', 'transport', 'aspire_id',
                'direct_deposit', 'pay_rate', 'evaluation_score', 'staff_notes',
            ]);
        });
    }
};
