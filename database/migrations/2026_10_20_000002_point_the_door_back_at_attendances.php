<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One record again: the door reads and writes the register's tables.
 *
 * Teacher Attendance got tables of its own on 16 Oct 2026 (door_attendances
 * and friends) so that it and Director Attendance would not sync. On 20 Oct
 * the centre asked for them back on one table — a clock-in at the door is
 * the arrival on the register, as it was before. The models point at
 * attendances, attendance_returns and health_audits again; this carries what
 * the door recorded in the meantime across, and moves the comments' foreign
 * key with it.
 *
 *  - A door row with no register row for the same child, day and session is
 *    copied onto the register, with its trips out and back.
 *  - A door row that duplicates a register row is dropped: the register's
 *    times stand, as the paper form does.
 *  - Every comment is re-pointed from its door row to the register row for
 *    the same child, day and session.
 *
 * The door_* tables are left standing but are no longer read or written.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The old key first, or MySQL refuses to re-point a comment at a
        // register row while the key still says door_attendances.
        Schema::table('attendance_comments', function (Blueprint $table) {
            $table->dropForeign(['attendance_id']);
        });

        $this->carryTheDoorOntoTheRegister();

        Schema::table('attendance_comments', function (Blueprint $table) {
            $table->foreign('attendance_id')->references('id')->on('attendances')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_comments', function (Blueprint $table) {
            $table->dropForeign(['attendance_id']);
            $table->foreign('attendance_id')->references('id')->on('door_attendances')->cascadeOnDelete();
        });
    }

    private function carryTheDoorOntoTheRegister(): void
    {
        if (! Schema::hasTable('door_attendances')) {
            return;
        }

        $map = [];   // door id → register id

        foreach (DB::table('door_attendances')->orderBy('id')->cursor() as $door) {
            $register = DB::table('attendances')
                ->where('child_id', $door->child_id)
                ->whereDate('attendance_date', $door->attendance_date)
                ->where('session', $door->session)
                ->first();

            if ($register) {
                $map[$door->id] = $register->id;

                continue;
            }

            $id = DB::table('attendances')->insertGetId([
                'child_id' => $door->child_id,
                'attendance_date' => $door->attendance_date,
                'session' => $door->session,
                'signed_in_at' => $door->signed_in_at,
                'signed_out_at' => $door->signed_out_at,
                'health_in_code' => $door->health_in_code,
                'health_in_note' => $door->health_in_note,
                'health_out_code' => $door->health_out_code,
                'health_out_note' => $door->health_out_note,
                'created_at' => $door->created_at,
                'updated_at' => $door->updated_at,
            ]);
            $map[$door->id] = $id;

            if (Schema::hasTable('door_attendance_returns')) {
                foreach (DB::table('door_attendance_returns')->where('attendance_id', $door->id)->get() as $trip) {
                    DB::table('attendance_returns')->insert([
                        'attendance_id' => $id,
                        'left_at' => $trip->left_at,
                        'returned_at' => $trip->returned_at,
                        'performed_by' => $trip->performed_by,
                        'created_at' => $trip->created_at,
                        'updated_at' => $trip->updated_at,
                    ]);
                }
            }

            if (Schema::hasTable('door_health_audits')) {
                foreach (DB::table('door_health_audits')->where('attendance_id', $door->id)->get() as $audit) {
                    DB::table('health_audits')->insert([
                        'attendance_id' => $id,
                        'direction' => $audit->direction,
                        'old_code' => $audit->old_code,
                        'new_code' => $audit->new_code,
                        'note' => $audit->note,
                        'user_id' => $audit->user_id,
                        'created_at' => $audit->created_at,
                    ]);
                }
            }
        }

        // The comments follow their rows. One that points at a door row the
        // register cannot place has nothing to hang on, and goes.
        $comments = DB::table('attendance_comments')->select('id', 'attendance_id')->get();
        foreach ($comments as $comment) {
            if (DB::table('attendances')->where('id', $comment->attendance_id)->exists() && ! isset($map[$comment->attendance_id])) {
                continue;   // already on a register row
            }
            $target = $map[$comment->attendance_id] ?? null;
            if ($target === null) {
                DB::table('attendance_comments')->where('id', $comment->id)->delete();

                continue;
            }
            DB::table('attendance_comments')->where('id', $comment->id)->update(['attendance_id' => $target]);
        }
    }
};
