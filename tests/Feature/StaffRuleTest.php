<?php

namespace Tests\Feature;

use App\Models\StaffRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Director', 'email' => 'director@example.com', 'password' => 'password', 'role' => 'admin']);
        $this->teacher = User::create(['name' => 'Grace Lee', 'email' => 'grace@example.com', 'password' => 'password', 'role' => 'teacher']);
    }

    public function test_times_are_picked_from_a_list_not_typed_into_a_spinner(): void
    {
        /*
         * A native time input asks for hours, minutes and AM/PM in three
         * separate hits and is genuinely awkward with a mouse. A rule is
         * nearly always on a quarter hour inside the centre's own day, so
         * those are the only times offered.
         */
        $html = $this->actingAs($this->admin)
            ->get(route('teachers.show', $this->teacher))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('type="time"', $html);

        // Read in words, posted as H:i — which is what the validator wants.
        $this->assertStringContainsString('>7:00 AM<', $html);
        $this->assertStringContainsString('>6:00 PM<', $html);
        $this->assertStringContainsString('value="07:00"', $html);

        // Opening to closing, a step apart, and short enough to read.
        preg_match_all('/<option value="(\d\d:\d\d)"/', $html, $found);

        $times = array_values(array_unique($found[1]));

        $this->assertSame('07:00', $times[0]);
        $this->assertSame('18:00', end($times));
        $this->assertContains('07:30', $times);

        // Eleven hours at a quarter of an hour came to forty-five options — a
        // list to hunt through rather than read.
        $this->assertLessThanOrEqual(24, count($times), 'the list has to fit on a screen');
    }

    public function test_a_finer_step_can_be_asked_for(): void
    {
        // For a centre that genuinely schedules on the quarter.
        config(['daycare.time_step' => 15]);

        $html = $this->actingAs($this->admin)
            ->get(route('teachers.show', $this->teacher))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="07:15"', $html);
    }

    public function test_the_offered_times_follow_the_centres_own_hours(): void
    {
        // From config rather than hard-coded, so a centre that opens at six
        // gets six o'clock without anybody editing a view.
        config(['daycare.open' => 6 * 60, 'daycare.close' => 19 * 60]);

        $html = $this->actingAs($this->admin)
            ->get(route('teachers.show', $this->teacher))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="06:00"', $html);
        $this->assertStringContainsString('value="19:00"', $html);
    }

    public function test_a_rule_stores_its_times_as_minutes(): void
    {
        $this->actingAs($this->admin)
            ->post(route('teachers.rules.store', $this->teacher), [
                'rule_type' => 'AVAILABLE_WINDOW',
                'priority' => 'HARD',
                'day' => 'ALL',
                'time_1' => '07:00',
                'time_2' => '12:30',
            ])
            ->assertRedirect();

        $rule = $this->teacher->staffRules()->sole();

        $this->assertSame(420, $rule->time_1);
        $this->assertSame(750, $rule->time_2);
    }

    public function test_columns_the_rule_type_does_not_use_are_cleared(): void
    {
        // A stale end time left behind by switching type is invisible on screen
        // but still in the row, and applies again the day the type changes back.
        $rule = $this->teacher->staffRules()->create([
            'rule_type' => 'FIXED_SHIFT', 'priority' => 'HARD',
            'day' => 'MON', 'time_1' => 480, 'time_2' => 960,
        ]);

        $this->actingAs($this->admin)
            ->put(route('teachers.rules.update', [$this->teacher, $rule]), [
                'rule_type' => 'AVAILABLE_AFTER',
                'priority' => 'HARD',
                'day' => 'MON',
                'time_1' => '09:00',
                'time_2' => '16:00',
            ])
            ->assertRedirect();

        $this->assertNull($rule->fresh()->time_2);
    }

    public function test_a_rule_missing_a_field_its_type_needs_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('teachers.rules.store', $this->teacher), [
                'rule_type' => 'AVAILABLE_WINDOW',
                'priority' => 'HARD',
                'day' => 'ALL',
                'time_1' => '07:00',
            ])
            ->assertSessionHasErrors('time_2');

        $this->assertSame(0, $this->teacher->staffRules()->count());
    }

    public function test_a_window_that_ends_before_it_starts_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('teachers.rules.store', $this->teacher), [
                'rule_type' => 'AVAILABLE_WINDOW',
                'priority' => 'HARD',
                'day' => 'ALL',
                'time_1' => '14:00',
                'time_2' => '09:00',
            ])
            ->assertSessionHasErrors('time_2');
    }

    public function test_a_no_pair_rule_naming_a_stranger_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('teachers.rules.store', $this->teacher), [
                'rule_type' => 'NO_PAIR',
                'priority' => 'SOFT',
                'value_text' => 'Nobody At All',
            ])
            ->assertSessionHasErrors('value_text');
    }

    public function test_somebody_cannot_be_paired_against_themselves(): void
    {
        $this->actingAs($this->admin)
            ->post(route('teachers.rules.store', $this->teacher), [
                'rule_type' => 'NO_PAIR',
                'priority' => 'SOFT',
                'value_text' => $this->teacher->name,
            ])
            ->assertSessionHasErrors('value_text');
    }

    public function test_a_room_rule_must_name_a_real_room(): void
    {
        $this->actingAs($this->admin)
            ->post(route('teachers.rules.store', $this->teacher), [
                'rule_type' => 'ROOM_PREFERENCE',
                'priority' => 'SOFT',
                'value_text' => 'Nursery',
            ])
            ->assertSessionHasErrors('value_text');
    }

    public function test_required_hours_must_be_weekly_or_biweekly(): void
    {
        $this->actingAs($this->admin)
            ->post(route('teachers.rules.store', $this->teacher), [
                'rule_type' => 'REQUIRED_HOURS',
                'priority' => 'HARD',
                'number' => 40,
                'value_text' => 'MONTHLY',
            ])
            ->assertSessionHasErrors('value_text');
    }

    public function test_a_flag_rule_needs_nothing_else(): void
    {
        $this->actingAs($this->admin)
            ->post(route('teachers.rules.store', $this->teacher), [
                'rule_type' => 'CAN_OPEN',
                'priority' => 'HARD',
                'source_note' => 'Keyholder.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('CAN_OPEN', $this->teacher->staffRules()->sole()->rule_type);
    }

    public function test_a_rule_cannot_be_edited_through_another_teachers_url(): void
    {
        $other = User::create(['name' => 'Emily Carter', 'email' => 'emily@example.com', 'password' => 'password', 'role' => 'teacher']);
        $rule = $this->teacher->staffRules()->create(['rule_type' => 'CAN_OPEN', 'priority' => 'HARD']);

        $this->actingAs($this->admin)
            ->delete(route('teachers.rules.destroy', [$other, $rule]))
            ->assertNotFound();

        $this->assertModelExists($rule);
    }

    public function test_teachers_cannot_reach_the_rules_screen(): void
    {
        $this->actingAs($this->teacher)->get(route('teachers.show', $this->teacher))->assertForbidden();
        $this->actingAs($this->teacher)
            ->post(route('teachers.rules.store', $this->teacher), ['rule_type' => 'CAN_OPEN', 'priority' => 'HARD'])
            ->assertForbidden();
    }

    public function test_deleting_a_teacher_takes_their_rules_with_them(): void
    {
        $this->teacher->staffRules()->create(['rule_type' => 'CAN_OPEN', 'priority' => 'HARD']);

        $this->actingAs($this->admin)->delete(route('teachers.destroy', $this->teacher));

        $this->assertSame(0, StaffRule::count());
    }

    public function test_the_rules_screen_reads_each_rule_back_in_english(): void
    {
        $this->teacher->staffRules()->create([
            'rule_type' => 'AVAILABLE_WINDOW', 'priority' => 'HARD',
            'day' => 'ALL', 'time_1' => 420, 'time_2' => 720,
            'source_note' => 'Classes in the afternoon.',
        ]);

        $this->actingAs($this->admin)
            ->get(route('teachers.show', $this->teacher))
            ->assertOk()
            ->assertSee('Only works 7:00 AM–12:00 PM every day')
            ->assertSee('Classes in the afternoon.');
    }

    public function test_employment_details_save_from_the_teacher_form(): void
    {
        $this->actingAs($this->admin)
            ->put(route('teachers.update', $this->teacher), [
                'name' => 'Grace Lee',
                'email' => 'grace@example.com',
                'employment' => 'PT',
                'title' => 'UPK-4',
                'legal_name' => 'Grace Y. Lee',
                'pay_rate' => '15.50',
                'direct_deposit' => '1',
            ])
            ->assertRedirect();

        $teacher = $this->teacher->fresh();

        $this->assertSame('PT', $teacher->employment);
        $this->assertSame('Grace Y. Lee', $teacher->legal_name);
        $this->assertSame('15.50', $teacher->pay_rate);
        $this->assertTrue($teacher->direct_deposit);
    }
}
