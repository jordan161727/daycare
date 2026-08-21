<?php

namespace Tests\Unit;

use App\Services\TemporaryPassword;
use Tests\TestCase;

/**
 * The first password on a staff account.
 *
 * Its whole job is to survive being read off a screen and typed into a phone,
 * so the properties worth pinning are legibility ones as much as strength ones.
 */
class TemporaryPasswordTest extends TestCase
{
    public function test_it_reads_as_three_groups_of_four(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', TemporaryPassword::generate());
    }

    public function test_the_group_count_is_adjustable(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', TemporaryPassword::generate(2));
        $this->assertMatchesRegularExpression('/^([A-Z2-9]{4}-){4}[A-Z2-9]{4}$/', TemporaryPassword::generate(5));
    }

    /**
     * The characters people get wrong when reading aloud or retyping.
     *
     * A password nobody can transcribe is a support call, and the support call
     * ends with somebody being told their password over the phone.
     */
    public function test_it_never_contains_a_character_mistakable_for_another(): void
    {
        $everything = '';

        for ($i = 0; $i < 200; $i++) {
            $everything .= TemporaryPassword::generate();
        }

        foreach (['0', 'O', '1', 'I', 'L'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, $everything, "Generated a password containing '{$ambiguous}'.");
        }
    }

    public function test_it_is_long_enough_for_the_rule_the_forms_enforce(): void
    {
        // Both the teacher form and the change-password form demand 8, so a
        // generated password that failed it would lock the account on arrival.
        $this->assertGreaterThanOrEqual(8, strlen(TemporaryPassword::generate(2)));
    }

    public function test_two_accounts_opened_together_do_not_share_a_password(): void
    {
        $generated = [];

        for ($i = 0; $i < 50; $i++) {
            $generated[] = TemporaryPassword::generate();
        }

        $this->assertCount(50, array_unique($generated));
    }
}
