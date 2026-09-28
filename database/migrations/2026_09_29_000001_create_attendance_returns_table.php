<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A child who left and came back the same day.
 *
 * An attendance row holds one arrival and one departure a session, and DSS
 * bills from those two: first in, last out. A child collected for a dental
 * appointment at eleven and dropped off again at one is the same day, not a
 * second one — but the hours out of the room were not care given, and the
 * register has to be able to say so.
 *
 * So the row keeps the first arrival and the latest departure, and every time
 * the child comes back the pair it closes — left at, returned at — is written
 * down here. The row's own departure is cleared while the child is in the
 * room again, exactly as it is empty before the first departure, so every
 * screen that reads "no out time" as "still here" goes on being right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_returns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attendance_id')->constrained('attendances')->cascadeOnDelete();

            // The departure this return closed, and the moment of coming back.
            $table->dateTime('left_at');
            $table->dateTime('returned_at');

            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_returns');
    }
};
