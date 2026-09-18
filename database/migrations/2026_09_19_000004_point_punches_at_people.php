<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who collected the child, as a person rather than a guardian.
 *
 * The door's own table of adults has moved into people, so the record of who
 * pressed the button has to follow it — otherwise the punch log points at rows
 * that nothing writes to any more, and "who took this child home on the 14th"
 * becomes a question with no answer.
 *
 * guardian_id is kept beside it for now, filled and untouched, for the same
 * reason the child's old contact columns are: a week of being able to read the
 * old answer next to the new one is worth more than a tidy table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_attendance_punches', function (Blueprint $table) {
            // Nullable and nulled on delete rather than cascading: a punch is a
            // record of something that happened, and deleting a person years
            // later must not delete the fact that they collected a child.
            $table->foreignId('person_id')->nullable()->after('guardian_id')
                ->constrained('people')->nullOnDelete();
        });

        // Match each old punch to the person its guardian became. The PIN is
        // what identifies them: the backfill carried it across untouched, and
        // two adults cannot share one hash.
        foreach (DB::table('guardians')->get() as $guardian) {
            $personId = DB::table('people')->where('pin_hash', $guardian->pin_hash)->value('id');

            if ($personId === null) {
                continue;
            }

            DB::table('child_attendance_punches')
                ->where('guardian_id', $guardian->id)
                ->update(['person_id' => $personId]);
        }
    }

    public function down(): void
    {
        Schema::table('child_attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_id');
        });
    }
};
