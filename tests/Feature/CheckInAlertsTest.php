<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teacher Attendance, Phase 0: the alerts on a child's record — allergy,
 * medical, a court order — are on the select panel. Read from the record,
 * never edited here, and nothing about the clock changes.
 */
class CheckInAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_select_panel_carries_the_record_alerts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Child::create([
            'lan' => '1001', 'first_name' => 'Ella', 'last_name' => 'Foster', 'status' => 'Active', 'classroom' => 'Transition', 'classroom_override' => 'Transition',
            'alerts' => [
                ['type' => 'allergy', 'text' => 'Milk protein (Dairy)', 'severity' => 'Severe'],
                ['type' => 'court', 'text' => 'No contact with father'],
                ['type' => 'note', 'text' => ''],   // an empty row is dropped
            ],
        ]);
        Child::create(['lan' => '1002', 'first_name' => 'Owen', 'last_name' => 'Hart', 'status' => 'Active', 'classroom' => 'Transition', 'classroom_override' => 'Transition']);

        $html = $this->actingAs($admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-child-alerts', $html);
        $this->assertStringContainsString('Milk protein (Dairy)', $html);
        $this->assertStringContainsString('Severe', $html);
        $this->assertStringContainsString('No contact with father', $html);
        $this->assertStringContainsString('Court Order', $html);
        $this->assertStringContainsString('Allergy', $html);

        // Each child carries exactly their own alerts: Ella two, Owen none.
        preg_match_all('/\\\\u0022alerts\\\\u0022:(\[.*?\])/', $html, $m);
        $this->assertCount(2, $m[1]);
        $this->assertSame(1, count(array_filter($m[1], fn ($json) => $json === '[]')));
    }
}
