<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change a data cleanup command makes, one row per field: what it
 * was, what it became, and when it was undone — so `--undo=<batch>` can put
 * it back exactly (data:cleanup-2026-11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_cleanup_log', function (Blueprint $table) {
            $table->id();
            $table->string('batch', 40)->index();
            $table->string('item', 60);
            $table->string('table_name', 60);
            $table->unsignedBigInteger('record_id');
            $table->string('field', 60);
            $table->longText('old_value')->nullable();
            $table->longText('new_value')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('run_by', 120)->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_cleanup_log');
    }
};
