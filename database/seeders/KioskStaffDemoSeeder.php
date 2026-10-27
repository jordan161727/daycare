<?php

namespace Database\Seeders;

use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The three teachers the kiosk hand test in docs/kiosks.md is written
 * around, with their PINs and rooms, and Lana's shift today in PreK so
 * "Your schedule" comes first on the door's room picker.
 *
 *     php artisan db:seed --class=KioskStaffDemoSeeder
 *
 * Lana Ortiz 3333 (PreK), Sultana Begum 4444 (Toddler), Evana Roy 5555
 * (Infant). Made-up people on example.test addresses; run it as often as
 * you like — each run finds them by email and puts the PINs back.
 *
 * A PIN somebody else already holds is left alone and said so, because two
 * people on one PIN is the one thing the clock cannot tell apart.
 */
class KioskStaffDemoSeeder extends Seeder
{
    private const STAFF = [
        ['Lana Ortiz', 'lana.ortiz@example.test', 'PreK', '3333'],
        ['Sultana Begum', 'sultana.begum@example.test', 'Toddler', '4444'],
        ['Evana Roy', 'evana.roy@example.test', 'Infant', '5555'],
    ];

    public function run(): void
    {
        $made = [];

        foreach (self::STAFF as [$name, $email, $room, $pin]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->fill(['name' => $name, 'role' => 'teacher', 'classroom' => $room]);
            if (! $user->exists) {
                $user->password = Hash::make(Str::random(32));
            }
            $user->save();

            if (User::kioskPinTaken($pin, $user)) {
                $this->command?->warn("$pin is already somebody else's PIN — $name was left without one. Give her another on the Staff page.");
            } else {
                $user->setKioskPin($pin);
                $made[] = "$name $pin ($room)";
            }

            if ($name === 'Lana Ortiz') {
                $this->shiftToday($user, $room);
            }
        }

        $this->command?->info('Kiosk staff ready: '.implode(', ', $made).'. Lana has a shift today in PreK.');
    }

    /** 7:00 AM – 3:00 PM today, in the room, unless she already has one. */
    private function shiftToday(User $user, string $room): void
    {
        $today = Carbon::today();
        $week = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

        StaffShift::firstOrCreate(
            ['user_id' => $user->id, 'shift_date' => $today->toDateString()],
            ['week_start' => $week, 'day' => $today->dayOfWeekIso, 'starts_at' => 7 * 60, 'ends_at' => 15 * 60, 'classroom' => $room, 'role' => 'STAFF'],
        );
    }
}
