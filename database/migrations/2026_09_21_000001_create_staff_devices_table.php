<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tablets and terminals staff punch at.
 *
 * Registered rather than assumed, for the same reason a punch records who made
 * it: "clocked in at 7:52" is a weaker fact than "clocked in at 7:52 at the
 * front desk". When a morning's punches are all disputed it is nearly always
 * one device — a clock drifting, a tablet somebody moved, a screen left on the
 * wrong page — and without a name on each punch there is no way to see that.
 *
 * The child kiosk names its device from config, one string for the whole
 * centre. That was enough while there was one door; a centre with a classroom
 * iPad per room needs them told apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_devices', function (Blueprint $table) {
            $table->id();

            // What it is called on the wall it is screwed to. This is what a
            // director reads on a punch six weeks later, so it is a place
            // rather than a serial number: "Front desk", "Infant room iPad".
            $table->string('name');
            $table->string('location')->nullable();

            /*
             * What the device proves itself with.
             *
             * A kiosk is a screen anybody can walk up to, so the device is not
             * a secret — but the pairing is. The token goes into the URL once,
             * when the tablet is set up, and is kept in its session afterwards.
             * Hashed, because a table of live tokens is a table of ways to
             * open a kiosk from anywhere.
             */
            $table->string('token_hash');
            $table->string('token_last4', 8)->nullable();

            // Turned off without being deleted: a device that has been retired
            // still has to be nameable on the punches it recorded.
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_seen_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_devices');
    }
};
