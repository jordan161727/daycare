<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may change what on a child's record.
 *
 * The guard is whose record, not which fields. A teacher keeps the whole record
 * of the children in their own rooms — the phone numbers and the pick-up list,
 * and the hours, the dates and the room too. They are the one told any of it
 * first, and splitting the record in half only decided which corrections had to
 * wait on the office.
 *
 * What makes that safe is that every edit is checked against the same rule the
 * roster is filtered by. A teacher can only act on a child they already hold,
 * so setting the room can move one out of their own roster — never pull one in.
 * There is no record here that becomes readable by editing.
 */
class ChildRecordEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);
    }

    public function test_a_teacher_may_open_the_record_of_a_child_in_their_own_room(): void
    {
        $this->actingAs($this->teacher)
            ->get(route('children.edit', $this->child()))
            ->assertOk()
            ->assertSee('Emergency &amp; pickup', false);
    }

    public function test_a_teacher_may_not_touch_a_record_from_another_room(): void
    {
        $other = $this->child(['classroom' => 'Infant', 'birth_date' => '2026-02-10', 'lan' => '2002']);

        $this->actingAs($this->teacher)->get(route('children.edit', $other))->assertForbidden();
        $this->actingAs($this->teacher)
            ->put(route('children.update', $other), $this->form($other))
            ->assertForbidden();
    }

    /**
     * The room field is safe to hand over precisely because of the rule above.
     *
     * A teacher cannot edit a child they do not already hold, so the override
     * is no way to acquire one — the 403 lands before any field is read.
     */
    public function test_a_teacher_cannot_pull_another_rooms_child_into_their_own(): void
    {
        $other = $this->child(['classroom' => 'Infant', 'birth_date' => '2026-02-10', 'lan' => '2002']);

        $this->actingAs($this->teacher)
            ->put(route('children.update', $other), $this->form($other, ['classroom_override' => 'Toddler']))
            ->assertForbidden();

        $this->assertNull($other->fresh()->classroom_override);
        $this->assertSame('Infant', $other->fresh()->classroom);
    }

    public function test_a_teacher_keeps_the_whole_record_of_their_own_child(): void
    {
        $child = $this->child();

        $this->actingAs($this->teacher)
            ->put(route('children.update', $child), $this->form($child, [
                'first_name' => 'Adelaide',
                'telephone' => '555-0142',
                'mother_cell' => '555-0199',
                'pickup_1_name' => 'Grandma Hopper',
                'important_notes' => 'Peanut allergy — EpiPen in the office.',
                'expected_hours_per_week' => '22.5',
                'drop_off_time' => '08:30',
                'pick_up_time' => '17:30',
                'schedule_days' => ['1', '3', '5'],
                'enrolled_on' => '2026-01-05',
                'dss_case_no' => 'S1177706D',
                'dss_cin' => 'HB14137F',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $child->refresh();

        $this->assertSame('Adelaide', $child->first_name);
        $this->assertSame('555-0142', $child->telephone);
        $this->assertSame('Grandma Hopper', $child->pickup_1_name);
        $this->assertSame('Peanut allergy — EpiPen in the office.', $child->important_notes);
        $this->assertSame(22.5, $child->expected_hours_per_week);
        $this->assertSame([1, 3, 5], $child->scheduleDays());
        $this->assertSame('2026-01-05', $child->enrolled_on->toDateString());
        $this->assertSame('S1177706D', $child->dss_case_no);
    }

    /**
     * Moving their own child to another room is allowed, and it costs them the
     * record — which is the honest consequence rather than a hidden one.
     */
    public function test_moving_their_own_child_out_hands_the_record_over(): void
    {
        $child = $this->child();

        $this->actingAs($this->teacher)
            ->put(route('children.update', $child), $this->form($child, [
                'classroom_override' => 'Infant',
                'classroom_override_from' => '2026-09-01',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Infant', $child->fresh()->classroom);

        // It is not their room any more, so it is not their record any more.
        $this->actingAs($this->teacher)->get(route('children.edit', $child))->assertForbidden();
    }

    public function test_the_form_asks_a_teacher_for_the_same_fields_as_a_director(): void
    {
        $child = $this->child();

        $teacherForm = $this->actingAs($this->teacher)->get(route('children.edit', $child))->assertOk()->getContent();
        $directorForm = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('children.edit', $child))->assertOk()->getContent();

        // Nothing is shown as somebody else's to set, and nothing is dead.
        $this->assertStringNotContainsString('Set by the director', $teacherForm);
        $this->assertStringNotContainsString('<input disabled', $teacherForm);
        $this->assertStringNotContainsString('<select disabled', $teacherForm);

        foreach (['name="lan"', 'name="classroom_override"', 'name="dss_cin"', 'name="schedule_days[]"'] as $field) {
            $this->assertStringContainsString($field, $teacherForm);
            $this->assertStringContainsString($field, $directorForm);
        }
    }

    /**
     * Adding a child stays the director's. A new record decides which room it
     * lands in before anybody holds it, so there is no roster to check it
     * against — the rule that makes editing safe has nothing to bite on.
     */
    public function test_creating_a_child_is_still_the_directors(): void
    {
        $this->actingAs($this->teacher)->get(route('children.create'))->assertForbidden();
        $this->actingAs($this->teacher)
            ->post(route('children.store'), ['lan' => '3003', 'first_name' => 'New', 'last_name' => 'Child', 'status' => 'Active'])
            ->assertForbidden();

        $this->assertSame(0, Child::where('lan', '3003')->count());
    }

    public function test_the_roster_offers_the_edit_to_whoever_may_read_it(): void
    {
        $this->child();

        // The roster is already filtered to what this reader may see, so every
        // row on it is one they may also keep up to date.
        $this->actingAs($this->teacher)
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('Edit');
    }

    /** The whole form, since an update posts every field the page carries. */
    private function form(Child $child, array $overrides = []): array
    {
        return $overrides + [
            'lan' => $child->lan,
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => $child->status,
            'birth_date' => $child->birthDate()?->toDateString(),
        ];
    }

    private function child(array $attributes = []): Child
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
