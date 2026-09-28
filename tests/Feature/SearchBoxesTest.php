<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One search box, everywhere.
 *
 * The register's box is the reference: the drawn magnifier at the left, a
 * bare × at the right while there is something to clear, Escape to clear,
 * focus handed back. Every page with a search reads the same, so a hand
 * that has learned one has learned them all.
 */
class SearchBoxesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);

        Child::create([
            'lan' => '10064', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'status' => 'Active',
            'classroom' => 'PreK', 'dob' => '2022-12-15', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);
    }

    /** Each page, and how many search boxes it draws. */
    public static function pages(): array
    {
        return [
            'register' => ['attendance.index', 1],
            'check in' => ['check-in.index', 1],
            'month sheet' => ['attendance.month-sheet', 1],
            'children' => ['children.index', 2],   // one for the desktop line, one for the phone
            'teachers' => ['teachers.index', 1],
        ];
    }

    #[DataProvider('pages')]
    public function test_every_search_box_reads_like_the_registers(string $route, int $boxes): void
    {
        $html = $this->actingAs($this->admin)->get(route($route))->assertOk()->getContent();

        // The 🔍 glyph is the magnifier the centre chose, over the drawn one.
        $magnifier = 'aria-hidden="true">🔍</span>';

        $this->assertSame($boxes, substr_count($html, $magnifier), 'the 🔍 magnifier, once a box');
        $this->assertSame($boxes, substr_count($html, 'aria-label="Clear search">×</button>'), 'a bare × a box');
        $this->assertSame($boxes, substr_count($html, "@keydown.escape=\"search = ''\""), 'Escape clears each box');

        // No drawn magnifier left anywhere, and no disc behind any ×.
        $this->assertStringNotContainsString('d="M21 21l-4.35-4.35m1.35-5.15a6.5', $html);
        $this->assertStringNotContainsString('rounded-full text-slate-400 transition hover:bg-slate-100', $html);
    }
}
