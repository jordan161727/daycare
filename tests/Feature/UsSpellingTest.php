<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\StaffRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The app writes US English (QA, 16 Nov 2026): "center", not "centre", in
 * what people read. Code names — CentreSettings, the 'centre.*' settings
 * keys, form field names — stay as they are.
 */
class UsSpellingTest extends TestCase
{
    use RefreshDatabase;

    /** What a person reads: the page without its scripts, styles, comments and tags. */
    private function visible(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html);
        $html = preg_replace('#<!--.*?-->#s', ' ', $html);

        return html_entity_decode(strip_tags($html));
    }

    public function test_pages_say_center_not_centre(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
        Child::create(['lan' => '10601', 'first_name' => 'Ava', 'last_name' => 'Cruz', 'status' => 'Active', 'classroom' => 'Toddler']);

        foreach (['settings.rooms', 'holidays.index', 'dashboard', 'departments.index', 'leave.index', 'profile.edit', 'children.create'] as $route) {
            $text = $this->visible($this->actingAs($admin)->get(route($route))->assertOk()->getContent());

            $this->assertDoesNotMatchRegularExpression('/\b(centre|enrolment|recognis\w*|honour\w*)\b/i', $text, "$route reads UK spelling");
        }

        $this->assertStringContainsString('Center hours', $this->visible($this->actingAs($admin)->get(route('settings.rooms'))->getContent()));
    }

    public function test_labels_built_in_code_say_center(): void
    {
        $this->assertSame('Center closed', (new ClosureDay)->label());
        $this->assertSame('May open the center', StaffRule::GROUPS['When they can work']['CAN_OPEN']);
        $this->assertSame('Center holiday', config('daycare.timesheet.leave_codes.HOLIDAY'));
    }
}
