<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The health check taken at the door, morning and evening.
 *
 * A code is recorded against the arrival and against the departure, because
 * they answer different questions: the morning code is "should this child be
 * here today", and the evening code is "did something start while they were
 * with us". A single code per day could not tell a child who arrived with a
 * rash from one who developed it at three o'clock.
 *
 * Three pieces:
 *
 *   symptom_codes  — the list itself, in a table rather than an enum, because
 *                    a centre's list is a licensing matter that changes
 *                    without a deploy. `active` retires a code without
 *                    deleting it, so the months of records that used it still
 *                    read correctly.
 *   attendances    — the codes and notes themselves, nullable throughout.
 *   health_audits  — who changed a code, when, and what it was before.
 *
 * Nullable on purpose, and this is the important decision. The screens require
 * a code before they will save a check-in, but the column cannot be: every
 * attendance row already in the table predates this feature, and the door
 * kiosk signs children in without a member of staff present to judge a
 * symptom. Those rows read as "not recorded" — the dashed chip — which is a
 * true statement, where a defaulted 0 would be a false one claiming somebody
 * looked at the child and saw nothing wrong.
 */
return new class extends Migration
{
    /** The centre's list, as it stands today. Code 11 is the one that needs words. */
    private const CODES = [
        [0, 'Normal', false],
        [1, 'Asthma / wheezing', false],
        [2, 'Behavior change', false],
        [3, 'Diarrhea', false],
        [4, 'Fever', false],
        [5, 'Headache', false],
        [6, 'Rash', false],
        [7, 'Respiratory', false],
        [8, 'Stomach ache', false],
        [9, 'Urine problem', false],
        [10, 'Vomiting', false],
        [11, 'Other', true],
    ];

    public function up(): void
    {
        Schema::create('symptom_codes', function (Blueprint $table) {
            // The code is the key. It is what is printed on the sheet, read
            // aloud and written in the records, so an autoincrementing id
            // beside it would be a second name for the same thing.
            $table->unsignedTinyInteger('code')->primary();
            $table->string('label', 60);
            $table->boolean('requires_note')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('symptom_codes')->insert(array_map(fn (array $row) => [
            'code' => $row[0],
            'label' => $row[1],
            'requires_note' => $row[2],
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::CODES));

        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedTinyInteger('health_in_code')->nullable()->after('signed_out_at');
            $table->string('health_in_note', 120)->nullable()->after('health_in_code');
            $table->unsignedTinyInteger('health_out_code')->nullable()->after('health_in_note');
            $table->string('health_out_note', 120)->nullable()->after('health_out_code');

            // Read on every sheet to colour a chip and to count the day's sick
            // children, so both are indexed.
            $table->index('health_in_code');
            $table->index('health_out_code');
        });

        Schema::create('health_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);
            // Null on the first entry — there was nothing there before — and
            // null again in new_code when somebody clears one.
            $table->unsignedTinyInteger('old_code')->nullable();
            $table->unsignedTinyInteger('new_code')->nullable();
            $table->string('note', 120)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['attendance_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_audits');

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['health_in_code']);
            $table->dropIndex(['health_out_code']);
            $table->dropColumn(['health_in_code', 'health_in_note', 'health_out_code', 'health_out_note']);
        });

        Schema::dropIfExists('symptom_codes');
    }
};
