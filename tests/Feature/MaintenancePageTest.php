<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page shown while the site is down for a deploy.
 *
 * Laravel renders errors/503 once when `artisan down` runs and serves the
 * file after that, so the page has to stand on its own: the centre's name,
 * a plain sentence, and a way to reach somebody — or, where no number is
 * configured, a word to the teachers at the door instead of a blank box.
 */
class MaintenancePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_the_centres_own_page(): void
    {
        config(['daycare.company.phone' => '0917 000 0000', 'daycare.company.email' => 'hello@example.com']);

        $html = view('errors.503')->render();

        $this->assertStringContainsString('Little Angels Day Care Center', $html);
        $this->assertStringContainsString('taking a little nap', $html);
        $this->assertStringContainsString('href="tel:09170000000"', $html);
        $this->assertStringContainsString('href="mailto:hello@example.com"', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringNotContainsString('Little Sprouts', $html);
    }

    public function test_a_missing_page_gets_the_centres_own_404(): void
    {
        config(['daycare.company.phone' => '0917 000 0000', 'daycare.company.email' => null]);

        $response = $this->get('/this-page-does-not-exist')->assertNotFound();

        $response->assertSee('Error 404')
            ->assertSee("We can't find that page", escape: false)
            ->assertSee('Little Angels Day Care Center')
            ->assertSee('Go to homepage')
            ->assertSee('href="tel:09170000000"', escape: false)
            ->assertDontSee('mailto:')
            ->assertDontSee('Little Sprouts');

        // Signed in, the way home is the dashboard.
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']))
            ->get('/nor-this-one')->assertNotFound()->assertSee('Go to my dashboard');
    }

    public function test_a_fault_on_our_side_gets_the_centres_own_500(): void
    {
        config(['daycare.company.phone' => '0917 000 0000', 'daycare.company.email' => 'hello@example.com']);

        $html = view('errors.500')->render();

        $this->assertStringContainsString('Error 500', $html);
        $this->assertStringContainsString('our blocks tumbled over', $html);
        $this->assertStringContainsString('Little Angels Day Care Center', $html);
        $this->assertStringContainsString('href="tel:09170000000"', $html);
        $this->assertStringContainsString('href="mailto:hello@example.com"', $html);
        // Nothing here may lean on who is signed in: the session may be what broke.
        $this->assertStringNotContainsString('dashboard', $html);
        $this->assertStringNotContainsString('Little Sprouts', $html);
    }

    public function test_without_contact_details_it_speaks_to_the_teachers_instead(): void
    {
        config(['daycare.company.phone' => null, 'daycare.company.email' => null]);

        $html = view('errors.503')->render();

        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringContainsString('Sign children in on paper', $html);
    }
}
