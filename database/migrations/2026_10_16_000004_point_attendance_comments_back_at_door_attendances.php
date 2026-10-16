<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The door reads its own tables again.
 *
 * Earlier today (16 Oct 2026) the door was pointed back at the register's
 * tables and the comments' foreign key moved with it. The split is wanted
 * after all: Director Attendance (attendances) and Teacher Attendance
 * (door_attendances) are two records that do not sync. The models point at
 * the door_* tables again, and this brings the comments' key back with them.
 *
 * A comment written while the tables were shared sits on an attendances row.
 * It is carried over rather than lost: the same child, day and session is
 * found on the door — or copied onto it from the register row, the way
 * attendance:copy-register-to-door does — and the comment is re-pointed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->carryCommentsOntoTheDoor();

        Schema::table('attendance_comments', function (Blueprint $table) {
            $table->dropForeign(['attendance_id']);
            $table->foreign('attendance_id')->references('id')->on('door_attendances')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_comments', function (Blueprint $table) {
            $table->dropForeign(['attendance_id']);
            $table->foreign('attendance_id')->references('id')->on('attendances')->cascadeOnDelete();
        });
    }

    private function carryCommentsOntoTheDoor(): void
    {
        $stranded = DB::table('attendance_comments as c')
            ->leftJoin('door_attendances as d', 'd.id', '=', 'c.attendance_id')
            ->whereNull('d.id')
            ->select('c.id', 'c.attendance_id')
            ->get();

        foreach ($stranded as $comment) {
            $row = DB::table('attendances')->find($comment->attendance_id);

            if (! $row) {
                // Nothing to hang it on in either record.
                DB::table('attendance_comments')->where('id', $comment->id)->delete();

                continue;
            }

            $doorId = DB::table('door_attendances')
                ->where('child_id', $row->child_id)
                ->whereDate('attendance_date', $row->attendance_date)
                ->where('session', $row->session)
                ->value('id');

            if (! $doorId) {
                $doorId = DB::table('door_attendances')->insertGetId([
                    'child_id' => $row->child_id,
                    'attendance_date' => $row->attendance_date,
                    'session' => $row->session,
                    'signed_in_at' => $row->signed_in_at,
                    'signed_out_at' => $row->signed_out_at,
                    'health_in_code' => $row->health_in_code,
                    'health_in_note' => $row->health_in_note,
                    'health_out_code' => $row->health_out_code,
                    'health_out_note' => $row->health_out_note,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }

            DB::table('attendance_comments')->where('id', $comment->id)->update(['attendance_id' => $doorId]);
        }
    }
};
