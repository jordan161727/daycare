<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How this person likes to read a child's name.
 *
 * A preference rather than a centre-wide setting: the office works from
 * surnames because that is how the paper file is ordered, and the room works
 * from first names because that is what a child answers to. Both are reading
 * the same roster, and neither is wrong — so it is stored per user and nobody
 * changes anybody else's screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable rather than defaulted, so "never chose" and "chose the
            // default" stay distinguishable — the day the centre wants its own
            // house style, the people who never expressed a view can follow it.
            $table->string('name_format')->nullable()->after('classrooms');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name_format');
        });
    }
};
