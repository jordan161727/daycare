<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an emailed temporary password stops working.
 *
 * A password sitting in an inbox is a password anybody with that inbox can
 * use, so the one a director sends from a staff profile has a shelf life.
 * Null means no limit: the first password typed in on the new-teacher form is
 * handed over in person, and stays as it always was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('temp_password_expires_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('temp_password_expires_at');
        });
    }
};
