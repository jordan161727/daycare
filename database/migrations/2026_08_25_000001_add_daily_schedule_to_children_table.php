<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The hours a child is contracted for on a normal day, which is a different
     * fact from the days they come — those are the schedule boxes on the
     * attendance page. Nullable because plenty of records predate the question
     * being asked, and a blank pair means nobody has written the times down.
     */
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->time('drop_off_time')->nullable()->after('expected_hours_per_week');
            $table->time('pick_up_time')->nullable()->after('drop_off_time');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn(['drop_off_time', 'pick_up_time']);
        });
    }
};
