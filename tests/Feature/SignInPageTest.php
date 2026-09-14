<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sign-in page: the first screen anybody sees, and the only one shown to
 * somebody who is not signed in.
 */
class SignInPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_form_says_it_is_working_and_stops_taking_clicks(): void
    {
        // A sign-in is a round trip. Without this the button sits there looking
        // untouched, which is how one click becomes three.
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('x-data="{ sending: false }"', false)
            ->assertSee('@submit="sending = true"', false)
            ->assertSee(':disabled="sending"', false)
            ->assertSee("sending ? 'Signing in…' : 'Sign in'", false);
    }

    public function test_the_column_arrives_in_order_rather_than_all_at_once(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        // Four beats: mark, heading, form, the two ways in.
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'auth-rise'));

        foreach (['.06s', '.12s', '.18s', '.24s'] as $delay) {
            $this->assertStringContainsString('animation-delay:'.$delay, $html);
        }
    }

    public function test_nobody_is_made_to_watch_the_motion(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // The entrance is decoration. Somebody who has asked their system for
        // less of it gets the page in its finished state instead.
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{\s*\.auth-rise\s*\{\s*animation: none/',
            $css
        );
    }

    public function test_an_autofilled_password_is_still_legible_after_dark(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $html = $this->get(route('login'))->assertOk()->getContent();

        // The browser paints its own background over an autofilled field and
        // ignores ours — which in dark mode left white text on a white slab.
        $this->assertStringContainsString('.dark .auth-field:-webkit-autofill', $css);
        $this->assertSame(2, substr_count($html, 'auth-field'), 'both fields a manager fills in');
    }

    /**
     * Day and night are two pictures, not one picture dimmed. A balloon bobbing
     * over a midnight sky is what a half-done theme looks like.
     */
    public function test_the_night_sky_swaps_the_cast_rather_than_dimming_it(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $html = $this->get(route('login'))->assertOk()->getContent();

        // Both casts are drawn, and the theme picks one.
        foreach (['sky-balloon', 'sky-birds', 'sky-lantern', 'sky-shoot'] as $piece) {
            $this->assertStringContainsString($piece, $html);
        }

        // The four daytime pieces come off after dark…
        foreach (['sun', 'rainbow', 'birds', 'balloon'] as $piece) {
            $this->assertMatchesRegularExpression(
                '/\.dark \.sky-'.$piece.'[,\s][^{]*\{[^}]*display: none/s',
                $css,
                "sky-{$piece} is still on stage at midnight"
            );
        }

        // …and the night pieces are hidden by day, shown after dark.
        foreach (['moon', 'stars', 'shoot', 'lantern'] as $piece) {
            $this->assertMatchesRegularExpression('/\.sky-'.$piece.'[,\s][^{]*\{[^}]*display: none/s', $css);
            $this->assertMatchesRegularExpression('/\.dark \.sky-'.$piece.'[,\s][^{]*\{[^}]*display: block/s', $css);
        }
    }

    public function test_the_shooting_star_goes_when_the_motion_does(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // Everything else in the panel has a resting arrangement. This one is
        // the movement, so held still it is a dot with a smear behind it.
        $reduced = strrpos($css, '@media (prefers-reduced-motion: reduce)');

        $this->assertNotFalse($reduced);
        $this->assertStringContainsString(
            '.dark .sky-shoot { display: none; }',
            substr($css, $reduced),
            'the streak is left frozen mid-sky when motion is turned off'
        );
    }

    /**
     * Three lines: what it is, the promise, and one sentence.
     *
     * The panel is read in the two seconds it takes to type a password. A list
     * of everything the register can do is a list nobody has read since the
     * first morning, so the feature lines came off and the subhead carries it.
     */
    public function test_the_panel_says_one_thing_rather_than_everything(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $response->assertSee('Daycare Attendance');
        $response->assertSee('Little arrivals. Big peace of mind.');
        $response->assertSee('Every hello, remembered.');

        // The feature list is gone, not merely shortened.
        $response->assertDontSee('A week at a glance');
        $response->assertDontSee('Put right, not papered over');
        $response->assertDontSee('The month on one page');
        $response->assertDontSee('not the clipboard');
    }

    /**
     * The subhead is one of four tones, chosen by a word.
     *
     * The same product reads differently depending on who is standing at the
     * screen — a parent wants to know the child arrived, a teacher wants the
     * sheet gone, a director wants something to hand licensing. Keeping the
     * alternates beside the chosen one is what makes trying another cheap.
     */
    public function test_the_subhead_can_be_swapped_between_audiences(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();
        $view = file_get_contents(resource_path('views/auth/login.blade.php'));

        foreach (['default', 'parent', 'staff', 'compliance', 'short'] as $tone) {
            $this->assertStringContainsString("'".$tone."' =>", $view, $tone.' is not on offer');
        }

        // One of them is on the page, and only one.
        $this->assertStringContainsString('Every hello, remembered.', $html);
        $this->assertStringNotContainsString('Everyone accounted for.', $html);
        $this->assertStringNotContainsString('whenever licensing asks', $html);
    }

    public function test_the_page_carries_the_illustrated_half_on_a_laptop(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('sky-panel', false)
            // It is decoration under real content: nothing in it is offered to
            // a screen reader or reachable with a pointer.
            ->assertSee('pointer-events-none absolute inset-0" aria-hidden="true"', false);
    }
}
