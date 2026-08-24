<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A password the account holder has not chosen yet.
 *
 * New staff accounts are opened by the director, so the first password is one
 * somebody else typed and emailed. It is a shared secret until the teacher
 * replaces it, which is what `must_change_password` is for: the account works,
 * but every page redirects to the change form until they have picked their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
