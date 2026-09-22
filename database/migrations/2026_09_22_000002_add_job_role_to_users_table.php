<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody does, as distinct from what they may open.
 *
 * `role` is the account: admin or teacher, and it decides which screens they
 * see. It has never been the job — a Lead Teacher, an Assistant, a Floater and
 * a Cook are all `teacher` as far as the app's permissions go, and telling them
 * apart is a rota question, not a security one.
 *
 * With nowhere to put the job, screens that wanted one reached for `title` —
 * which is the room they lead, chosen from the room list — so every staff table
 * showed "UPK-4" in a column headed Role. This is the column those screens
 * should have been reading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_role')->nullable()->after('role')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('job_role');
        });
    }
};
