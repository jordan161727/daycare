<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The time a re-enrolled child was away.
 *
 * Re-enrolling clears the last day so the child is back on the roll, which
 * left nothing to say that the weeks between the first period's last day and
 * the re-enrollment were not enrolled: the DSS grid and the register read
 * them as enrolled days. Each re-enrollment now writes the gap here, as
 * [{"from": "Y-m-d", "to": "Y-m-d"}, ...]; see Child::isEnrolledOn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('children', 'enrollment_gaps')) {
            return;
        }

        Schema::table('children', function (Blueprint $table) {
            $table->json('enrollment_gaps')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('children', 'enrollment_gaps')) {
            Schema::table('children', function (Blueprint $table) {
                $table->dropColumn('enrollment_gaps');
            });
        }
    }
};
