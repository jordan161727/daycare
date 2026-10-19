<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A comment is a note on a day, and the day is now written on it.
 *
 * Until now a comment knew its day only through the attendance row it hangs
 * on. Reading a child's earlier comments — Friday's note, read on Monday —
 * means listing them by day without walking door_attendances, and the log
 * page lists a whole day's notes across every child. So the attendance day
 * is copied onto the comment, with who wrote it as they were that day
 * (author_role), when it was changed (edited_at) and a soft delete the
 * screens never offer but a director's tooling may.
 *
 * `type` is the existing tag column read three ways — health, incident,
 * and everything else as general — see AttendanceComment::type().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_comments', function (Blueprint $table) {
            $table->date('date')->nullable()->after('child_id');
            $table->string('author_role', 20)->nullable()->after('user_id');
            $table->timestamp('edited_at')->nullable()->after('body');
            $table->softDeletes();

            $table->index(['child_id', 'date']);
            $table->index('date');
        });

        // The rows already written: the day is the row's, the role the author's now.
        DB::table('attendance_comments as c')
            ->join('door_attendances as d', 'd.id', '=', 'c.attendance_id')
            ->select('c.id', 'd.attendance_date')
            ->orderBy('c.id')
            ->get()
            ->each(fn ($row) => DB::table('attendance_comments')->where('id', $row->id)->update([
                'date' => substr((string) $row->attendance_date, 0, 10),
            ]));

        DB::table('attendance_comments as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->select('c.id', 'u.role')
            ->get()
            ->each(fn ($row) => DB::table('attendance_comments')->where('id', $row->id)->update(['author_role' => $row->role]));

        DB::table('attendance_comments')->whereColumn('updated_at', '>', 'created_at')->update(['edited_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('attendance_comments', function (Blueprint $table) {
            $table->dropIndex(['child_id', 'date']);
            $table->dropIndex(['date']);
            $table->dropColumn(['date', 'author_role', 'edited_at', 'deleted_at']);
        });
    }
};
