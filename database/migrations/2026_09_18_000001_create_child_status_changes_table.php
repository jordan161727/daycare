<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a child started, when they stopped, and who said so.
 *
 * The record already carries enrolled_on and withdrawn_on, but those are two
 * boxes on a form: they say what somebody intended, they are only right if
 * somebody remembered to fill them in, and they hold one answer each. A child
 * who leaves in June and comes back in September has one withdrawal date and
 * no way to say they returned.
 *
 * This is the other half of that — not what was planned but what happened, and
 * every time it happened. Append-only, like the attendance amendments beside
 * it: a history that can be rewritten is not one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();

            // Null on the first row: a child added to the roll came from
            // nothing, which is a different fact from having been Inactive.
            $table->string('from_status')->nullable();
            $table->string('to_status');

            // Null when nobody was signed in — a seeder, an import, a command
            // run from the shell. The row still stands; "we do not know who"
            // is worth recording and is not the same as nobody having done it.
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Every read is one child's history, newest first.
            $table->index(['child_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_status_changes');
    }
};
