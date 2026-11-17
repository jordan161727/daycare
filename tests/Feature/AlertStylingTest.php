<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Medical alerts must not look like ordinary notes (QA, 16 Nov 2026): each
 * kind has its own colour and icon, a note is neutral, and severe is
 * stronger — the same on every screen (<x-child-alert>, Child::alertsForScreen).
 */
class AlertStylingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
        $this->child = Child::create([
            'lan' => '10301', 'first_name' => 'Ella', 'last_name' => 'Foster', 'status' => 'Active',
            'classroom' => 'Toddler', 'classroom_override' => 'Toddler',
            'alerts' => [
                ['type' => 'note', 'text' => 'Leaves early on Tuesdays'],
                ['type' => 'allergy', 'text' => 'Peanuts', 'severity' => 'severe'],
                ['type' => 'medical', 'text' => 'Asthma', 'severity' => 'mild'],
                ['type' => 'medication', 'text' => 'Amoxicillin at noon'],
            ],
        ]);
    }

    public function test_medication_is_its_own_kind_and_health_kinds_are_named(): void
    {
        $this->assertArrayHasKey('medication', Child::ALERT_TYPES);
        $this->assertSame(['allergy', 'medical', 'medication'], Child::HEALTH_ALERT_TYPES);
        foreach (array_keys(Child::ALERT_TYPES) as $type) {
            $this->assertArrayHasKey($type, Child::ALERT_ICONS, "$type has an icon");
        }
    }

    public function test_the_children_list_draws_each_kind_differently_and_severe_stronger(): void
    {
        $html = $this->actingAs($this->admin)->get(route('children.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="na-alert na-alert--allergy na-alert--severe"[^>]*>.*?<svg.*?Peanuts.*?Severe/s', $html);
        $this->assertMatchesRegularExpression('/class="na-alert na-alert--note"[^>]*>.*?Leaves early on Tuesdays/s', $html);
        $this->assertMatchesRegularExpression('/class="na-alert na-alert--medical"[^>]*>.*?Asthma · mild/s', $html);
        $this->assertStringContainsString('na-alert--medication', $html);
        // The styles come once, with a dark-mode set.
        $this->assertSame(1, substr_count($html, '.na-alert { display'));
        $this->assertStringContainsString('.dark .na-alert--allergy', $html);
    }

    public function test_the_record_header_uses_the_same_chips(): void
    {
        $html = $this->actingAs($this->admin)->get(route('children.show', $this->child))->assertOk()->getContent();

        $this->assertStringContainsString('Allergy: Peanuts', $html);
        $this->assertStringContainsString('na-alert--allergy na-alert--severe', $html);
        $this->assertStringContainsString('na-alert--note', $html);
    }

    public function test_alpine_screens_get_the_kind_severity_and_icon_health_first(): void
    {
        $alerts = $this->child->fresh()->alertsForScreen();

        $this->assertSame(['allergy', 'medical', 'medication', 'note'], array_column($alerts, 'type'));
        $this->assertTrue($alerts[0]['severe']);
        $this->assertTrue($alerts[0]['health']);
        $this->assertFalse($alerts[3]['health']);
        $this->assertStringStartsWith('<svg', $alerts[0]['icon']);

        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();
        $this->assertStringContainsString("'na-alert--' + a.type", $html);
        $this->assertStringContainsString('.na-alert--medical {', $html);

        // Director Attendance carries no alerts at all (Jordan, 17 Nov 2026).
        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('na-alert', $html);
        $this->assertStringNotContainsString('data-alert-chip', $html);
    }

    public function test_the_kitchen_list_shows_medication_too_with_the_same_chip(): void
    {
        $html = $this->actingAs($this->admin)->get(route('kitchen.index'))->assertOk()->getContent();

        $this->assertStringContainsString('na-alert--allergy na-alert--severe', $html);
        $this->assertStringContainsString('Amoxicillin at noon', $html);
        $this->assertStringNotContainsString('Leaves early on Tuesdays', $html);
    }
}
