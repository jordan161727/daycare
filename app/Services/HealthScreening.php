<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\HealthAudit;
use App\Models\SymptomCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording and correcting the health check taken at the door.
 *
 * One place, because three screens write these codes — the check-in cell, the
 * check-out cell and the correction popover — and the rules they share are the
 * kind that go wrong quietly when they are copied. A code recorded on a past
 * day by somebody who should not have, or a code changed with no audit entry
 * behind it, is not a thing anybody notices until it matters.
 */
class HealthScreening
{
    /**
     * Who may write a code on a given day.
     *
     * Today is everybody's: the whole point is that the person standing at the
     * door records what they saw, and a teacher who has to find a director
     * before they can write "rash" will write nothing at all.
     *
     * A day already gone is the director's. By then nobody remembers the
     * child, and a code typed from memory into last Tuesday is a statement
     * about a child's health that nobody witnessed — which is exactly the kind
     * of record a licensing visit asks about.
     */
    public function mayRecord(User $user, string $date): bool
    {
        return $user->isAdmin() || $date === today()->toDateString();
    }

    /**
     * Check a code and its note before anything is written.
     *
     * Throws rather than returns, because every caller's answer to a bad code
     * is the same: refuse the write and say why on the field it came from.
     *
     * @param  ?int  $code  Null means clear — allowed, and audited like any change.
     */
    public function validate(?int $code, ?string $note): void
    {
        if ($code === null) {
            return;
        }

        $symptom = SymptomCode::find($code);

        if (! $symptom || ! $symptom->active) {
            throw ValidationException::withMessages([
                'health_code' => 'That is not a symptom code the centre uses.',
            ]);
        }

        // "Other" with no words is not a record of anything. It is the one
        // code whose meaning lives entirely in the note beside it.
        if ($symptom->requires_note && blank($note)) {
            throw ValidationException::withMessages([
                'health_note' => 'Choosing "'.$symptom->label.'" needs a short note saying what was seen.',
            ]);
        }
    }

    /**
     * Write a code against one end of a day, and note the change.
     *
     * The write and its audit entry go in one transaction: a code that changed
     * with no record of who changed it is worse than one that never changed,
     * because the sheet then says something nobody can stand behind.
     *
     * A note is only kept where a code is. Clearing a code clears its note
     * with it — words explaining a symptom that is no longer recorded are
     * words about nothing.
     */
    public function record(Attendance $attendance, string $direction, ?int $code, ?string $note, ?User $by): Attendance
    {
        $this->validate($code, $note);

        if ($direction === HealthAudit::OUT && $code !== null && ! $attendance->acceptsOutHealth()) {
            throw ValidationException::withMessages([
                'health_code' => 'Record the collection time first — a leaving check belongs to a child who has left.',
            ]);
        }

        $old = $attendance->healthCode($direction);
        $codeColumn = $direction === HealthAudit::OUT ? 'health_out_code' : 'health_in_code';
        $noteColumn = $direction === HealthAudit::OUT ? 'health_out_note' : 'health_in_note';

        DB::transaction(function () use ($attendance, $direction, $code, $note, $by, $old, $codeColumn, $noteColumn) {
            $attendance->forceFill([
                $codeColumn => $code,
                $noteColumn => $code === null ? null : ($note ?: null),
            ])->save();

            HealthAudit::note($attendance, $direction, $old, $code, $code === null ? null : ($note ?: null), $by);
        });

        return $attendance->refresh();
    }

    /**
     * The list a picker offers and a legend prints.
     *
     * Retired codes are left out: a picker offering one would let somebody
     * record against a code the centre has stopped using, and the reason it
     * was retired is usually that it was being misread.
     */
    public function codes()
    {
        return SymptomCode::active()->map(fn (SymptomCode $code) => [
            'code' => $code->code,
            'label' => $code->label,
            'requires_note' => $code->requires_note,
            'sick' => SymptomCode::isSick($code->code),
        ])->values();
    }
}
