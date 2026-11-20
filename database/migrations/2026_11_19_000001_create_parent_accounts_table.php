<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parent app accounts (19 Nov 2026). After a parent's first sign-in with a
 * texted code, they choose a username and password and, if they like, a
 * photo and the name the app shows. Kept beside the Person, not on it: the
 * contact record is the office's, and a parent writes only their own row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->unique()->constrained('people')->cascadeOnDelete();
            $table->string('username', 30)->unique(); // stored lower-case
            $table->string('password');
            $table->string('display_name', 60)->nullable();
            $table->string('email')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamp('profile_done_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_accounts');
    }
};
