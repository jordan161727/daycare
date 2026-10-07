<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Staff photographs come out through a route, not a symlink.
 *
 * public/storage has to be created on every server and allowed by its web
 * server; on staging it was not, and every face in the app went blank. The
 * route streams the file through PHP, so a new server needs nothing set up.
 */
class StaffAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_the_avatar_address_is_a_route_stamped_with_the_file(): void
    {
        $user = User::factory()->create(['role' => 'teacher', 'avatar_path' => 'avatars/ada.svg']);
        Storage::disk('public')->put('avatars/ada.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $url = $user->avatar_url;

        $this->assertStringContainsString('/avatars/'.$user->id.'?v=', $url);
        $this->assertStringNotContainsString('/storage/', $url);
    }

    public function test_the_avatar_is_streamed_to_a_signed_in_user(): void
    {
        $user = User::factory()->create(['role' => 'teacher', 'avatar_path' => 'avatars/ada.svg']);
        Storage::disk('public')->put('avatars/ada.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get($user->avatar_url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, private');
    }

    public function test_a_user_without_a_photo_has_no_address_and_nothing_to_serve(): void
    {
        $user = User::factory()->create(['role' => 'teacher', 'avatar_path' => null]);

        $this->assertNull($user->avatar_url);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('avatars.show', $user))
            ->assertNotFound();
    }

    public function test_signed_out_it_leads_to_the_login(): void
    {
        $user = User::factory()->create(['role' => 'teacher', 'avatar_path' => 'avatars/ada.svg']);
        Storage::disk('public')->put('avatars/ada.svg', '<svg/>');

        $this->get(route('avatars.show', $user))->assertRedirect(route('login'));
    }
}
