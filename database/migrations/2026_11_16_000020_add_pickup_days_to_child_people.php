<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pick-up on some days only (QA probe D14): "grandma collects on Thursdays".
 * A comma list of short weekday names ("Mon,Thu"); null means every day,
 * which is every link already on file. Read by ChildPerson::allowsPickup()
 * and the allowedToCollect scope, so the door and the kiosk both honour it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('child_people', 'pickup_days')) {
            return;
        }

        Schema::table('child_people', function (Blueprint $table) {
            $table->string('pickup_days', 40)->nullable()->after('can_pickup');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('child_people', 'pickup_days')) {
            return;
        }

        Schema::table('child_people', function (Blueprint $table) {
            $table->dropColumn('pickup_days');
        });
    }
};
