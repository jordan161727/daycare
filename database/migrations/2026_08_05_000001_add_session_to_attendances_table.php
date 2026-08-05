<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('attendances', 'session')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->string('session')->default('FULL')->after('attendance_date');
            });
        }

        $indexes = collect(DB::select("SHOW INDEX FROM attendances"))->pluck('Key_name')->unique();

        Schema::table('attendances', function (Blueprint $table) use ($indexes) {
            if (! $indexes->contains('attendances_child_id_index')) {
                $table->index('child_id');
            }

            if ($indexes->contains('attendances_child_id_attendance_date_unique')) {
                $table->dropUnique('attendances_child_id_attendance_date_unique');
            }

            if (! $indexes->contains('attendances_child_id_attendance_date_session_unique')) {
                $table->unique(['child_id', 'attendance_date', 'session']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('attendances', 'session')) {
            return;
        }

        $indexes = collect(DB::select("SHOW INDEX FROM attendances"))->pluck('Key_name')->unique();

        Schema::table('attendances', function (Blueprint $table) use ($indexes) {
            if ($indexes->contains('attendances_child_id_attendance_date_session_unique')) {
                $table->dropUnique('attendances_child_id_attendance_date_session_unique');
            }

            if (! $indexes->contains('attendances_child_id_attendance_date_unique')) {
                $table->unique(['child_id', 'attendance_date']);
            }

            if ($indexes->contains('attendances_child_id_index')) {
                $table->dropIndex('attendances_child_id_index');
            }

            $table->dropColumn('session');
        });
    }
};
