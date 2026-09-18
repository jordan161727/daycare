<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The child record form: a stepper on Edit, one stacked sheet everywhere else.
 *
 * The record is a hundred fields long. Walked in five sections it gets filled
 * in; presented all at once it does not — but the sections are only ever
 * hidden, never dropped, so a save from the first one keeps the answers given
 * on the last.
 */
class ChildFormStepperTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_edit_opens_as_a_stepper_naming_the_child(): void
    {
        $child = $this->makeChild();

        $html = $this->actingAs($this->admin)->get(route('children.edit', $child))->assertOk()->getContent();

        // The band: the face, the name, and the three facts that say which
        // record is open — with the room read off the record rather than typed.
        $this->assertStringContainsString('cs-band', $html);
        $this->assertStringContainsString('LAN 1001 · Infant · Active', $html);

        // Four steps on an existing child, numbered, and a counter that says
        // how many there are. Parents and Emergency & pickup are one People
        // step now — sixty columns that asked for the same grandmother twice,
        // replaced by one row per person.
        foreach (['Basics', 'Child details', 'People', 'Notes'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertSame(4, substr_count($html, 'class="cs-tab"'));
        $this->assertStringContainsString('of 4', $html);

        // The band already names the child, so the page header above it is gone.
        $this->assertStringNotContainsString('Update Ada', $html);
    }

    public function test_a_hidden_step_still_posts_its_fields(): void
    {
        $child = $this->makeChild(['important_notes' => 'Peanut allergy']);

        $html = $this->actingAs($this->admin)->get(route('children.edit', $child))->assertOk()->getContent();

        // Steps are hidden with x-show, which is display:none — the input is in
        // the DOM and in the POST. Were they rendered lazily, saving a name on
        // step 1 would blank every answer on the steps after it.
        $this->assertStringContainsString('name="important_notes"', $html);
        $this->assertStringContainsString('Peanut allergy', $html);

        // Notes is the last step, and its fields are here while step 1 is showing.
        $this->assertStringContainsString('x-show="index === 3"', $html);

        // The People step is the exception, and deliberately so: a link is a
        // row in another table, saved as it goes, so it posts nothing with the
        // form and has no fields to lose.
        $this->assertStringNotContainsString('name="pickup_3_license_number"', $html);
    }

    public function test_a_new_child_still_gets_the_old_parent_blocks(): void
    {
        // The People step needs a child to link to, and the PDF import
        // pre-fills these very inputs — so until the import is rewritten to
        // produce people itself, this is where a scanned form's parents land.
        $html = $this->actingAs($this->admin)->get(route('children.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="mother_name"', $html);
        $this->assertStringContainsString('name="pickup_3_license_number"', $html);
        $this->assertStringContainsString('Emergency &amp; pickup', $html);
        $this->assertStringNotContainsString('Linked people', $html);
    }

    public function test_the_form_opens_on_the_step_holding_the_rejected_field(): void
    {
        $child = $this->makeChild();

        // The nickname sits on Child details, which is index 1.
        $this->actingAs($this->admin)
            ->from(route('children.edit', $child))
            ->put(route('children.update', $child), [
                'lan' => $child->lan,
                'first_name' => $child->first_name,
                'last_name' => $child->last_name,
                'status' => 'Active',
                'nickname' => str_repeat('a', 300),
            ])
            ->assertSessionHasErrors('nickname');

        $html = $this->actingAs($this->admin)->get(route('children.edit', $child))->assertOk()->getContent();

        // Opening on step 1 would leave the error a section out of sight, with
        // nothing on screen to say why the save did not take.
        $this->assertStringContainsString('x-data="{ index: 1 }"', $html);
        $this->assertStringContainsString('data-invalid="true"', $html);
    }

    public function test_each_section_says_how_much_of_it_is_answered(): void
    {
        // Two of the seven contact fields filled in.
        $child = $this->makeChild(['nickname' => 'Addie', 'city' => 'Trenton']);

        $this->actingAs($this->admin)
            ->get(route('children.edit', $child))
            ->assertOk()
            ->assertSee('2/7', false);
    }

    public function test_add_and_the_pdf_review_get_the_same_sections_without_the_stepper(): void
    {
        $html = $this->actingAs($this->admin)->get(route('children.create'))->assertOk()->getContent();

        // Nothing to step through: every section is on the page at once, which
        // is what typing a record from scratch — or checking one against the
        // scan beside it — actually wants.
        $this->assertStringNotContainsString('cs-tab', $html);
        $this->assertStringNotContainsString('x-show="index ===', $html);

        $this->assertStringContainsString('name="first_name"', $html);
        $this->assertStringContainsString('name="important_notes"', $html);

        // And no fill counters on a record that does not exist yet.
        $this->assertStringNotContainsString('cs-pill', $html);
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '1001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Infant',
            'status' => 'Active',
            'birth_date' => '2025-06-01',
        ]);
    }
}
