<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_is_a_drawer_until_the_desktop_breakpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        // Off-canvas by default, pinned only under the `desktop` variant.
        $this->assertStringContainsString('-translate-x-full desktop:translate-x-0', $html);
        $this->assertStringContainsString('desktop:sticky', $html);
        $this->assertStringContainsString('desktop:h-screen', $html);

        // The hamburger is the only way in below that breakpoint.
        $this->assertStringContainsString('@click="mobileOpen = true"', $html);
        $this->assertStringContainsString('desktop:hidden', $html);

        // The old width-only breakpoint is gone, so tablets never pin the sidebar.
        $this->assertStringNotContainsString('xl:translate-x-0', $html);
        $this->assertStringNotContainsString('xl:sticky', $html);
    }

    /**
     * A director carries fourteen links. They have to be able to run past the
     * bottom of the screen without taking the account block and the way out
     * with them.
     */
    public function test_the_links_scroll_and_the_account_block_stays_put(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        // All three are needed: a flex child does not shrink below its content
        // without min-h-0, and without shrinking there is nothing to scroll.
        $this->assertMatchesRegularExpression('/<nav class="[^"]*min-h-0[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/<nav class="[^"]*flex-1[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/<nav class="[^"]*overflow-y-auto[^"]*"/', $html);

        // And the account block sits after the nav closes, not inside it, so
        // scrolling the links never scrolls the way out off the screen.
        $navEnds = strpos($html, '</nav>');
        $this->assertNotFalse($navEnds);
        $this->assertGreaterThan($navEnds, strpos($html, 'aria-label="Log out"'));
    }

    public function test_the_sidebar_counts_the_leave_requests_waiting_on_a_director(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        // Nothing waiting: the link is there, the badge is not.
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Leave Requests')
            // The badge's colour, not its padding: the sidebar is laid out
            // more than once a year and this test is about the count.
            ->assertDontSee('rounded-full bg-amber-500', escape: false);

        foreach (['2026-09-01', '2026-09-08'] as $date) {
            LeaveRequest::create([
                'user_id' => $teacher->id,
                'leave_type' => 'VACATION',
                'starts_on' => $date,
                'ends_on' => $date,
                'hours_per_day' => 8,
                'status' => LeaveRequest::STATUS_PENDING,
            ]);
        }

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('rounded-full bg-amber-500', $html);
        $this->assertMatchesRegularExpression('/bg-amber-500[^>]*>2</', $html);

        // A teacher has no queue to be counted at, so neither link is theirs.
        $this->actingAs($teacher)->get(route('dashboard'))->assertOk()
            ->assertSee('My Leave')
            ->assertDontSee('Leave Requests');
    }

    public function test_the_desktop_variant_requires_width_and_a_fine_pointer(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            '@custom-variant desktop (@media (min-width: 1441px) or ((min-width: 1280px) and (pointer: fine)));',
            $css,
            'a touch device must keep the drawer no matter how wide its screen is'
        );
    }

    public function test_reports_hide_the_app_chrome_when_printed(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/@media print\s*\{[^}]*aside, header, \.no-print\s*\{\s*display: none/', $css);
    }

    public function test_the_dark_theme_uses_the_night_palette(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // The four values, in the order they sit forward from the page: black
        // behind, then card, input and accent.
        foreach (['950' => '#000000', '900' => '#150050', '800' => '#3f0071', '700' => '#610094'] as $shade => $hex) {
            $this->assertStringContainsString("--color-night-{$shade}: {$hex};", $css);
        }
    }

    public function test_the_dark_theme_repoints_the_slate_surfaces(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // This is what repaints the hundred and fifty dark:bg-slate-* utilities
        // already in the views. Lose it and the app is grey again everywhere
        // except the handful of places written against night directly.
        $this->assertMatchesRegularExpression(
            '/\.dark\s*\{[^}]*--color-slate-900:\s*var\(--color-night-900\)/s',
            $css
        );
    }

    public function test_no_dark_surface_is_left_on_a_baked_in_slate(): void
    {
        // An opacity variant compiles to a literal rather than a var, so it
        // cannot follow the remap above and has to name night itself.
        $offenders = [];

        foreach (array_merge(
            glob(resource_path('views/**/*.blade.php')),
            glob(resource_path('views/*.blade.php')),
            glob(resource_path('views/**/**/*.blade.php')),
            [resource_path('css/app.css')],
        ) as $file) {
            if (preg_match('/dark:bg-slate-(950|900|800|700)\/\d+/', file_get_contents($file), $hit)) {
                $offenders[] = basename($file).': '.$hit[0];
            }
        }

        $this->assertSame([], $offenders, 'these would stay grey in dark mode');
    }



    public function test_the_palette_is_single_sourced(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // A second copy of a palette hex further down the file is how "one edit
        // recolours the app" quietly stops being true — comments included,
        // since a hex written in prose goes stale exactly as silently.
        preg_match_all('/--color-night-\d+:\s*(#[0-9a-f]{3,8});/i', $css, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $hex) {
            $this->assertSame(
                1,
                substr_count(strtolower($css), strtolower($hex)),
                $hex.' is declared more than once'
            );
        }
    }

    public function test_every_palette_colour_is_actually_used(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $views = implode('', array_map('file_get_contents', $this->bladeFiles()));

        // A palette entry nothing points at is a colour that was chosen and is
        // never seen. Referenced either through a var in the stylesheet or as a
        // utility in the markup — both count.
        preg_match_all('/--color-night-(\d+):/', $css, $matches, PREG_SET_ORDER);

        foreach ($matches as [$whole, $shade]) {
            $this->assertTrue(
                str_contains($css, "var(--color-night-{$shade})") || str_contains($views, "-night-{$shade}"),
                "--color-night-{$shade} is defined but nothing uses it"
            );
        }
    }

    public function test_the_sidebar_keeps_the_brand_in_light_and_darkens_properly(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/sidebar.blade.php'));

        // The brand blue is the light theme's sidebar and stays that way. It
        // used to be that hex alone in both themes, which left a bright slab
        // beside a black page once dark mode had a palette of its own.
        $this->assertStringContainsString('bg-[#B6C3E6]', $sidebar);
        $this->assertStringContainsString('dark:bg-night-900', $sidebar);
    }


    public function test_the_sidebar_shows_the_centres_logo(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/sidebar.blade.php'));

        $this->assertFileExists(public_path('images/littleangels-logo.png'));
        $this->assertStringContainsString("asset('images/littleangels-logo.png')", $sidebar);

        // The logo carries the centre's name itself, so the old wordmark beside
        // it would only say it twice.
        $this->assertStringNotContainsString('Management</span>', $sidebar);

        // Width and height are on the tag so the header does not jump while the
        // image loads.
        $this->assertMatchesRegularExpression('/<img[^>]*width="531"[^>]*height="228"/s', $sidebar);
    }

    public function test_the_collapsed_rail_holds_only_the_toggle(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/sidebar.blade.php'));

        // The rail is eighty pixels wide. A mark beside the toggle did not fit
        // in it — the two overflowed and read as broken — so collapsing drops
        // the logo entirely and centres what is left.
        $this->assertMatchesRegularExpression('/<a\s+x-show="!collapsed"/s', $sidebar);
        $this->assertStringContainsString("collapsed ? 'desktop:justify-center'", $sidebar);

        // No second brand mark to compete for the rail.
        $this->assertStringNotContainsString('x-show="collapsed" x-cloak', $sidebar);
        $this->assertSame(1, substr_count($sidebar, '<img'), 'the sidebar carries one brand image');
    }


    public function test_every_page_carries_the_tab_icon(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Signed out and signed in are different layouts with their own <head>,
        // and an icon that is right on one of them is the kind of thing nobody
        // notices for a year.
        foreach ([$this->get(route('login')), $this->actingAs($admin)->get(route('dashboard'))] as $response) {
            $response->assertOk()
                ->assertSee('icon-angels-light-32.png', false)
                ->assertSee('apple-touch-icon', false);
        }
    }

    public function test_the_tab_icon_is_served_at_the_sizes_it_declares(): void
    {
        // A 512px file shrunk to 16 by the browser is a smudge, so each size is
        // resampled up front. The declared size has to match the file, or the
        // browser picks one and then scales it anyway.
        foreach ([16, 32, 48, 180] as $size) {
            $path = public_path("images/icon-angels-light-{$size}.png");

            $this->assertFileExists($path);
            $this->assertSame([$size, $size], array_slice(getimagesize($path), 0, 2), "{$size}px icon is the wrong size");
        }

        // iOS composites a transparent touch icon onto black, which would lose
        // pale blue entirely — so that one, and only that one, has a ground.
        $this->assertSame(0, $this->cornerAlpha(public_path('images/icon-angels-light-180.png')));
        $this->assertSame(127, $this->cornerAlpha(public_path('images/icon-angels-light-32.png')));
    }


    public function test_the_tab_icon_fills_the_space_it_is_given(): void
    {
        // The 512px source carries a hundred pixels of transparent margin above
        // and below the angels — scaled straight down, a 16px tab spent half its
        // height on nothing and the mark arrived as a smudge. The generator
        // trims to the artwork first, and this is what says it still does.
        foreach ([16, 32, 48] as $size) {
            [$width, $height] = $this->markExtent(public_path("images/icon-angels-light-{$size}.png"));

            $this->assertGreaterThanOrEqual(
                0.90,
                $width / $size,
                "the {$size}px icon wastes horizontal space — is the artwork being trimmed?"
            );

            // The mark is wider than it is tall, so height fits where width
            // lands. Well clear of the 58% it started at.
            $this->assertGreaterThanOrEqual(0.65, $height / $size, "the {$size}px icon is letterboxed");
        }
    }

    /** The box the non-transparent pixels of an icon actually occupy. */
    private function markExtent(string $path): array
    {
        $image = imagecreatefrompng($path);
        [$w, $h] = [imagesx($image), imagesy($image)];
        [$minX, $minY, $maxX, $maxY] = [$w, $h, -1, -1];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ((imagecolorat($image, $x, $y) >> 24 & 0x7F) < 120) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        return [$maxX - $minX + 1, $maxY - $minY + 1];
    }

    public function test_the_conventional_favicon_is_the_same_icon(): void
    {
        // Browsers fetch /favicon.ico whether or not the tags name it. Leaving
        // the old one there means two different icons depending on the route in.
        $ico = file_get_contents(public_path('favicon.ico'));
        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));

        $this->assertSame(1, $header['type'], 'not an icon file');
        $this->assertSame(3, $header['count'], 'expected 16, 32 and 48');

        $entry = unpack('Cw/Ch/Ccolors/Cres/vplanes/vbpp/Vbytes/Voffset', substr($ico, 6, 16));
        $this->assertSame(16, $entry['w']);
        $this->assertSame("\x89PNG", substr($ico, $entry['offset'], 4), 'entries should be PNG payloads');
    }

    private function cornerAlpha(string $path): int
    {
        $image = imagecreatefrompng($path);

        return imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha'];
    }

    public function test_the_icon_is_declared_in_one_place_only(): void
    {
        $partial = resource_path('views/layouts/favicon.blade.php');

        $this->assertFileExists($partial);
        $this->assertStringContainsString('rel="apple-touch-icon"', file_get_contents($partial));

        // Three layouts include it. A fourth that hand-rolls its own <link rel="icon">
        // is how the tab icon starts disagreeing with itself.
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            if (realpath($file) === realpath($partial)) {
                continue;
            }

            if (str_contains(file_get_contents($file), 'rel="icon"')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'these declare a favicon instead of including layouts.favicon');
    }
    /** Every Blade template in the app, however deeply nested. */
    private function bladeFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
