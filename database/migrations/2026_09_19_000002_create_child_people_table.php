<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one adult is for one child.
 *
 * The person is held once in `people`; everything that is true of them only in
 * relation to a particular child is held here. A mother of two is one person
 * row and two of these, and she can be a legal guardian of one child and
 * merely an emergency number for the other without either fact touching the
 * other.
 *
 * The four flags are independent and nothing infers one from another. Being a
 * guardian does not imply being allowed to collect, and being allowed to
 * collect does not imply being an emergency contact — every one of those pairs
 * comes apart in practice, and a centre that guesses gets it wrong at the door.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_people', function (Blueprint $table) {
            $table->id();

            $table->foreignId('child_id')->constrained()->cascadeOnDelete();

            // Restricted, not cascading: a person linked to a child is not one
            // anybody may delete out from under them. Unlink first — which is
            // the deliberate act, and the one that leaves the record behind for
            // the other children they belong to.
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();

            // Mother, Father, Stepparent, Grandmother, Grandfather, Aunt/Uncle,
            // Nanny, Family friend, Other. Free text rather than an enum: it is
            // what somebody wrote on a form, and the list grows.
            $table->string('relationship')->nullable();

            $table->boolean('is_guardian')->default(false);
            $table->boolean('can_pickup')->default(false);
            $table->boolean('is_emergency')->default(false);

            // Call order, and only meaningful with is_emergency. Unique per
            // child and renumbered 1, 2, 3 on save so the list never has gaps.
            $table->unsignedInteger('priority')->nullable();

            /*
             * Why this person must not collect this child — a court order,
             * most often. Any text here overrides can_pickup: the pick-up list
             * is can_pickup AND restriction IS NULL, so a tick left on by
             * mistake cannot quietly readmit somebody a court has excluded.
             * Kept as text rather than a flag because the reason is what the
             * person at the door needs to be shown.
             */
            $table->text('restriction')->nullable();

            // manual, pdf_import, or migration for the rows this table was
            // born with. Worth keeping: a link nobody typed is one to look at
            // twice when it turns out to be wrong.
            $table->string('source')->default('manual');

            $table->timestamps();

            // One link per pair. Two rows for the same adult and child would be
            // two answers to whether they may collect.
            $table->unique(['child_id', 'person_id']);

            // Every read is "this child's people"; the lists then filter on the
            // flags, which is cheap once the child's handful of rows are in hand.
            $table->index(['child_id', 'is_emergency', 'priority']);
            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_people');
    }
};
