<?php

namespace Database\Seeders;

/**
 * The first half of October 2026, 1st to 17th, on both records.
 *
 * Kept for the addresses and notes that name it; the work moved to
 * ChildAttendanceDemoSeeder (27 Oct 2026), which does the same for any range
 * and marks the absent days on the DSS page. This one only sets the old
 * default range.
 *
 *   php artisan db:seed --class=OctoberAttendanceSeeder
 *   SEED_FROM=2026-10-01 SEED_TO=2026-10-17 php artisan db:seed --class=OctoberAttendanceSeeder
 */
class OctoberAttendanceSeeder extends ChildAttendanceDemoSeeder
{
    protected function defaultFrom(): string
    {
        return '2026-10-01';
    }

    protected function defaultTo(): string
    {
        return '2026-10-17';
    }
}
