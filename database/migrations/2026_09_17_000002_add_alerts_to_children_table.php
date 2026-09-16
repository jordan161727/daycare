<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The things a room has to know about a child before the day starts.
 *
 * There was already a free-text box on the record — "Allergies, medication,
 * court orders" — and it stays, because a paragraph is the right shape for
 * what an allergy actually needs said about it. What it could not do is be
 * read at a glance down a roll of sixty: "no nuts" and "collected by father on
 * Fridays" and "asthma pump in the office" all arrive as the same grey
 * sentence, so the roster showed none of them and a teacher covering a room
 * they do not usually have found out by opening records one at a time.
 *
 * These are the same facts in the shape a list can carry: a kind and a line.
 * The kind is what gives the chip its colour, so a court order does not have
 * to be read to be noticed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            // Null is "nobody has said", the same third state the rest of this
            // record uses, and an empty array is "asked and there are none".
            $table->json('alerts')->nullable()->after('important_notes');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn('alerts');
        });
    }
};
