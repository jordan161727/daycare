<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The blue/gray boxes: whether a child is expected on one day, in one session.
     * Rows are physical per week, which is what keeps each week independent of
     * the one it was copied from.
     */
    public function up(): void
    {
        Schema::create('schedule_slots', function (Blueprint $table) {
            $table->id();
            $table->date('week_start')->index();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->date('slot_date');
            $table->string('session', 8)->default('FULL');
            $table->boolean('is_scheduled')->default(false);
            $table->timestamps();

            $table->unique(['child_id', 'slot_date', 'session']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_slots');
    }
};
