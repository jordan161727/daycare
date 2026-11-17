<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * PINs the director has to reset (QA K1/K2, 16 Nov 2026).
 *
 * Read-only — nothing here writes, so it is safe to run on the live server at
 * any hour. Two lists:
 *
 *  1. People who share a PIN: staff with staff, parents with parents, and a
 *     staff member with a parent. The migrations that make PINs unique refuse
 *     to run over the first two rather than wiping anybody's PIN; the third
 *     cannot be held by an index across two tables, so the forms refuse it
 *     and this lists any that were set before they did.
 *  2. PINs that do not fit config('kiosk.pin_length'): a keypad submits at
 *     that many digits, so a PIN of another length can never be keyed. A PIN
 *     set before its length was recorded counts as the length the screens
 *     used then — 4 for staff, 6 for parents.
 *
 * Grouped by the HMAC index keyed with APP_KEY, so the report says who shares
 * a PIN without anybody — including whoever runs it — learning what the PIN
 * is. It never prints a PIN, a hash or an index.
 */
class KioskDuplicatePins extends Command
{
    protected $signature = 'kiosk:duplicate-pins';

    protected $description = 'Read-only: list staff and parents who share a kiosk PIN, or whose PIN is the wrong length (never prints the PINs)';

    /** The lengths every PIN had before QA K1, for PINs set before lengths were recorded. */
    public const OLD_STAFF_LENGTH = 4;

    public const OLD_PARENT_LENGTH = 6;

    public function handle(): int
    {
        $groups = static::groups();
        $wrongLength = static::wrongLength();

        if ($groups->isEmpty()) {
            $this->info('No two people share a PIN.');
        } else {
            $this->warn($groups->count().' '.str('PIN')->plural($groups->count()).' shared by more than one person (read-only, nothing is changed):');
            $this->newLine();

            foreach ($groups->values() as $i => $people) {
                $this->line('Group '.($i + 1).' — '.$people->count().' people on one PIN');
                $this->table(['Who', 'ID', 'Name', 'Email', 'Role'], $people->map(fn (array $p) => [$p['kind'], $p['id'], $p['name'], $p['email'], $p['role']])->all());
            }

            $this->line('Give everyone but one person in each group a new PIN, then run this again.');
        }

        $this->newLine();
        $length = (int) config('kiosk.pin_length');

        if ($wrongLength->isEmpty()) {
            $this->info("Every PIN is {$length} digits.");
        } else {
            $this->warn($wrongLength->count().' '.str('PIN')->plural($wrongLength->count())." cannot be keyed: the kiosks take {$length} digits.");
            $this->table(['Who', 'ID', 'Name', 'Email', 'Role', 'PIN length'], $wrongLength->map(fn (array $p) => [$p['kind'], $p['id'], $p['name'], $p['email'], $p['role'], $p['length']])->all());
            $this->line("Give each of them a new {$length}-digit PIN (staff: Teachers → the person → Clock PIN → Replace).");
        }

        return $groups->isEmpty() && $wrongLength->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every set of two or more people on one PIN, staff and parents together,
     * each set ordered by name.
     *
     * @return Collection<string, Collection<int, array>>
     */
    public static function groups(): Collection
    {
        return static::everyone()
            ->groupBy('index')
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(fn (Collection $group) => $group->sortBy('name')->values());
    }

    /** Everybody whose PIN is not config('kiosk.pin_length') digits. */
    public static function wrongLength(): Collection
    {
        $length = (int) config('kiosk.pin_length');

        return static::everyone()
            ->reject(fn (array $p) => $p['length'] === $length)
            ->sortBy('name')
            ->values();
    }

    /** One row per PIN held, staff and parents, with nothing secret in it. */
    private static function everyone(): Collection
    {
        $staff = User::whereNotNull('kiosk_pin_index')->get()->map(fn (User $user) => [
            'index' => $user->kiosk_pin_index,
            'kind' => 'Staff',
            'id' => $user->staffId(),
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'length' => $user->kiosk_pin_length !== null ? (int) $user->kiosk_pin_length : self::OLD_STAFF_LENGTH,
        ]);

        $parents = Person::whereNotNull('pin_index')->get()->map(fn (Person $person) => [
            'index' => $person->pin_index,
            'kind' => 'Parent',
            'id' => 'P-'.$person->id,
            'name' => $person->name,
            'email' => '',
            'role' => 'parent',
            'length' => $person->pin_length !== null ? (int) $person->pin_length : self::OLD_PARENT_LENGTH,
        ]);

        return $staff->concat($parents)->values();
    }
}
