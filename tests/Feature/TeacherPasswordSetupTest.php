<?php

namespace Tests\Feature;

use App\Mail\TeacherWelcomeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeacherPasswordSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_teacher_form_offers_the_emailed_password_by_default(): void
    {
        $this->actingAs($this->admin())
            ->get(route('teachers.create'))
            ->assertOk()
            ->assertSee('Email a temporary password');
    }

    public function test_creating_a_teacher_emails_a_temporary_password_and_flags_the_account(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->post(route('teachers.store'), [
                'name' => 'Grace Ilagan',
                'email' => 'grace@example.com',
                'send_invite' => '1',
            ])
            ->assertRedirect(route('teachers.index'));

        $teacher = User::where('email', 'grace@example.com')->sole();

        $this->assertTrue($teacher->mustChangePassword());
        $this->assertNull($teacher->password_changed_at);

        Mail::assertSent(TeacherWelcomeMail::class, function (TeacherWelcomeMail $mail) use ($teacher) {
            // The password only ever exists in the hash and in this email, so
            // the one that was mailed has to be the one that signs in.
            return $mail->hasTo('grace@example.com')
                && Hash::check($mail->temporaryPassword, $teacher->password);
        });
    }

    public function test_a_hand_typed_password_is_used_as_is_and_sends_no_email(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->post(route('teachers.store'), [
                'name' => 'Grace Ilagan',
                'email' => 'grace@example.com',
                'send_invite' => '0',
                'password' => 'chosen-by-hand-1',
                'password_confirmation' => 'chosen-by-hand-1',
            ])
            ->assertRedirect(route('teachers.index'));

        $teacher = User::where('email', 'grace@example.com')->sole();

        $this->assertFalse($teacher->mustChangePassword());
        $this->assertTrue(Hash::check('chosen-by-hand-1', $teacher->password));

        Mail::assertNothingSent();
    }

    public function test_a_blank_password_is_rejected_when_no_invite_was_asked_for(): void
    {
        $this->actingAs($this->admin())
            ->post(route('teachers.store'), [
                'name' => 'Grace Ilagan',
                'email' => 'grace@example.com',
                'send_invite' => '0',
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'grace@example.com']);
    }

    public function test_every_page_redirects_to_the_change_form_until_the_password_is_replaced(): void
    {
        $teacher = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($teacher)->get(route('dashboard'))->assertRedirect(route('password.change'));
        $this->actingAs($teacher)->get(route('children.index'))->assertRedirect(route('password.change'));

        // The form itself, and the way out, both have to stay reachable.
        $this->actingAs($teacher)->get(route('password.change'))->assertOk();
    }

    public function test_changing_the_password_clears_the_flag_and_reopens_the_app(): void
    {
        $teacher = User::factory()->create([
            'password' => Hash::make('TEMP-PASS-1234'),
            'must_change_password' => true,
        ]);

        $this->actingAs($teacher)
            ->put(route('password.change.update'), [
                'current_password' => 'TEMP-PASS-1234',
                'password' => 'my-own-password-9',
                'password_confirmation' => 'my-own-password-9',
            ])
            ->assertRedirect(route('dashboard'));

        $teacher->refresh();

        $this->assertFalse($teacher->mustChangePassword());
        $this->assertNotNull($teacher->password_changed_at);
        $this->assertTrue(Hash::check('my-own-password-9', $teacher->password));

        $this->actingAs($teacher)->get(route('dashboard'))->assertOk();
    }

    public function test_the_temporary_password_cannot_be_kept_as_the_new_one(): void
    {
        $teacher = User::factory()->create([
            'password' => Hash::make('TEMP-PASS-1234'),
            'must_change_password' => true,
        ]);

        $this->actingAs($teacher)
            ->put(route('password.change.update'), [
                'current_password' => 'TEMP-PASS-1234',
                'password' => 'TEMP-PASS-1234',
                'password_confirmation' => 'TEMP-PASS-1234',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($teacher->refresh()->mustChangePassword());
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $teacher = User::factory()->create([
            'password' => Hash::make('TEMP-PASS-1234'),
            'must_change_password' => true,
        ]);

        $this->actingAs($teacher)
            ->put(route('password.change.update'), [
                'current_password' => 'not-the-one',
                'password' => 'my-own-password-9',
                'password_confirmation' => 'my-own-password-9',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('TEMP-PASS-1234', $teacher->refresh()->password));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }
}
