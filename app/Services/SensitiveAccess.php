<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who looked at or changed a Social Security number, one line each in the
 * activity log (QA #34, 12 Nov 2026).
 *
 * A line names the person who did it and whose record it was — never the
 * number, not even the last four: the log is read by more people than the
 * numbers are.
 *
 * TODO(Jordan): decide whether the centre needs the parents' SSNs at all.
 * They come off the paper enrollment form (Child::mother_ssn / father_ssn,
 * Person::ssn). If nothing the centre files asks for them, stop collecting
 * them (drop the fields from the Add child form, the people forms and the
 * import) and clear the columns.
 */
class SensitiveAccess
{
    public const VIEWED = 'viewed';

    public const UPDATED = 'updated';

    public const EXPORTED = 'exported';

    public static function record(string $event, string $text, ?object $subject = null, ?string $subjectName = null): void
    {
        $actor = auth()->user();

        DB::table('activity_log')->insert([
            'actor_id' => $actor instanceof User ? $actor->id : null,
            'actor_name' => $actor instanceof User ? mb_substr($actor->name, 0, 120) : 'System',
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject->id ?? null,
            'subject_name' => $subjectName !== null ? mb_substr($subjectName, 0, 160) : null,
            'event' => $event,
            'text' => mb_substr('SSN: '.$text, 0, 400),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Whether the signed-in user may see or change an SSN at all. */
    public static function allowed(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdmin();
    }
}
