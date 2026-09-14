<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two numbers the state knows a subsidised child by.
 *
 * A voucher letter carries both: the case number, which belongs to the family
 * and covers every child on it, and the CIN, which belongs to this child
 * alone. The centre bills against them, so they are read off the record far
 * more often than they are typed into it.
 */
class ChildDssNumbersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_both_numbers_are_asked_for_beside_the_lan(): void
    {
        $child = $this->makeChild(['dss_case_no' => 'S1177706D', 'dss_cin' => 'HB14137F']);

        $this->actingAs($this->admin)
            ->get(route('children.edit', $child))
            ->assertOk()
            // Beside the centre's own number, not three sections down: all
            // three are numbers that name this child.
            ->assertSeeInOrder(['name="lan"', 'DSS Case No', 'DSS CIN'], escape: false)
            ->assertSee('name="dss_case_no" value="S1177706D"', false)
            ->assertSee('name="dss_cin" value="HB14137F"', false);
    }

    public function test_the_numbers_are_saved_as_typed(): void
    {
        $child = $this->makeChild();

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->form($child, [
                'dss_case_no' => 'S1177706D',
                'dss_cin' => 'HB14137F',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $child->refresh();

        // Strings, letters and all — and a leading zero is part of the number
        // rather than something to round away.
        $this->assertSame('S1177706D', $child->dss_case_no);
        $this->assertSame('HB14137F', $child->dss_cin);
    }

    public function test_a_child_with_no_subsidy_needs_neither(): void
    {
        $child = $this->makeChild();

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->form($child))
            ->assertSessionHasNoErrors();

        $this->assertNull($child->fresh()->dss_case_no);
        $this->assertNull($child->fresh()->dss_cin);
    }

    public function test_the_record_page_shows_them_without_opening_the_form(): void
    {
        $child = $this->makeChild(['dss_case_no' => 'S1177706D', 'dss_cin' => 'HB14137F']);

        // Billing reads these, and reading a record should not mean opening it
        // for editing — which a teacher cannot do anyway.
        $this->actingAs($this->admin)
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSee('DSS Case No')
            ->assertSee('S1177706D')
            ->assertSee('DSS CIN')
            ->assertSee('HB14137F');
    }

    public function test_a_record_without_them_says_nothing_about_them(): void
    {
        // Most children are not on a subsidy. Two empty rows on every one of
        // their records would be a question nobody asked.
        $this->actingAs($this->admin)
            ->get(route('children.show', $this->makeChild()))
            ->assertOk()
            ->assertDontSee('DSS Case No')
            ->assertDontSee('DSS CIN');
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '1001',
            'status' => 'Active',
            'first_name' => 'Isabella',
            'last_name' => 'Taylor',
            'classroom' => 'Toddler',
            'birth_date' => '2023-10-01',
        ]);
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
}
