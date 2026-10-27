<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of docs/work.md: the Children list and the child record as the
 * mock-ups draw them.
 *
 * What the record did not have a column for until now:
 *
 *  - how a child left (the reason, the note, whether they might be back),
 *    which the Withdrawn tab and the DSS page both read;
 *  - the one enrollment note that shows on the DSS page;
 *  - the health facts beside the alerts (doctor, immunizations, medication);
 *  - who last touched the record, for the footer;
 *  - on each adult's link, whether they pay the fees and get the texts;
 *  - a history line that is not a status change (a room move, a schedule
 *    change, the subsidy starting), with a note on it;
 *  - the documents on file for a child, and the office's own notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('withdrawal_reason')->nullable()->after('withdrawn_on');
            $table->text('withdrawal_note')->nullable()->after('withdrawal_reason');
            $table->string('could_return', 20)->nullable()->after('withdrawal_note');
            $table->text('enrollment_note')->nullable()->after('could_return');
            $table->timestamp('enrollment_note_at')->nullable()->after('enrollment_note');
            $table->json('health')->nullable()->after('alerts');
            $table->string('custody_note')->nullable()->after('health');
            $table->foreignId('updated_by')->nullable()->after('custody_note')->constrained('users')->nullOnDelete();
        });

        Schema::table('child_people', function (Blueprint $table) {
            $table->boolean('pays_fees')->default(false)->after('is_emergency');
            $table->boolean('gets_texts')->default(false)->after('pays_fees');
        });

        Schema::table('child_status_changes', function (Blueprint $table) {
            // status, room, schedule, subsidy, enrolled, started, withdrawn,
            // cancelled, reenrolled, archived, restored — see Child::recordEvent.
            $table->string('event', 30)->default('status')->after('child_id');
            $table->text('note')->nullable()->after('to_status');
        });

        Schema::create('child_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('name');
            // Null when the paper is in the cabinet and only the fact of it is
            // recorded here.
            $table->string('path')->nullable();
            $table->date('signed_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['child_id', 'kind']);
        });

        Schema::create('child_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // office or health
            $table->string('kind', 20)->default('office');
            $table->text('body');
            $table->timestamps();

            $table->index(['child_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_notes');
        Schema::dropIfExists('child_documents');

        Schema::table('child_status_changes', function (Blueprint $table) {
            $table->dropColumn(['event', 'note']);
        });

        Schema::table('child_people', function (Blueprint $table) {
            $table->dropColumn(['pays_fees', 'gets_texts']);
        });

        Schema::table('children', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['withdrawal_reason', 'withdrawal_note', 'could_return', 'enrollment_note', 'enrollment_note_at', 'health', 'custody_note']);
        });
    }
};
