<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A few words on how the child looks.
 *
 * "Blonde long girl", "brunet short hair boy": the note the centre's own
 * sheet keeps beside the gender column, so a face on the register can be
 * told from the next one before a photo is on file. Free text, the way the
 * sheet writes it, and nullable for every child who predates the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('description')->nullable()->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
