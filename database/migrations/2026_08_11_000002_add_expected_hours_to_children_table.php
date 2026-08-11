<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The hours a child is contracted for in a normal week — what the parent
     * signed up for and what DSS authorises, as opposed to the days they
     * actually turned up.
     *
     * Null means nobody has said, which is how every record behaves today: the
     * projection then reports the days it can see and claims nothing about how
     * many there should be. Zero is a different statement — contracted for
     * nothing — and suppresses the projection outright.
     */
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            if (! Schema::hasColumn('children', 'expected_hours_per_week')) {
                $table->decimal('expected_hours_per_week', 5, 2)->nullable()->after('withdrawn_on');
            }
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn('expected_hours_per_week');
        });
    }
};
