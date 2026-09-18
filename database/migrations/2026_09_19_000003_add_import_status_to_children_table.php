<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the people on this child's record have been looked at by a person.
 *
 * A scanned enrollment form gives four or five adults, some of whom are
 * already on file under a slightly different spelling. The import makes its
 * best guess and marks the child needs_review; confirming the guesses on the
 * review screen sets it back to ok. Without it there is no way to tell, a week
 * later, which records were read by somebody and which were read by a model.
 *
 * Every existing child is ok: they were all typed in by hand.
 *
 * nickname and parents_status are already on this table — the scope document
 * listed them as new, but they were added with the enrollment fields long ago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('import_status')->default('ok');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn('import_status');
        });
    }
};
