<?php

namespace Database\Seeders;

use App\Models\RoomSchedule;
use App\Services\ClassroomAssignment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * A starting set of hours for every room.
 *
 * Each room opens with the centre and closes with it, which is the honest
 * default: a room that has never been given hours of its own runs the whole
 * operating day. The director then shortens the rooms that actually differ,
 * and that edit is what this must not undo — so a room already set is left
 * exactly as it is, and re-seeding only fills in the ones nobody has touched.
 */
class RoomScheduleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $opens = $this->timeOf((int) config('daycare.open'));
        $closes = $this->timeOf((int) config('daycare.close'));

        foreach (ClassroomAssignment::rooms() as $room) {
            $schedule = RoomSchedule::firstOrNew(['room' => $room]);

            // A room already given hours is a decision and is left alone. A row
            // that exists with both ends blank is not a decision — it is a room
            // somebody opened the form on and never filled in, and it is
            // exactly what this is here to fill.
            if ($schedule->isSet()) {
                continue;
            }

            $schedule->fill(['opens_at' => $opens, 'closes_at' => $closes])->save();
        }
    }

    /** Minutes past midnight as the H:i a time column wants. */
    private function timeOf(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
