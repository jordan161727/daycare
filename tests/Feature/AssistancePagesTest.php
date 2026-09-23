<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The two public pages about help paying for day care.
 *
 * What matters about these is the part that is easy to break from a distance:
 * they are reachable signed out. Every other route in this app is behind auth,
 * so a later sweep that wraps more of web.php in a middleware group would shut
 * the front door on parents without anyone noticing for months.
 *
 * The rest checks the wording that took the longest to get right — the plain
 * phrase over the programme's own name, and the disclaimer that says who
 * actually decides — plus that the income table is rendered from the constant
 * rather than typed into the markup again.
 */
class AssistancePagesTest extends TestCase
{
    public function test_hub_is_reachable_signed_out(): void
    {
        $response = $this->get('/child-care-assistance');

        $response->assertOk();
        $response->assertSee('Help paying for', false);
        $response->assertSee('I\'ve never applied before', false);
    }

    public function test_hub_links_to_the_first_time_guide(): void
    {
        $this->get('/child-care-assistance')
            ->assertOk()
            ->assertSee(route('assistance.apply'), false);
    }

    public function test_apply_page_is_reachable_signed_out(): void
    {
        $response = $this->get('/child-care-assistance/apply');

        $response->assertOk();
        $response->assertSee('Gather your paperwork');
        $response->assertSee('Go to the Erie County office');
    }

    public function test_apply_page_prints_the_dated_income_limits(): void
    {
        $response = $this->get('/child-care-assistance/apply');

        $response->assertSee('June 1, 2026');
        $response->assertSee('$60,576.10', false);
        $response->assertSee('$153,770.10', false);
    }

    public function test_pages_say_the_county_decides_not_us(): void
    {
        foreach (['/child-care-assistance', '/child-care-assistance/apply'] as $path) {
            $this->get($path)->assertSee('Erie County, not', false);
        }
    }
}
