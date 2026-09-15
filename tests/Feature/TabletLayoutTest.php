<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The screen size between the two the app was built for.
 *
 * A phone gets the card list and a desktop has room for the whole table. The
 * tablet is the one in between: the grid appears at 768px and its columns run
 * past a thousand, so the middle of every row used to be read with the child's
 * name scrolled off the left-hand side — a row about nobody.
 */
class TabletLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * The two columns that say WHICH child stay put while the week scrolls
     * under them — the same treatment the checklist's Quick-set column has had
     * all along, for the same reason.
     */
    public function test_the_register_freezes_the_child_against_a_sideways_scroll(): void
    {
        $this->makeChild();

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // LAN against the edge, Student parked against LAN's width.
        $this->assertStringContainsString('att-col-lan sticky left-0 z-20', $html);
        $this->assertStringContainsString('att-col-student sticky left-[60px] z-20', $html);
        $this->assertStringContainsString('att-lan sticky left-0 z-10', $html);
        $this->assertStringContainsString('att-student sticky left-[60px] z-10', $html);

        // A frozen cell needs an opaque ground, or the row scrolling under it
        // shows straight through the name. The card around the table is glass,
        // so the cells cannot inherit one.
        $this->assertStringContainsString('att-lan sticky left-0 z-10 bg-white dark:bg-night-900', $html);
        $this->assertStringContainsString('att-student sticky left-[60px] z-10 bg-white dark:bg-night-900', $html);

        // And the table declares a width that matches what it now holds — two
        // frozen columns and five fixed 116px days — so the browser scrolls
        // rather than squeezing the boxes, which is the thing the frozen
        // columns are there to make safe.
        $this->assertStringContainsString('min-w-[1290px]', $html);
    }

    public function test_the_roster_freezes_the_same_two_columns(): void
    {
        $this->makeChild();

        $html = $this->actingAs($this->admin)
            ->get(route('children.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('sticky left-0 z-20 w-[60px]', $html);
        $this->assertStringContainsString('sticky left-[60px] z-20', $html);
    }

    /**
     * Freezing a column is no reason to reorder one. The two tables list the
     * same children and are read one after the other.
     */
    public function test_freezing_did_not_reshuffle_either_table(): void
    {
        $this->makeChild();

        $order = ['LAN', 'Student', 'Classroom', 'DOB', 'Age'];

        $this->actingAs($this->admin)->get(route('children.index'))->assertOk()->assertSeeInOrder($order);
        $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->assertSeeInOrder($order);
    }

    /**
     * A Letter page is wider than a tablet. On paper that is the point; on
     * screen it made the whole document scroll sideways to be looked at.
     */
    public function test_the_printed_sheets_fit_a_tablet_screen_to_preview(): void
    {
        $this->makeChild();

        foreach ([[], ['range' => 'month']] as $range) {
            $this->actingAs($this->admin)
                ->get(route('attendance.print', $range + ['date' => '2026-09-14']))
                ->assertOk()
                ->assertSee('max-width:calc(100% - 24px)', false);
        }
    }

    /**
     * A touch device keeps the drawer however wide its screen is. An iPad Pro
     * in landscape is 1366px, and a sidebar pinned open there would be a
     * sidebar nobody can swipe away.
     */
    public function test_a_tablet_keeps_the_drawer_whatever_its_width(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            '@custom-variant desktop (@media (min-width: 1441px) or ((min-width: 1280px) and (pointer: fine)));',
            $css
        );
    }

    /**
     * A rule that means to override the table's own must outrank it.
     *
     * `.att-table th, .att-table td` is an element and a class — specificity
     * (0,1,1) — and it sets text-align, padding and vertical-align for every
     * cell. A bare `.att-w-room { text-align: left }` is (0,1,0), so it loses,
     * and CSS says so by quietly doing nothing: the Classroom column stayed
     * centred through two rounds of "still not aligned" because the
     * declaration was there, correct, and outranked.
     *
     * Only a class that actually lands on a th or a td is in that contest —
     * `.att-cell` is a div inside the cell and the base rule never reaches it
     * — so the set is read from the markup rather than listed here, and stays
     * right when a column is added.
     */
    public function test_a_cell_override_outranks_the_table_rule_it_means_to_beat(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $markup = file_get_contents(resource_path('views/attendance/index.blade.php'));

        // What the base rule claims for every cell.
        $this->assertMatchesRegularExpression(
            '/\.att-table th, \.att-table td \{[^}]*text-align: center/',
            $css,
            'the base cell rule moved; this guard is reading the wrong thing'
        );

        // Every att- class that appears on a th or a td.
        preg_match_all('/<t[hd]\b[^>]*\bclass="([^"]*)"/', $markup, $cells);
        $onCells = [];
        foreach ($cells[1] as $classList) {
            foreach (preg_split('/\s+/', $classList) as $class) {
                if (str_starts_with($class, 'att-')) {
                    $onCells[$class] = true;
                }
            }
        }

        $this->assertNotEmpty($onCells, 'no att- classes found on any cell; the markup moved');
        $this->assertArrayHasKey('att-w-room', $onCells);

        $contested = ['text-align', 'padding-left', 'padding-right', 'padding', 'vertical-align'];

        preg_match_all('/^ {4}(\.att-[^{]*?) \{([^}]*)\}/m', $css, $rules, PREG_SET_ORDER);
        $this->assertNotEmpty($rules);

        foreach ($rules as [, $selector, $body]) {
            // Already scoped to beat it, or shouting over it on purpose.
            if (str_contains($selector, '.att-table th.') || str_contains($selector, '.att-table td.')) {
                continue;
            }
            if (str_contains($body, '!important')) {
                continue;
            }

            // Does this rule target a class that lands on a cell?
            preg_match_all('/\.(att-[\w-]+)/', $selector, $named);
            $touchesCell = (bool) array_intersect($named[1], array_keys($onCells));

            if (! $touchesCell) {
                continue;
            }

            foreach ($contested as $property) {
                if (! preg_match('/(?:^|;|\s)'.preg_quote($property, '/').'\s*:/', $body)) {
                    continue;
                }

                $this->fail(
                    "`{$selector}` sets {$property} on a class that lands on a th or td, which "
                    ."`.att-table th, .att-table td` also sets at a higher specificity — so it will be "
                    ."ignored. Scope it as `.att-table th.x, .att-table td.x`."
                );
            }
        }

        $this->assertTrue(true);
    }
    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '1001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
            'birth_date' => '2024-02-10',
        ]);
    }
}
