<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The export is never kept by the browser.
 *
 * It went out as a file response, which stamps itself Cache-Control: public
 * with a Last-Modified date, from the same address every day. A browser may
 * keep such a response and hand it back without asking — so a director
 * downloaded the roll, corrected a child, downloaded again and opened the
 * old file, while a private window always got the new one.
 */
class ChildrenExportIsNeverCachedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_export_response_tells_the_browser_to_keep_nothing(): void
    {
        $this->child();

        $response = $this->actingAs($this->admin())->get(route('children.export'))->assertOk();

        // Symfony re-emits the directives sorted and adds private; what
        // matters is that every one asked for is there.
        $sent = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        foreach (['no-store', 'no-cache', 'must-revalidate', 'max-age=0'] as $directive) {
            $this->assertContains($directive, $sent, "Cache-Control lacks $directive");
        }
        $this->assertNotContains('public', $sent);
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));
        $this->assertNull($response->headers->get('Last-Modified'));
        $this->assertStringStartsWith('attachment; filename=children-', $response->headers->get('Content-Disposition'));
    }

    public function test_the_export_link_changes_every_time_the_roll_is_drawn(): void
    {
        $this->child();
        $admin = $this->admin();

        $this->travelTo(now()->setTime(9, 0, 0));
        $first = $this->exportLink($this->actingAs($admin)->get(route('children.index'))->assertOk()->getContent());

        $this->travelTo(now()->addSecond());
        $second = $this->exportLink($this->actingAs($admin)->get(route('children.index'))->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/[?&]t=\d+/', $first);
        $this->assertNotSame($first, $second, 'The same address twice is an address a browser may answer from its cache.');

        // The timestamp changes nothing about what is exported: the filter
        // and the sort still travel, and the file is still the roll.
        $this->assertStringContainsString('children/export', $first);
        $this->actingAs($admin)->get($first)->assertOk();
    }

    private function child(): Child
    {
        return Child::create([
            'lan' => '1001', 'status' => 'Active', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'classroom' => 'Toddler', 'birth_date' => '2024-03-02',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function exportLink(string $html): string
    {
        $this->assertSame(1, preg_match('/href="([^"]*children\/export[^"]*)"/', $html, $m));

        return html_entity_decode($m[1]);
    }
}
