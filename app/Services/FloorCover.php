<?php

namespace App\Services;

use App\Models\StaffRule;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The floor plan's one action — Phase 4.2 of docs/work.md.
 *
 * "Move LaQuora to Toddler until Sultana arrives" is written onto today's
 * roster, not kept on the floor plan: LaQuora's shift is cut at this minute
 * and the rest of it becomes a FLOAT shift in Toddler that remembers who it
 * stands in for. The floor plan, the schedule, the timesheet and the ratio
 * check all read the roster, so they all see the move at once. When Sultana
 * clocks in, settle() closes the float at that minute and gives LaQuora the
 * rest of her day back in her own room.
 */
class FloorCover
{
    /**
     * Move a person to a room from this minute, for the rest of their shift.
     *
     * @throws \DomainException when a Must rule keeps the person out of that room
     */
    public function move(User $person, string $to, ?User $for, User $actor, ?Carbon $now = null): StaffShift
    {
        $now = ($now ?? now())->copy()->timezone(config('app.timezone'));
        $date = $now->toDateString();
        $minute = $now->hour * 60 + $now->minute;

        if (! in_array($to, ClassroomAssignment::rooms(), true)) {
            throw new \InvalidArgumentException('That room is not on the plan.');
        }
        if ($person->staffRules->contains(fn (StaffRule $r) => $r->rule_type === 'ROOM_FORBIDDEN' && $r->value_text === $to)) {
            throw new \DomainException("A Must rule says {$this->first($person)} is never assigned to {$to}.");
        }

        return DB::transaction(function () use ($person, $to, $for, $date, $minute, $now) {
            $own = StaffShift::where('user_id', $person->id)->whereDate('shift_date', $date)->orderBy('starts_at')->get();
            $current = $own->first(fn (StaffShift $s) => $s->covers($minute));
            $end = $current?->ends_at ?? max($minute + 60, $own->max('ends_at') ?? (int) config('daycare.close'));
            $base = $this->base($now);

            if ($current && $current->classroom === $to) {
                $current->update(['covers_user_id' => $for?->id ?? $current->covers_user_id]);

                return $current;
            }

            if ($current && $current->starts_at < $minute) {
                $current->update(['ends_at' => $minute]);
            } elseif ($current) {
                $current->delete();
            }

            $float = StaffShift::create($base + [
                'user_id' => $person->id,
                'starts_at' => $minute,
                'ends_at' => $end,
                'classroom' => $to,
                'role' => StaffShift::ROLE_FLOAT,
                'covers_user_id' => $for?->id,
            ]);

            StaffScheduleWeek::firstOrCreate(['week_start' => $base['week_start']], ['generated_at' => null])->noteEdit();

            return $float;
        });
    }

    /**
     * Someone has clocked in: any float standing in for them ends now, and
     * the floater goes back to their own room for what is left of the day.
     *
     * @return list<StaffShift> the shifts given back
     */
    public function settle(User $arrived, ?Carbon $at = null): array
    {
        $at = ($at ?? now())->copy()->timezone(config('app.timezone'));
        $date = $at->toDateString();
        $minute = $at->hour * 60 + $at->minute;

        $floats = StaffShift::where('covers_user_id', $arrived->id)->whereDate('shift_date', $date)
            ->where('ends_at', '>', $minute)->get();

        if ($floats->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($floats, $minute, $at) {
            $given = [];
            $base = $this->base($at);

            foreach ($floats as $float) {
                $person = $float->user;
                $home = $person?->classroom;
                $end = $float->ends_at;
                $from = max($minute, $float->starts_at);

                if ($float->starts_at < $minute) {
                    $float->update(['ends_at' => $minute, 'covers_user_id' => null]);
                } else {
                    $float->delete();
                }

                if ($home && $from < $end) {
                    $given[] = StaffShift::create($base + [
                        'user_id' => $float->user_id,
                        'starts_at' => $from,
                        'ends_at' => $end,
                        'classroom' => $home,
                        'role' => StaffShift::ROLE_STAFF,
                    ]);
                }
            }

            StaffScheduleWeek::firstOrCreate(['week_start' => $base['week_start']], ['generated_at' => null])->noteEdit();

            return $given;
        });
    }

    /** @return array{week_start: string, shift_date: string, day: string} */
    private function base(Carbon $day): array
    {
        $monday = $day->copy()->startOfWeek(Carbon::MONDAY);
        $days = config('daycare.days');
        $offset = min($day->dayOfWeekIso - 1, count($days) - 1);

        return ['week_start' => $monday->toDateString(), 'shift_date' => $day->toDateString(), 'day' => $days[$offset]];
    }

    private function first(User $user): string
    {
        return explode(' ', trim($user->name))[0];
    }
}
