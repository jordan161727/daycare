<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildAttendancePunch;
use App\Models\Guardian;
use Illuminate\Support\Facades\DB;

/**
 * The door and the sheet, kept in step.
 *
 * A guardian signing a child in at the kiosk writes the same attendance row a
 * teacher writes by tapping the box, through the same firstOrCreate keyed on
 * child, date and session — so the sheet shows the child as signed in, at the
 * minute they actually arrived, with nobody retyping anything. That is the whole
 * point of the kiosk: the record is made once, by the person who was there.
 *
 * Signing out does not undo it. Attendance is the fact that a child was here
 * that day, and DSS bills against it; leaving at three does not make the morning
 * not have happened. The departure is written beside the arrival instead.
 */
class KioskAttendance
{
    /**
     * @return array{status: string, time?: string, session?: string}
     */
    public function punch(Child $child, string $direction, Guardian $guardian, string $device = null): array
    {
        // Re-checked here and not only in the browser. The tile's button was a
        // hint; this is the rule.
        if ($direction === ChildAttendancePunch::OUT && ! $guardian->mayCollect($child)) {
            return ['status' => 'not_authorised'];
        }

        if (! $child->guardians()->whereKey($guardian->id)->exists()) {
            return ['status' => 'not_authorised'];
        }

        $date = today()->toDateString();

        // Which half of the day a School Age child is arriving for. Every other
        // room signs in once, so FULL is both the common case and the honest one.
        $session = $this->sessionFor($child);

        if (! $child->isEnrolledOn($date)) {
            return ['status' => 'not_enrolled'];
        }

        return DB::transaction(function () use ($child, $direction, $guardian, $device, $date, $session) {
            $attendance = Attendance::firstOrCreate(
                ['child_id' => $child->id, 'attendance_date' => $date, 'session' => $session],
                ['signed_in_at' => now()],
            );

            if ($direction === ChildAttendancePunch::IN) {
                // Already here. Pressing again is a double tap at a door, not a
                // second arrival, and it must not move the recorded time.
                if (! $attendance->wasRecentlyCreated && $attendance->signed_out_at === null) {
                    return ['status' => 'already_in', 'time' => $attendance->signed_in_at->format('g:i A')];
                }

                // Back after being collected — a half day out and in again. The
                // morning's arrival stands; the departure is cleared because the
                // child is here now.
                if (! $attendance->wasRecentlyCreated) {
                    $attendance->forceFill(['signed_out_at' => null])->save();
                }
            } else {
                if ($attendance->wasRecentlyCreated) {
                    // Signing out a child who was never signed in would leave an
                    // arrival nobody made. Undo it and say so.
                    $attendance->delete();

                    return ['status' => 'not_in'];
                }

                $attendance->forceFill(['signed_out_at' => now()])->save();
            }

            ChildAttendancePunch::create([
                'child_id' => $child->id,
                'guardian_id' => $guardian->id,
                'direction' => $direction,
                'occurred_at' => now(),
                'service_date' => $date,
                'session' => $session,
                'method' => 'pin',
                'device' => $device,
            ]);

            return [
                'status' => 'ok',
                'session' => $session,
                'time' => now()->format('g:i A'),
            ];
        });
    }

    /** How this child's day is recorded — one stamp, or a morning and an afternoon. */
    private function sessionFor(Child $child): string
    {
        $sessions = $child->sessions();

        if (count($sessions) === 1) {
            return $sessions[0];
        }

        // A room that splits the day: before noon is the morning's session.
        return now()->hour < 12 ? 'AM' : 'PM';
    }
}
