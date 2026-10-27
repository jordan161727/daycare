<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity feed behind the kiosks, and the flags for the office.
 *
 * Four kinds of line (HANDOFF §5.3.1): a payroll punch, a room move, a flag
 * the office should act on, and a tablet being told which room it is in.
 * The punches and the check-ins are already in their own tables; this is
 * the one place they are told as a story, newest first, in the words the
 * office reads — and it is the only home a flag has.
 *
 * A flag is a line with a resolved_at. "Sultana skipped the front door —
 * office to confirm start time" stays red until somebody in the office has
 * looked at it, and the looking is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_events', function (Blueprint $table) {
            $table->id();

            // 'punch', 'room', 'flag' or 'tablet'.
            $table->string('kind', 20);

            // Tablet set-up lines have no person; everything else does.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The room the line is about, so a room move can wear its colour
            // and a short-room flag can be found by room.
            $table->string('classroom')->nullable();

            $table->foreignId('staff_device_id')->nullable()->constrained('staff_devices')->nullOnDelete();

            $table->string('text', 300);
            $table->timestamp('occurred_at');

            // Flags only: who in the office saw to it, and when.
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('occurred_at');
            $table->index(['kind', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_events');
    }
};
