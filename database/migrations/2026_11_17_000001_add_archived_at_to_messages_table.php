<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message can be archived — kept, but off every thread — rather than
 * deleted (data cleanup, Nov 2026: test messages "test", "zxczxcxz").
 * App\Models\Message reads it as its soft-delete column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
