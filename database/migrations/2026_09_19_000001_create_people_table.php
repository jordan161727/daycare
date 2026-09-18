<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every adult connected to a child, stored once.
 *
 * Until now an adult existed only as a block of columns on one child's row —
 * mother_name and the nine beside it, a pick-up slot, an emergency line. A
 * mother with two children at the centre was typed in twice, and correcting
 * her telephone number meant remembering that. This is that record, held once,
 * with child_people saying what she is for each child.
 *
 * The PIN columns at the bottom are the door kiosk's. They were on a separate
 * `guardians` table, which was the same idea reached from the other end: an
 * adult, linked to children, with a flag for whether they may collect. Two
 * tables answering "who may take this child home" is one more than the centre
 * can keep straight, so the kiosk's people move in here alongside the office's.
 * The kiosk keeps reading `guardians` until the screens are built; nothing
 * writes here yet, so the two cannot drift in the meantime.
 *
 * Nearly every field is nullable, including the cell. An emergency contact
 * copied off a paper form is frequently a name and nothing else, and a record
 * that refuses to hold what the form actually said is a record somebody works
 * around. Required-ness belongs on the screens that create people by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Null means "same as the child's household", resolved per child
            // when it is shown. Never copied in: the household address lives on
            // the child, and a copy would be the thing that goes stale.
            $table->string('address')->nullable();

            $table->string('home_phone')->nullable();
            $table->string('work_phone')->nullable();
            $table->string('cell')->nullable();

            // The second number on a pick-up block of the paper form.
            $table->string('alternate_phone')->nullable();

            $table->string('fax')->nullable();
            $table->string('email')->nullable();
            $table->string('employer')->nullable();
            $table->string('title')->nullable();

            // Held encrypted and shown masked to the last four. Cast on the
            // model, so nothing here has to know it is not plain text.
            $table->text('ssn')->nullable();

            $table->string('drivers_license')->nullable();

            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();

            /*
             * The door kiosk's half, folded in from `guardians`.
             *
             * A PIN is not something every person has — the office record of a
             * grandmother who is only ever an emergency number has no reason to
             * carry one — so all of it is nullable and a person without a PIN
             * simply cannot be authenticated at the door.
             */
            $table->string('pin_index')->nullable()->index();
            $table->string('pin_hash')->nullable();
            $table->string('phone_last4')->nullable();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->timestamps();

            // The two ways one person is recognised as an existing one: the
            // cell first, the name when there is no number to go on.
            $table->index('cell');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
