<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The door kiosk: who may sign a child in and out, and every press of it.
     *
     * A guardian is not a user. They have no login, reach no screen but the one
     * at the door, and are identified by a PIN rather than a password — so they
     * live in their own table rather than as a role on users, where one mistake
     * in a policy would hand a parent the payroll page.
     */
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('relationship')->nullable();
            $table->string('phone')->nullable();

            // Two columns for one PIN, and both are needed.
            //
            // pin_index is a keyed hash: it is what the kiosk looks the PIN up
            // by, because verifying a bcrypt hash against every guardian in the
            // centre would be a second of work per keypress. It is keyed on the
            // app key, so the table alone does not let anyone try six-digit
            // numbers against it offline.
            //
            // pin_hash is what actually proves the PIN. The index narrows to a
            // row; the hash decides.
            //
            // Deliberately not unique: two guardians choosing the same six
            // digits is a collision the kiosk resolves by asking for the last
            // four of a phone number, not an error to refuse at the form.
            $table->string('pin_index')->index();
            $table->string('pin_hash');
            $table->string('phone_last4', 4)->nullable();

            // Lockout lives on the row rather than in the cache: a kiosk that
            // forgets its lockouts when the queue restarts is not a lockout.
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->timestamps();
        });

        // The allow-list. A guardian on a child's record may be told about them;
        // a guardian with can_collect may take them home. The two are different
        // permissions and the second is the one that matters at the door.
        Schema::create('child_guardian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_collect')->default(false);
            $table->timestamps();

            $table->unique(['child_id', 'guardian_id']);
        });

        // Every press, kept. The attendance row says a child was here; this says
        // who brought them, at which minute, and by what means — and it is
        // append-only, so a correction is another row rather than an edit.
        Schema::create('child_attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction');          // in | out
            $table->timestamp('occurred_at');
            $table->date('service_date');
            $table->string('session')->default('FULL');
            $table->string('method')->default('pin');
            $table->string('device')->nullable();
            $table->timestamps();

            $table->index(['child_id', 'service_date']);
        });

        // The sheet has always recorded that a child came. It has had nowhere to
        // record that they left, because until there was a door kiosk nobody was
        // pressing anything when they did.
        Schema::table('attendances', function (Blueprint $table) {
            $table->timestamp('signed_out_at')->nullable()->after('signed_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', fn (Blueprint $table) => $table->dropColumn('signed_out_at'));
        Schema::dropIfExists('child_attendance_punches');
        Schema::dropIfExists('child_guardian');
        Schema::dropIfExists('guardians');
    }
};
