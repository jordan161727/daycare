<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A child only has sign-in boxes between these two dates. Both null means
     * "has always been here and still is", which is how existing records behave.
     */
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            if (! Schema::hasColumn('children', 'enrolled_on')) {
                $table->date('enrolled_on')->nullable()->after('status');
            }

            if (! Schema::hasColumn('children', 'withdrawn_on')) {
                $table->date('withdrawn_on')->nullable()->after('enrolled_on');
            }
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn(['enrolled_on', 'withdrawn_on']);
        });
    }
};
