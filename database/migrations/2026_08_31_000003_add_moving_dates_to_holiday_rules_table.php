<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holidays that land on a different date every year.
     *
     * A fixed month and day covers Christmas and Canada Day and nothing else.
     * Half of a real statutory calendar moves: Labour Day is the first Monday
     * in September, Victoria Day the Monday before 25 May, Good Friday two days
     * before an Easter that is itself computed. Those cannot be written down as
     * a date, only as the rule that finds one.
     *
     * So the row stops being a date and becomes one of four kinds of rule, and
     * month and day become the arguments some of them happen to take.
     */
    public function up(): void
    {
        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->string('type')->default('fixed')->after('id');
            // 1 = Monday to 7 = Sunday, matching Carbon's ISO weekday.
            $table->unsignedTinyInteger('weekday')->nullable()->after('day');
            // Which one in the month: 1st, 2nd… or -1 for the last.
            $table->tinyInteger('nth')->nullable()->after('weekday');
            // Days from Easter Sunday. Good Friday is -2, Easter Monday +1.
            $table->smallInteger('offset_days')->nullable()->after('nth');
            // Set on seeded holidays so re-running the seeder updates the rule
            // it wrote last time instead of adding a second one beside it.
            $table->string('key')->nullable()->unique()->after('reason');
        });

        // An Easter rule has no month, and a nth-weekday rule has no day. The
        // old unique pair goes with them: Victoria Day and Quebec's National
        // Patriots' Day are two holidays on one date, and both are real.
        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->dropUnique(['month', 'day']);
            $table->unsignedTinyInteger('month')->nullable()->change();
            $table->unsignedTinyInteger('day')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->dropColumn(['type', 'weekday', 'nth', 'offset_days', 'key']);
        });
    }
};
