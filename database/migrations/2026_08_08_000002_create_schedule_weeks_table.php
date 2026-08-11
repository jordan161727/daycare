<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per week that has been opened. Its existence is the record that the
     * week was built; copied_from_week_start is the provenance shown in the banner.
     */
    public function up(): void
    {
        Schema::create('schedule_weeks', function (Blueprint $table) {
            $table->id();
            $table->date('week_start')->unique();
            $table->date('copied_from_week_start')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_weeks');
    }
};
