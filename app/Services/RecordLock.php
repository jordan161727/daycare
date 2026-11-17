<?php

namespace App\Services;

use App\Models\Child;
use Illuminate\Http\Request;

/**
 * Optimistic locking for the child record's forms (QA J2, J3, E4, E5).
 *
 * Two people with the same record open used to save over each other without
 * a word: the second save wrote back every field as it was when that page
 * was drawn, so the first person's case number, phone fix or severe allergy
 * was quietly undone. Each form now carries the version of the record it was
 * drawn from (`record_version`); a save from a version that is no longer the
 * record's is refused with a message, and nothing is written.
 *
 * The version is a fingerprint of the record's own columns, so it needs no
 * column of its own. The room, which the nightly sync rewrites with nobody
 * touching the record, and the bookkeeping columns are left out of it.
 *
 * A post without the field (an older page still open in a tab, a script)
 * falls back to what this signed-in user last saw of the record, kept in the
 * session when the record or its edit form is drawn: stale when the record
 * has changed since and the change was somebody else's. Never having seen it
 * counts as stale only once somebody else has changed it.
 */
class RecordLock
{
    public const FIELD = 'record_version';

    /** The columns a change to which does not make an open form stale. */
    private const IGNORED = ['id', 'created_at', 'updated_at', 'updated_by', 'classroom'];

    public static function version(Child $child): string
    {
        $attributes = array_diff_key($child->getAttributes(), array_flip(self::IGNORED));
        ksort($attributes);

        return substr(sha1(json_encode(array_map(fn ($v) => $v === null ? null : (string) $v, $attributes))), 0, 16);
    }

    /** Remember that the signed-in user has the record in front of them as it is now. */
    public static function seen(Child $child): void
    {
        if ($key = self::key($child)) {
            session()->put($key, self::version($child->exists ? ($child->fresh() ?? $child) : $child));
        }
    }

    /** Whether this save comes from a copy of the record that has changed since. */
    public static function isStale(Request $request, Child $child): bool
    {
        $current = self::version($child);
        $posted = $request->input(self::FIELD);

        if (is_string($posted) && $posted !== '') {
            return ! hash_equals($current, $posted);
        }

        $me = $request->user()?->id;
        $byOther = $child->updated_by !== null && (int) $child->updated_by !== (int) $me;
        $seen = ($key = self::key($child)) ? session($key) : null;

        return $seen !== null
            ? $seen !== $current && $byOther
            : $byOther;
    }

    /**
     * The alerts list as this user was last shown it — by the record page,
     * the edit form, or a save of the whole list from the form. Not by a
     * Remove: one tab removing an alert says nothing about what a second tab
     * on the same record still shows (QA E4, H13).
     */
    public static function alertsSeen(Child $child): void
    {
        if ($key = self::key($child, 'alerts_seen')) {
            session()->put($key, self::alertsVersion($child->exists ? ($child->fresh() ?? $child) : $child));
        }
    }

    /**
     * Whether a Remove or Edit that names an alert only by its place in the
     * list comes from a page whose list has changed since. The record page
     * posts each alert's fingerprint instead, which needs none of this.
     */
    public static function alertsStale(Child $child): bool
    {
        $seen = ($key = self::key($child, 'alerts_seen')) ? session($key) : null;

        return $seen !== null
            ? $seen !== self::alertsVersion($child)
            : $child->updated_by !== null;
    }

    private static function alertsVersion(Child $child): string
    {
        return sha1((string) json_encode(array_values($child->alerts ?? [])));
    }

    /** What the person whose save was refused is told. */
    public static function message(Child $child): string
    {
        $who = $child->updatedBy?->name;
        $when = $child->updated_at?->timezone(config('app.timezone'))->format('g:i A');

        return trim($child->first_name.'\'s record was changed'.($who ? ' by '.$who : ' by someone else').($when ? ' at '.$when : ''))
            .' after you opened it, so your changes were not saved. This page now shows the latest record — make your change again.';
    }

    private static function key(Child $child, string $what = 'record_seen'): ?string
    {
        $user = auth()->id();

        return $user && $child->getKey() ? $what.'.'.$user.'.'.$child->getKey() : null;
    }
}
