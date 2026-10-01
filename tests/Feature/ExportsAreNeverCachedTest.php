<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * No export is ever kept by the browser.
 *
 * Each goes out as a file response, which stamps itself Cache-Control:
 * public with a Last-Modified date, from the same address every time. A
 * browser may keep such a response and hand it back without asking — so a
 * director downloaded the roll, corrected a child, downloaded again and
 * opened the old file, while a private window always got the new one. The
 * never-cached middleware on every export route is the answer, and this is
 * the list of routes it must be on.
 */
class ExportsAreNeverCachedTest extends TestCase
{
    use RefreshDatabase;

    private const EXPORTS = [
        'children.export',
        'timesheets.export',
        'staff.timesheets.export',
        'staff.reports.export',
        'attendance.month-sheet.export',
    ];

    public function test_every_export_route_carries_the_middleware(): void
    {
        foreach (self::EXPORTS as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "$name is not a route");
            $this->assertContains('never-cached', $route->gatherMiddleware(), "$name is not marked never-cached");
        }

        // And nothing else named export has been forgotten.
        $exports = collect(Route::getRoutes()->getRoutesByName())
            ->filter(fn ($route, string $name) => str_contains($name, 'export'))
            ->keys()->sort()->values()->all();

        $this->assertSame(collect(self::EXPORTS)->sort()->values()->all(), $exports);
    }

    public function test_each_export_answers_with_headers_that_forbid_keeping_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Child::create([
            'lan' => '1001', 'status' => 'Active', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'classroom' => 'Toddler', 'birth_date' => '2024-03-02',
        ]);

        $this->assertNeverCached($this->actingAs($admin)->get(route('children.export')), 'children');
        $this->assertNeverCached($this->actingAs($admin)->get(route('attendance.month-sheet.export', ['month' => 9, 'year' => 2026])), 'month sheet');
        $this->assertNeverCached($this->actingAs($admin)->get(route('staff.timesheets.export', ['from' => '2026-09-21', 'to' => '2026-09-25'])), 'staff timesheets');
        $this->assertNeverCached($this->actingAs($admin)->get(route('staff.reports.export', ['report' => 'daily_summary', 'start' => '2026-09-21', 'end' => '2026-09-25', 'format' => 'csv'])), 'staff reports');
        $this->assertNeverCached($this->actingAs($admin)->get(route('timesheets.export', TimesheetPeriod::forDate('2026-08-17'))), 'timesheets');
    }

    private function assertNeverCached(TestResponse $response, string $which): void
    {
        $response->assertOk();

        $sent = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));

        foreach (['no-store', 'no-cache', 'must-revalidate', 'max-age=0'] as $directive) {
            $this->assertContains($directive, $sent, "The $which export lacks $directive");
        }

        $this->assertNotContains('public', $sent, "The $which export is still public");
        $this->assertSame('no-cache', $response->headers->get('Pragma'), "The $which export lacks Pragma");
        $this->assertSame('0', $response->headers->get('Expires'), "The $which export lacks Expires");
        $this->assertNull($response->headers->get('Last-Modified'), "The $which export still carries Last-Modified");
    }
}
