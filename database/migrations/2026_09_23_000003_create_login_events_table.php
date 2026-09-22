<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who signed in, from where, and who tried and failed.
 *
 * The failures are the reason this exists. A successful login is a line in a
 * list; six failures against one address at two in the morning is the thing a
 * director needs to be able to see, and until now nothing in the app recorded
 * it at all.
 *
 * The email is stored as typed rather than only as a user id, because a
 * failure often names an account that does not exist — and "somebody is trying
 * addresses" is exactly what that pattern looks like.
 *
 * No password is recorded, in any form, successful or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();

            // Null on a failure against an address with no account behind it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('outcome', 20)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
