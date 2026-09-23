<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a family found the centre.
 *
 * Two questions the registration form has always asked on paper — where they
 * saw it advertised, and who told them about it — and which had nowhere to go
 * when the form was typed up. A centre that cannot say which of its adverts
 * brought children in is a centre guessing at where to spend next year's
 * advertising.
 *
 * Free text on purpose. "A friend at church", "the sign on Elm Road" and
 * "Google" are all real answers, and a dropdown of them would be somebody's
 * guess at a list that changes every year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('where_advertised')->nullable()->after('alerts');
            $table->string('who_referred')->nullable()->after('where_advertised');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn(['where_advertised', 'who_referred']);
        });
    }
};
