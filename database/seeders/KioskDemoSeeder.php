<?php

namespace Database\Seeders;

use App\Models\Child;
use App\Models\Guardian;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Guardians for the door kiosk, arranged so every branch can be walked by hand.
 *
 * Needs the children first — run DemoScenarioSeeder before this one.
 */
class KioskDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $children = Child::orderBy('id')->get();

        if ($children->count() < 5) {
            $this->command?->warn('Run DemoScenarioSeeder first — the kiosk needs children to attach guardians to.');

            return;
        }

        // The normal path: two children, both hers to collect.
        $amira = $this->guardian('Amira Haddad', 'Mother', '481902', '4417');
        $amira->children()->sync([
            $children[0]->id => ['can_collect' => true],
            $children[1]->id => ['can_collect' => true],
        ]);

        // On the record, but not on the pickup list. The kiosk will show his
        // child and refuse the sign-out — the see-a-staff-member route.
        $daniel = $this->guardian('Daniel Okonkwo', 'Uncle', '573164', '2290');
        $daniel->children()->sync([$children[2]->id => ['can_collect' => false]]);

        // Two families who chose the same six digits. The kiosk cannot tell them
        // apart from the PIN alone and asks for the last four of a phone number:
        // 5581 is Grace, 9032 is Marcus.
        $grace = $this->guardian('Grace Mbeki', 'Mother', '246810', '5581');
        $grace->children()->sync([$children[3]->id => ['can_collect' => true]]);

        $marcus = $this->guardian('Marcus Reid', 'Father', '246810', '9032');
        $marcus->children()->sync([$children[4]->id => ['can_collect' => true]]);

        $this->command?->info('4 guardians seeded. PINs: 481902 (Amira), 573164 (Daniel, cannot collect), 246810 (Grace 5581 / Marcus 9032).');
        $this->command?->info('Set DAYCARE_KIOSK=true in .env, then open /kiosk.');
    }

    private function guardian(string $name, string $relationship, string $pin, string $last4): Guardian
    {
        // The PIN columns are written with the row rather than after it: both
        // are NOT NULL, so a save that only carries the name never lands.
        $guardian = Guardian::firstOrNew(['name' => $name]);

        $guardian->fill([
            'relationship' => $relationship,
            'phone' => '555-01'.$last4,
            'phone_last4' => $last4,
            'pin_index' => Guardian::indexFor($pin),
            'pin_hash' => Hash::make($pin),
        ])->save();

        return $guardian;
    }
}
