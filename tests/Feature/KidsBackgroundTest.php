<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The animated background on the two screens a family stands in front of.
 */
class KidsBackgroundTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_on_the_family_facing_screens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $child = \App\Models\Child::create([
            'lan' => '9001', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'classroom' => 'Toddler', 'status' => 'Active',
        ]);

        // The sheet, the roster, and the record a parent is shown at the door.
        $screens = [
            route('attendance.index'),
            route('children.index'),
            route('children.show', $child),
        ];

        foreach ($screens as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertSee('class="kids-bg"', false)
                ->assertSee('kids-bg-float', false);
        }
    }

    public function test_it_is_not_on_the_screens_a_family_never_sees(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Payroll does not get balloons. It is included per page rather than in
        // the layout precisely so this stays true.
        foreach (['payroll.index', 'timesheets.index', 'dashboard'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertDontSee('class="kids-bg"', false);
        }
    }

    public function test_the_decoration_is_hidden_from_assistive_technology(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Balloons announced one by one would be noise between the room filters
        // and the sheet.
        $html = $this->actingAs($admin)->get(route('children.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="kids-bg" aria-hidden="true">/', $html);
    }

    public function test_there_is_no_pause_control(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // The background is decoration end to end — nothing in it is a control.
        // The escape that matters is the system one, covered below.
        $html = $this->actingAs($admin)->get(route('children.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Pause background', $html);
        $this->assertStringNotContainsString('kids-bg-paused', $html);

        $this->assertStringNotContainsString('kidsBackground', file_get_contents(resource_path('js/app.js')));
        $this->assertStringNotContainsString('kids-bg-paused', file_get_contents(resource_path('css/app.css')));
    }

    public function test_the_navbar_lets_the_background_through(): void
    {
        $navbar = file_get_contents(resource_path('views/layouts/navbar.blade.php'));

        // No fill and no rule: a tint washes the colour out and a border draws
        // the very seam this was meant to remove.
        $this->assertDoesNotMatchRegularExpression('/<header[^>]*\bbg-\S+/', $navbar, 'the navbar still has a fill');
        $this->assertDoesNotMatchRegularExpression('/<header[^>]*\bborder-b\b/', $navbar, 'the navbar still draws a seam');

        // The blur is what makes a tintless sticky bar safe — it wrecks text
        // scrolling underneath while leaving a soft gradient nearly untouched.
        $this->assertMatchesRegularExpression('/<header[^>]*\bbackdrop-blur-xl\b/', $navbar);
    }

    public function test_the_background_reaches_the_top_of_the_screen(): void
    {
        $component = file_get_contents(resource_path('views/components/kids-background.blade.php'));

        // Two blobs and a cloud sit in the top band. Without them a transparent
        // navbar just shows an empty page and the change looks like nothing.
        preg_match_all('/top:(-?\d+)(px|%)/', $component, $matches, PREG_SET_ORDER);

        $inTopBand = array_filter($matches, function ($match) {
            [$whole, $value, $unit] = $match;

            // The bar is 80px at its tallest; anything starting above that, or
            // within the first tenth of the viewport, paints behind it.
            return $unit === 'px' ? (int) $value < 80 : (int) $value <= 10;
        });

        $this->assertGreaterThanOrEqual(3, count($inTopBand), 'nothing is painted behind the navbar');
    }


    public function test_the_kiosk_gets_the_loud_background_and_the_app_screens_do_not(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config(['daycare.kiosk.enabled' => true]);

        // Two strengths on purpose. Behind a sixty-row sheet, colour is noise;
        // on a screen with nothing else on it, seen from a metre away by a
        // four-year-old, the quiet one reads as a blank white page.
        $this->get(route('kiosk.index'))->assertOk()->assertSee('kids-bg kids-bg--strong', false);

        foreach (['attendance.index', 'children.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertDontSee('kids-bg--strong', false);
        }
    }

    public function test_nothing_floats_in_on_a_delay_somebody_would_wait_through(): void
    {
        $component = file_get_contents(resource_path('views/components/kids-background.blade.php'));

        preg_match_all('/--delay:(-?\d+)s/', $component, $matches);

        $this->assertNotEmpty($matches[1]);

        // Negative, so every float starts mid-flight. A positive delay means an
        // empty sky for its first fifteen seconds — and somebody walking up to a
        // kiosk sees exactly those fifteen seconds.
        foreach ($matches[1] as $delay) {
            $this->assertLessThanOrEqual(0, (int) $delay, 'a float starts late enough to leave the sky empty');
        }
    }

    public function test_the_stylesheet_answers_a_request_for_less_motion(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{[^}]*\.kids-bg \* \{ animation: none !important; \}/s',
            $css
        );

        // The mockup placed the resting floats with calc(10% + var(--delay) * 4),
        // which multiplies a time by a number and is therefore not a length —
        // the declaration is dropped and they stack in one corner.
        $this->assertStringNotContainsString('var(--delay) * 4', $css);
        $this->assertStringContainsString('top: var(--rest, 20%)', $css);
    }

    public function test_the_background_is_themed_rather_than_always_daylight(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // The cards are 70% opaque. A white page behind them in dark mode would
        // light them from the back.
        $this->assertStringContainsString('.dark .kids-bg {', $css);
        $this->assertStringContainsString('var(--color-night-950)', $css);
    }
}
