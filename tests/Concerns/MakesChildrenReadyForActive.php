<?php

namespace Tests\Concerns;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;

/**
 * Active needs a date of birth, a guardian and an emergency contact
 * (Child::ACTIVE_REQUIRES). A fixture that is made Active through the form,
 * Start or Re-enroll — where the point of the test is something else — is
 * given those here rather than each test typing them out.
 */
trait MakesChildrenReadyForActive
{
    /** A child on file given a date of birth (if missing) and a guardian who is also the emergency contact. */
    protected function readyForActive(Child $child): Child
    {
        if ($child->birthDate() === null) {
            $child->forceFill(['birth_date' => '2023-05-01', 'dob' => '2023-05-01'])->save();
        }

        $person = Person::create(['name' => 'Ready Guardian '.$child->getKey(), 'cell' => '5550100'.str_pad((string) $child->getKey(), 3, '0', STR_PAD_LEFT)]);
        ChildPerson::create([
            'child_id' => $child->getKey(),
            'person_id' => $person->getKey(),
            'relationship' => 'Mother',
            'is_guardian' => true,
            'is_emergency' => true,
            'priority' => 1,
        ]);

        return $child->unsetRelation('personLinks');
    }

    /** What the Add form needs to carry for a child added as Active. */
    protected function activeFormFields(array $overrides = []): array
    {
        return $overrides + [
            'birth_date' => '2023-05-01',
            'mother_name' => 'Ready Mother',
            'emergency_contact' => 'Ready Mother',
        ];
    }
}
