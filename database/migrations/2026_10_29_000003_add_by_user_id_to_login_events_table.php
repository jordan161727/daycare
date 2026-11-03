<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did it, when it was not the account holder.
 *
 * A director sending somebody a new temporary password is written to login
 * history next to that person's sign-ins — it is the reason the next sign-in
 * looks different — and the row has to say which director pressed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            $table->foreignId('by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('by_user_id');
        });
    }
};
