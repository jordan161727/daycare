<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The settings a director may change without a deploy.
 *
 * Everything configurable in this app lives in config/daycare.php, which is a
 * PHP file with a paragraph of reasoning above each value — and nothing in the
 * app can write to it. That is the right home for a default: the reasoning
 * belongs beside the number, and a value nobody has overridden should read the
 * same in every environment.
 *
 * This table holds overrides and nothing else. A key absent here falls back to
 * the config file, so removing a row is how a setting goes back to its
 * documented default, and the file stays the single description of what each
 * setting means.
 *
 * Deliberately a key/value table rather than a columns-per-setting one: adding
 * a setting should not be a migration, and every value here is small and read
 * as a whole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            // Text, and cast on the way out by whoever asks. A setting is read
            // far more often than written, and the caller always knows the
            // shape it wants; a JSON column would buy nothing for a boolean.
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
