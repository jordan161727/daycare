<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bar across the top of every page.
 *
 * One line, and only as tall as that line needs. It used to greet you by the
 * hour and stack your name under that, which cost two lines and eighty pixels
 * at the top of every page — on the attendance sheet, eighty pixels is two
 * children you cannot see.
 */
class HeaderBarTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bar_does_not_greet_you_by_the_clock(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin', 'name' => 'Administrator']))
            ->get(route('attendance.index'))
            ->assertOk();

        // A window and a clock already said it.
        foreach (['Good morning', 'Good afternoon', 'Good evening'] as $greeting) {
            $response->assertDontSee($greeting);
        }

        // The name stays — it is what says which account is signed in.
        $response->assertSee('Administrator');
    }

    public function test_the_bar_is_one_line_and_sized_to_it(): void
    {
        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // Half the height it was, and the page starts right under it rather
        // than leaving a gap on top of the first card's own padding.
        $this->assertStringContainsString('h-10 items-center justify-between px-4 backdrop-blur-xl sm:px-6 lg:h-12', $html);

        // Flush: the first card carries its own padding, and the bar is blurred
        // rather than filled, so content sliding under it needs no runway.
        $this->assertStringContainsString('pb-5 pt-0 sm:px-6 lg:px-8 lg:pb-6', $html);

        // The two stacked lines are gone with it.
        $this->assertStringNotContainsString('h-16 items-center justify-between lg:h-20', $html);
    }
}
