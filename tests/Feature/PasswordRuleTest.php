<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * One password rule everywhere (QA, 16 Nov 2026): Password::defaults(), at
 * least twelve characters with letters and numbers.
 */
class PasswordRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_rule_is_twelve_with_letters_and_numbers(): void
    {
        $check = fn (string $p) => Validator::make(['p' => $p], ['p' => Password::defaults()])->passes();

        $this->assertFalse($check('abcdefgh123'));      // 11
        $this->assertFalse($check('abcdefghijkl'));     // no number
        $this->assertFalse($check('123456789012'));     // no letter
        $this->assertTrue($check('abcdefghi123'));
    }

    public function test_the_profile_refuses_an_eight_character_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => 'old-password', 'password' => 'short123', 'password_confirmation' => 'short123',
        ])->assertSessionHasErrors('password');

        $this->assertStringContainsString('At least 12 characters', $this->actingAs($user)->get(route('profile.edit'))->getContent());
    }

    public function test_the_director_cannot_set_a_short_password_from_the_staff_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($admin)->post(route('staff.password', $teacher), [
            'password' => 'eightch1', 'password_confirmation' => 'eightch1',
        ])->assertSessionHasErrors('password');

        $this->actingAs($admin)->post(route('staff.password', $teacher), [
            'password' => 'twelve-chars-1', 'password_confirmation' => 'twelve-chars-1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('twelve-chars-1', $teacher->fresh()->password));

        $html = $this->actingAs($admin)->get(route('staff.show', $teacher))->getContent();
        $this->assertStringContainsString('name="password" minlength="12"', $html);
        $this->assertStringNotContainsString('minlength="8"', $html);
    }
}
