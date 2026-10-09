<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\ClassroomAssignment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One teacher a room, each with a password of their own, and nobody else.
 *
 * The demo roster grows teachers by the dozen — RoomCoverSeeder tops the
 * staff up to what the roll needs — and every one of them signs in with
 * "password". For handing the app to the centre that is the wrong shape:
 * they want a login per room, so the Infant teacher opens the Infant room
 * and nothing else, and they want the passwords to differ, so one leaked
 * password does not open every room.
 *
 * This keeps (or makes) exactly one account per room, named for the room,
 * sets the room's own password on it, and deletes every other teacher. The
 * director's account is not touched. Deleting a teacher takes their shifts,
 * punches and leave with them (the tables cascade), so run this before the
 * schedule and punch seeders, not after, if you want those to line up.
 *
 *     php artisan db:seed --class=ClassroomTeacherSeeder --force
 */
class ClassroomTeacherSeeder extends Seeder
{
    use WithoutModelEvents;

    /** The room's password, by room. Memorable and different from each other. */
    public const PASSWORDS = [
        'Infant' => 'Infant#2026',
        'Transition' => 'Transition#2026',
        'Toddler' => 'Toddler#2026',
        'PreK' => 'PreK#2026',
        'UPK-4' => 'UPK4#2026',
        'School Age' => 'SchoolAge#2026',
    ];

    /** The room's login, by room. These are the base seed's own addresses. */
    public static function email(string $room): string
    {
        return Str::of($room)->lower()->replace(['-', ' '], '')->append('.teacher@daycare.test')->toString();
    }

    public function run(): void
    {
        $keep = [];

        foreach (ClassroomAssignment::rooms() as $room) {
            $email = self::email($room);
            $password = self::PASSWORDS[$room] ?? Str::of($room)->studly()->append('#2026')->toString();

            // The room's own account if it exists, else any teacher already
            // in that room, else a new one — so a re-run changes nothing but
            // the password, and a fresh database still comes out right.
            $teacher = User::where('email', $email)->first()
                ?? User::teachers()->where('classroom', $room)->whereNotIn('id', $keep)->first()
                ?? new User;

            $teacher->forceFill([
                'name' => $room.' Teacher',
                'email' => $email,
                'role' => 'teacher',
                'classroom' => $room,
                'classrooms' => [$room],
                'password' => Hash::make($password),
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            $keep[] = $teacher->id;
            $this->command?->line(sprintf('  %-11s %-36s %s', $room, $email, $password));
        }

        $gone = User::teachers()->whereNotIn('id', $keep)->get();
        foreach ($gone as $user) {
            $user->delete();
        }

        $this->command?->info(sprintf('%d room teachers set; %d other teacher account%s deleted.', count($keep), $gone->count(), $gone->count() === 1 ? '' : 's'));
    }
}
