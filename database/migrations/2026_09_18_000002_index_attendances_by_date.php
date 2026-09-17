<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index on the column the sheet actually asks about.
 *
 * Every read of the register is "the sign-ins between this Monday and this
 * Friday" — a range on attendance_date, with no child named. The indexes on the
 * table were (child_id, attendance_date, session) and (child_id): both lead
 * with child_id, and an index cannot be used for a range on its second column
 * when the first is unconstrained. So that query read the whole table.
 *
 * It does not hurt yet — a centre of sixty writes about thirteen thousand rows
 * a year, and scanning that is quick. It gets slower every week for as long as
 * the centre runs, which is the kind of slowness nobody attributes to the right
 * cause two years later.
 *
 * Date first, child second: it serves the week query, and also "this child's
 * attendance between two dates", which the projection asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['attendance_date', 'child_id'], 'attendances_date_child_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_date_child_index');
        });
    }
};
