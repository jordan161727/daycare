<?php

namespace Database\Seeders;

use App\Models\StaffRule;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A staff room you can walk through by hand.
 *
 * Eight people, chosen so that every rule type that changes the roster is
 * exercised by somebody, and so that the interesting failures happen on their
 * own rather than being staged: PreK loses its only teacher at midday,
 * Transition opens two hours before its teacher may start, and the one
 * substitute is unavailable on the day the gaps are widest.
 *
 * Run DemoScenarioSeeder first. The ratio checks read the children's booked
 * week, so without a roster of children every room reads as needing nobody and
 * the coverage bar has nothing to say.
 */
class StaffSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The staff room.
     *
     * Legal names deliberately differ from display names — payroll prints the
     * legal one, and matching on it is the part most likely to break quietly.
     */
    private const STAFF = [
        [
            'name' => 'Maria Santos', 'legal_name' => 'Maria G. Santos', 'employment' => 'LEAD',
            'title' => 'Infant', 'phone' => '555-0142', 'emergency_contact' => 'Jose Santos',
            'emergency_phone' => '555-0188', 'start_date' => '2018-03-05', 'dob' => '1988-06-14',
            'transport' => 'Own car — 10 min drive', 'aspire_id' => 'A1042', 'direct_deposit' => true,
            'pay_rate' => 19.50, 'evaluation_score' => 4.6, 'staff_notes' => 'Lead infant teacher. Keyholder.',
            'rules' => [
                ['CAN_OPEN', 'HARD', [], 'Keyholder.'],
                ['REQUIRED_HOURS', 'HARD', ['number' => 40, 'value_text' => 'WEEKLY'], null],
                ['ROOM_PREFERENCE', 'SOFT', ['value_text' => 'Infant'], null],
            ],
        ],
        [
            'name' => 'Emily Carter', 'legal_name' => 'Emily R. Carter', 'employment' => 'FT',
            'title' => 'Toddler', 'phone' => '555-0110', 'emergency_contact' => 'Dana Carter',
            'emergency_phone' => '555-0111', 'start_date' => '2020-08-17', 'dob' => '1994-02-02',
            'transport' => 'Bus', 'aspire_id' => 'A1088', 'direct_deposit' => true,
            'pay_rate' => 16.75, 'evaluation_score' => 4.1, 'staff_notes' => null,
            'rules' => [
                ['REQUIRED_HOURS', 'HARD', ['number' => 40, 'value_text' => 'WEEKLY'], null],
                ['PREFERRED_DAY_OFF', 'SOFT', ['day' => 'FRI'], 'Asked for Fridays where possible.'],
            ],
        ],
        [
            // Contract hours: the one person whose shift the solver may not move.
            'name' => 'Aisha Khan', 'legal_name' => 'Aisha N. Khan', 'employment' => 'FT',
            'title' => 'UPK-4', 'phone' => '555-0166', 'emergency_contact' => 'Sana Khan',
            'emergency_phone' => '555-0177', 'start_date' => '2019-01-14', 'dob' => '1991-11-23',
            'transport' => 'Own car', 'aspire_id' => 'A1055', 'direct_deposit' => true,
            'pay_rate' => 17.25, 'evaluation_score' => 4.4, 'staff_notes' => 'UPK-4 lead.',
            'rules' => [
                ['FIXED_SHIFT', 'HARD', ['day' => 'ALL', 'time_1' => 8 * 60, 'time_2' => 16 * 60], 'UPK contract hours.'],
                ['ROOM_PREFERENCE', 'SOFT', ['value_text' => 'UPK-4'], null],
            ],
        ],
        [
            // Mornings only, which is what leaves PreK uncovered after midday.
            'name' => 'Grace Lee', 'legal_name' => 'Grace Y. Lee', 'employment' => 'PT',
            'title' => 'PreK', 'phone' => '555-0133', 'emergency_contact' => 'Paul Lee',
            'emergency_phone' => '555-0144', 'start_date' => '2022-09-06', 'dob' => '2000-04-19',
            'transport' => 'Walks', 'aspire_id' => 'A1120', 'direct_deposit' => false,
            'pay_rate' => 15.50, 'evaluation_score' => 3.9, 'staff_notes' => 'Student — mornings only.',
            'rules' => [
                ['AVAILABLE_WINDOW', 'HARD', ['day' => 'ALL', 'time_1' => 7 * 60, 'time_2' => 12 * 60], 'Classes in the afternoon.'],
                ['REQUIRED_HOURS', 'HARD', ['number' => 20, 'value_text' => 'WEEKLY'], null],
            ],
        ],
        [
            'name' => 'David Nguyen', 'legal_name' => 'David T. Nguyen', 'employment' => 'FT',
            'title' => 'School Age', 'phone' => '555-0121', 'emergency_contact' => 'Linh Nguyen',
            'emergency_phone' => '555-0122', 'start_date' => '2021-06-01', 'dob' => '1996-09-30',
            'transport' => 'Own car', 'aspire_id' => 'A1099', 'direct_deposit' => true,
            'pay_rate' => 16.00, 'evaluation_score' => 4.0, 'staff_notes' => 'Designated closer.',
            'rules' => [
                ['FIXED_END', 'HARD', ['day' => 'ALL', 'time_1' => 18 * 60], 'Designated closer.'],
                ['REQUIRED_HOURS', 'HARD', ['number' => 40, 'value_text' => 'WEEKLY'], null],
                ['ROOM_PREFERENCE', 'SOFT', ['value_text' => 'School Age'], null],
            ],
        ],
        [
            // New, so never alone — and paired against the other new starter.
            'name' => 'Sofia Reyes', 'legal_name' => 'Sofia M. Reyes', 'employment' => 'PT',
            'title' => 'Transition', 'phone' => '555-0155', 'emergency_contact' => 'Ana Reyes',
            'emergency_phone' => '555-0166', 'start_date' => '2023-02-13', 'dob' => '2002-12-05',
            'transport' => 'Bus', 'aspire_id' => 'A1140', 'direct_deposit' => false,
            'pay_rate' => 15.00, 'evaluation_score' => 3.8, 'staff_notes' => 'New hire — needs supervision.',
            'rules' => [
                ['NEEDS_SUPERVISION', 'HARD', [], 'Under six months tenure.'],
                ['AVAILABLE_AFTER', 'HARD', ['day' => 'ALL', 'time_1' => 9 * 60], null],
                ['REQUIRED_HOURS', 'HARD', ['number' => 24, 'value_text' => 'WEEKLY'], null],
                ['NO_PAIR', 'SOFT', ['value_text' => 'Olivia Turner'], 'Two new or floating staff should not be left alone together.'],
            ],
        ],
        [
            'name' => 'Hannah Brooks', 'legal_name' => 'Hannah J. Brooks', 'employment' => 'FT',
            'title' => 'Toddler', 'phone' => '555-0177', 'emergency_contact' => 'Rob Brooks',
            'emergency_phone' => '555-0178', 'start_date' => '2017-11-20', 'dob' => '1990-07-08',
            'transport' => 'Own car', 'aspire_id' => 'A1020', 'direct_deposit' => true,
            'pay_rate' => 18.00, 'evaluation_score' => 4.5, 'staff_notes' => 'Keyholder.',
            'rules' => [
                ['CAN_OPEN', 'HARD', [], 'Keyholder.'],
                ['REQUIRED_HOURS', 'HARD', ['number' => 40, 'value_text' => 'WEEKLY'], null],
                ['PREFERRED_START', 'SOFT', ['time_1' => 7 * 60], null],
            ],
        ],
        [
            // The only substitute, and off on Wednesday — so a Wednesday gap has
            // nobody obvious to fill it, which is the case worth seeing.
            'name' => 'Olivia Turner', 'legal_name' => 'Olivia K. Turner', 'employment' => 'SUB',
            'title' => null, 'phone' => '555-0190', 'emergency_contact' => 'Meg Turner',
            'emergency_phone' => '555-0191', 'start_date' => '2024-01-08', 'dob' => '1998-03-27',
            'transport' => 'Own car', 'aspire_id' => 'A1200', 'direct_deposit' => false,
            'pay_rate' => 14.50, 'evaluation_score' => null, 'staff_notes' => 'Floating substitute.',
            'rules' => [
                ['MAX_HOURS', 'SOFT', [], 'Give hours wherever they are needed.'],
                ['UNAVAILABLE_DAY', 'HARD', ['day' => 'WED'], null],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::STAFF as $person) {
            $rules = $person['rules'];
            unset($person['rules']);

            $teacher = User::updateOrCreate(
                ['email' => $this->emailFor($person['name'])],
                $person + [
                    'password' => Hash::make('password'),
                    'role' => 'teacher',
                    'classroom' => $person['title'],
                    'classrooms' => $person['title'] ? [$person['title']] : [],
                ]
            );

            // Re-running the seeder must not stack duplicate rules on somebody
            // who already has them.
            $teacher->staffRules()->delete();

            foreach ($rules as [$type, $priority, $fields, $note]) {
                $teacher->staffRules()->create($fields + [
                    'rule_type' => $type,
                    'priority' => $priority,
                    'source_note' => $note,
                ]);
            }
        }

        $this->command?->info('Seeded '.count(self::STAFF).' staff with '.StaffRule::count().' scheduling rules.');

        // The per-room logins DatabaseSeeder creates stay exactly as they are.
        // They have no employment type, and the generator treats that as "no
        // contract, no shift", so they keep working for the room-visibility
        // checks in walkthrough.md without ever reaching the roster. That used
        // to be done by giving them an UNAVAILABLE_DAY rule, which anybody
        // could delete from the rules screen without knowing what it was for.
    }

    private function emailFor(string $name): string
    {
        return str($name)->lower()->replace(' ', '.').'@daycare.test';
    }
}
