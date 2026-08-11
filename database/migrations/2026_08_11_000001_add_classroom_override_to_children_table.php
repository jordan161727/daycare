<?php

use App\Services\ClassroomAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            // The room the director chose by hand, and the date it starts from.
            // A null date means "always" — it is how the backfill below records
            // a room that was already in force before this rule existed.
            $table->string('classroom_override')->nullable()->after('classroom');
            $table->date('classroom_override_from')->nullable()->after('classroom_override');

            // classroom stops being something a human types and becomes the
            // worked-out answer. A date of birth outside every band has no
            // answer, so the column has to be able to say so.
            $table->string('classroom')->nullable()->change();
        });

        $this->preserveRoomsTheRuleWouldMove();
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn(['classroom_override', 'classroom_override_from']);
        });
    }

    /**
     * Nobody changes room because this migration ran.
     *
     * Every child on file was put in their room by hand, and for some the age
     * rule now disagrees — a child moved up early, a missing date of birth, a
     * room that is not one of the six. Letting the rule win would move children
     * between rooms overnight and take them out of their own teacher's view.
     *
     * So a room the rule would not have chosen is kept, recorded as an override.
     * It shows up coloured on the sheet with the automatic value on hover, which
     * turns a silent disagreement into one the director can see and clear.
     */
    private function preserveRoomsTheRuleWouldMove(): void
    {
        $today = Carbon::today();

        foreach (DB::table('children')->select('id', 'classroom', 'birth_date', 'dob')->cursor() as $child) {
            $current = trim((string) $child->classroom);
            $birthDate = $child->birth_date ?? $child->dob;
            $automatic = ClassroomAssignment::automaticFor($birthDate ? Carbon::parse($birthDate) : null, $today);

            if ($current === '') {
                DB::table('children')->where('id', $child->id)->update(['classroom' => $automatic]);

                continue;
            }

            if ($current === $automatic) {
                continue;
            }

            DB::table('children')->where('id', $child->id)->update([
                'classroom_override' => $current,
                'classroom_override_from' => null,
            ]);
        }
    }
};
