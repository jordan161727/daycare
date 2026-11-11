<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The state in the child's home address (Jordan, 6 Nov 2026): the record had
 * a street, a city and a zip and nowhere to say which state. Two letters, the
 * postal code — blank on every record that predates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('state', 2)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn('state');
        });
    }
};
