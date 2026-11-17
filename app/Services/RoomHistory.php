<?php

namespace App\Services;

use App\Models\Child;
use App\Models\ChildStatusChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The room a child was in on a day gone by (QA C8).
 *
 * `children.classroom` is today's answer, so a page about an earlier week
 * that reads it straight rewrites that week the moment a child moves: the
 * October report suddenly lists them under the room they joined in November.
 * The enrollment history already has every move as "From → To", dated the
 * day it took effect, so the room on a date is the "From" of the first move
 * after it — or today's room when nothing has moved since.
 */
class RoomHistory
{
    /**
     * Put the room each child was in on $asOf onto the loaded models, for
     * display only. Never saved: the saving hook works the column out again
     * from the date of birth and any override, so a model that is saved
     * afterwards still gets today's room.
     *
     * @param  iterable<Child>  $children
     */
    public static function apply(iterable $children, Carbon $asOf): void
    {
        $rooms = self::roomsOn(collect($children)->pluck('id')->all(), $asOf);

        foreach ($children as $child) {
            if (array_key_exists($child->id, $rooms)) {
                $child->setAttribute('classroom', $rooms[$child->id]);
            }
        }
    }

    /**
     * The rooms on $asOf for the children that have moved since, keyed by id.
     * Children not in the result were in the room they are in now.
     *
     * @param  array<int>  $ids
     * @return array<int, string>
     */
    public static function roomsOn(array $ids, Carbon $asOf): array
    {
        $end = $asOf->copy()->endOfDay();

        if ($ids === [] || $end->gte(Carbon::now())) {
            return [];
        }

        return ChildStatusChange::whereIn('child_id', $ids)
            ->where('event', 'room')
            ->where('from_status', 'like', '% → %')
            ->where('created_at', '>', $end)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['child_id', 'from_status'])
            ->unique('child_id')
            ->mapWithKeys(fn (ChildStatusChange $c) => [$c->child_id => trim(Str::before($c->from_status, ' → '))])
            ->filter(fn ($room) => $room !== '')
            ->all();
    }
}
