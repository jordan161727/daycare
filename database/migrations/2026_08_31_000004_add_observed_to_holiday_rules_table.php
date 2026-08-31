<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a holiday landing at the weekend moves to the next working day.
     *
     * This is how a statutory calendar actually behaves: Christmas on a Saturday
     * does not stop being a holiday, it is observed on the Monday. Without it a
     * centre gets no Christmas closure at all in those years, which is worse
     * than wrong — it is silently wrong, in the one direction nobody checks.
     *
     * Only fixed dates need it. A rule built on "first Monday in September"
     * lands on a Monday by construction, and Good Friday is always a Friday.
     */
    public function up(): void
    {
        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->boolean('observed')->default(true)->after('offset_days');
        });
    }

    public function down(): void
    {
        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->dropColumn('observed');
        });
    }
};
