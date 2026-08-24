<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_can_open_their_own_profile(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Anna Reyes']);

        $this->actingAs($teacher)->get(route('profile.edit'))->assertOk()->assertSee('Anna Reyes');
    }

    public function test_signed_out_visitors_are_sent_to_the_login_page(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    public function test_a_user_can_update_their_own_details(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => 'New Name',
                'email' => 'new@example.com',
                'phone' => '0917 555 0100',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('0917 555 0100', $user->phone);
    }

    public function test_a_user_can_record_their_emergency_contact_birthday_and_transport(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '555-0142',
            'emergency_contact' => 'Rosa Santos (sister)',
            'emergency_phone' => '555-0199',
            'dob' => '1994-03-17',
            'transport' => 'Own car',
        ])->assertRedirect(route('profile.edit'))->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('555-0142', $user->phone);
        $this->assertSame('Rosa Santos (sister)', $user->emergency_contact);
        $this->assertSame('555-0199', $user->emergency_phone);
        $this->assertSame('1994-03-17', $user->dob->toDateString());
        $this->assertSame('Own car', $user->transport);
    }

    public function test_the_new_details_show_on_the_profile_page(): void
    {
        $user = User::factory()->create([
            'emergency_contact' => 'Rosa Santos (sister)',
            'emergency_phone' => '555-0199',
            'dob' => '1994-03-17',
            'transport' => 'Own car',
        ]);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertSee('Rosa Santos (sister)', false)
            ->assertSee('555-0199')
            ->assertSee('1994-03-17')
            ->assertSee('Own car');
    }

    public function test_employment_details_are_shown_but_not_editable(): void
    {
        $user = User::factory()->create([
            'role' => 'teacher',
            'employment' => 'FT',
            'title' => 'Infant',
            'legal_name' => 'Maria G. Santos',
            'start_date' => '2024-08-01',
            'aspire_id' => 'A-11423',
        ]);

        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertSee('Full time')
            ->assertSee('Maria G. Santos')
            ->assertSee('Aug 1, 2024')
            ->assertSee('A-11423')
            ->getContent();

        // Read-only means read-only: no inputs carrying those names.
        foreach (['employment', 'legal_name', 'start_date', 'aspire_id'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html);
        }
    }

    public function test_the_profile_form_cannot_change_employment_details(): void
    {
        $user = User::factory()->create(['employment' => 'PT', 'legal_name' => 'Maria G. Santos', 'aspire_id' => 'A-11423']);

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'employment' => 'FT_SALARY',
            'legal_name' => 'Someone Else',
            'aspire_id' => 'A-99999',
        ])->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('PT', $user->employment);
        $this->assertSame('Maria G. Santos', $user->legal_name);
        $this->assertSame('A-11423', $user->aspire_id);
    }

    public function test_a_birthday_in_the_future_is_rejected(): void
    {
        $user = User::factory()->create(['dob' => '1994-03-17']);

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'dob' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('dob');

        $this->assertSame('1994-03-17', $user->fresh()->dob->toDateString());
    }

    public function test_the_profile_form_cannot_change_a_role_or_classroom_assignment(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'classrooms' => ['Toddlers'], 'classroom' => 'Toddlers']);

        $this->actingAs($teacher)->put(route('profile.update'), [
            'name' => 'Anna Reyes',
            'email' => 'anna@example.com',
            'role' => 'admin',
            'classrooms' => ['Preschool'],
            'classroom' => 'Preschool',
        ])->assertRedirect(route('profile.edit'));

        $teacher->refresh();
        $this->assertSame('teacher', $teacher->role);
        $this->assertSame(['Toddlers'], $teacher->assignedClassrooms());
    }

    public function test_an_email_already_taken_by_someone_else_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'mine@example.com']);

        $this->actingAs($user)
            ->put(route('profile.update'), ['name' => 'Mine', 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertSame('mine@example.com', $user->fresh()->email);
    }

    public function test_a_user_can_upload_a_photo_and_it_replaces_the_previous_one(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ])->assertRedirect(route('profile.edit'));

        $first = $user->fresh()->avatar_path;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->image('newer.png'),
        ]);

        $second = $user->fresh()->avatar_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_document_is_not_accepted_as_a_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->create('roster.pdf', 40, 'application/pdf'),
        ])->assertSessionHasErrors('photo');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_removing_a_photo_deletes_the_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ]);

        $path = $user->fresh()->avatar_path;

        $this->actingAs($user)->delete(route('profile.photo.destroy'))->assertRedirect(route('profile.edit'));

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_user_can_change_their_password_with_the_current_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => 'old-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect(route('profile.edit'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_the_wrong_current_password_leaves_the_old_one_in_place(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => 'guessing',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_the_photo_url_follows_the_host_the_page_was_served_from(): void
    {
        Storage::fake('public');
        // The deployed address, as .env carries it while working locally.
        config(['app.url' => 'https://live-site.test', 'filesystems.disks.public.url' => 'https://live-site.test/storage']);

        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ]);

        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('/storage/'.$user->fresh()->avatar_path, $html);
        $this->assertStringNotContainsString('live-site.test', $html);
    }

    public function test_initials_stand_in_until_a_photo_is_uploaded(): void
    {
        $user = User::factory()->create(['name' => 'Anna Reyes', 'avatar_path' => null]);

        $this->assertSame('AR', $user->initials);
        $this->assertNull($user->avatar_url);
    }
}
