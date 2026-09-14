<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which days of the week a child is registered to attend.
 *
 * The hours were already on the record — drop_off_time and pick_up_time say
 * when in the day a child is here — but nothing said *which days*, so a
 * Monday-Wednesday-Friday child and a full-week child were the same record
 * until somebody ticked the difference into a particular week by hand. That
 * made a newly enrolled child's first week blank, every week, until a person
 * noticed.
 *
 * This is the standing arrangement agreed at registration. It is not the
 * week's schedule: a week is still ticked, closed and corrected on its own,
 * and a holiday or a one-off change never reaches back to this. It is only
 * what a week starts from when there is nothing to copy forward.
 *
 * Stored as a list of ISO weekday numbers — [1,3,4,5] is Mon, Wed, Thu, Fri.
 * Null and [] are different answers on purpose: null is "nobody has said",
 * which is every record that predates this column, and [] is "no days", which
 * is a deliberate statement about a child who is on the roll but not attending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->json('schedule_days')->nullable()->after('pick_up_time');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn('schedule_days');
        });
    }
};
