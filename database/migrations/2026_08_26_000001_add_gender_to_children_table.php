<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the child is a girl or a boy.
 *
 * Added because the drawn avatar was guessing. A stand-in face is picked from
 * the child's name and number, which decides a hairstyle by arithmetic — so a
 * girl was as likely as not to be drawn with a boy's crop. There is no way to
 * read this off a name reliably in any language, so the record holds it or the
 * face stays neutral.
 *
 * Nullable, and it stays nullable: every child already on file predates the
 * question, and "nobody has said" is a real answer rather than a gap to guess
 * at. Nothing but the drawing reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('gender', 20)->nullable()->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
