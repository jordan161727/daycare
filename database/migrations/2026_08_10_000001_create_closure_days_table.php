<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Days the centre is shut — holidays, snow days. One row closes the day for
     * every room at once, which is the point: a closure is a fact about the
     * centre, not something to tick off child by child.
     */
    public function up(): void
    {
        Schema::create('closure_days', function (Blueprint $table) {
            $table->id();
            $table->date('closed_on')->unique();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closure_days');
    }
};
