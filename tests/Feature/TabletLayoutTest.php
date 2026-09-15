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
        $this->assertStringContainsString('min-w-[1280px]', $html);
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
