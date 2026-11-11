<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Add child, as six tabs in one card (Jordan's brief, 6 Nov 2026).
 *
 * The tabs only hide; every field is in the form the whole time, so a save
 * from the first tab posts what was typed on the last. A refused field opens
 * the tab it is on, and the names are checked before the post leaves.
 */
class ChildAddTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_the_page_has_the_header_strip_and_six_tabs_in_order(): void
    {
        $html = $this->actingAs($this->admin)->get(route('children.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Children · New record', $html);
        $this->assertStringContainsString('<h1 class="ca-title">Add child</h1>', $html);
        $this->assertStringContainsString('Only first and last name are required to save.', $html);
        $this->assertStringContainsString('Import PDF / image', $html);

        // The summary strip, before anything is typed.
        $this->assertStringContainsString('New child', $html);
        $this->assertMatchesRegularExpression('/LAN \d+ · assigned on save · Classroom follows date of birth/', $html);

        preg_match_all('/role="tab" id="ca-tab-(\d)"[^>]*>.*?<span class="ca-num"[^>]*>\d<\/span>\s*<span>(.*?)<\/span>/s', $html, $tabs);
        $this->assertSame(['Basics', 'Enrollment', 'Child details', 'Parents', 'Emergency &amp; pickup', 'Notes'], $tabs[2]);
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertSame(6, substr_count($html, 'role="tabpanel"'));

        // Basics is open, and says it is the one with something required.
        $this->assertStringContainsString('id="ca-tab-0" aria-controls="ca-panel-0" class="ca-tab"', $html);
        $this->assertMatchesRegularExpression('/id="ca-tab-0".*?aria-selected="true"/s', $html);
        $this->assertStringContainsString('class="ca-required"', $html);

        // The footer: Step 1 of 6, Next names the next tab, Add child submits.
        $this->assertStringContainsString('Step <span x-text="index + 1">1</span> of 6', $html);
        $this->assertStringContainsString('>Next: Enrollment</button>', $html);
        $this->assertMatchesRegularExpression('/<button class="ca-btn ca-btn-primary"[^>]*>Add child<\/button>/', $html);

        // The browser's own check would stop silently on a hidden tab.
        $this->assertMatchesRegularExpression('/<form[^>]*novalidate/s', $html);
    }

    public function test_every_field_is_on_the_form_whichever_tab_is_open(): void
    {
        $html = $this->actingAs($this->admin)->get(route('children.create'))->assertOk()->getContent();

        $fields = ['first_name', 'last_name', 'nickname', 'birth_date', 'gender', 'description', 'child_name', 'dss_case_no', 'dss_cin', 'photo',
            'status', 'classroom_override', 'enrolled_on', 'withdrawn_on', 'expected_hours_per_week', 'drop_off_time', 'pick_up_time',
            'address', 'city', 'zip', 'telephone', 'parents_status', 'responsible_for_payment',
            'emergency_contact', 'secondary_emergency_contact', 'emergency_telephone', 'emergency_relationship', 'emergency_license_number',
            'important_notes', 'other_notes'];
        foreach (['mother', 'father'] as $who) {
            foreach (['name', 'email', 'address', 'cell', 'home_phone', 'work_phone', 'fax', 'employer', 'title', 'ssn'] as $f) {
                $fields[] = "{$who}_{$f}";
            }
        }
        foreach ([1, 2, 3] as $n) {
            foreach (['name', 'relationship', 'address', 'telephone', 'alternate', 'license_number'] as $f) {
                $fields[] = "pickup_{$n}_{$f}";
            }
        }

        foreach ($fields as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "{$field} is missing from Add");
            // Status is four radios, one name; everything else is one box.
            $boxes = preg_match_all('/<(?:input|select|textarea)\b[^>]*\sname="'.preg_quote($field, '/').'"/', $html);
            $this->assertSame($field === 'status' ? 4 : 1, $boxes, "{$field} is drawn twice");
        }
        $this->assertStringContainsString('name="schedule_days[]"', $html);

        // One guardian and one pick-up adult at a time, the others hidden, not gone.
        $this->assertStringContainsString('>Mother / legal guardian</button>', $html);
        $this->assertStringContainsString('>Father / legal guardian</button>', $html);
        $this->assertStringContainsString('>Pickup 3</button>', $html);
        $this->assertMatchesRegularExpression('/x-show="guardian === &#039;father&#039;"\s+x-cloak|x-show="guardian === \'father\'"\s+x-cloak/', $html);

        // The SSN is masked until asked for.
        $this->assertMatchesRegularExpression('/name="mother_ssn"[^>]*class="cs-input ca-masked"/', $html);
    }

    public function test_a_save_from_the_first_tab_keeps_what_was_typed_on_the_others(): void
    {
        $this->actingAs($this->admin)->post(route('children.store'), [
            'first_name' => 'Tess',
            'last_name' => 'Tabwell',
            'status' => 'Active',
            'nickname' => 'Tee',
            'city' => 'Trenton',
            'father_name' => 'Tom Tabwell',
            'father_ssn' => '123-45-6789',
            'pickup_3_name' => 'Aunt Tilly',
            'pickup_3_license_number' => 'D1234',
            'important_notes' => 'Peanuts',
        ])->assertSessionHasNoErrors();

        $child = Child::where('last_name', 'Tabwell')->firstOrFail();
        $this->assertSame('Tee', $child->nickname);
        $this->assertSame('Trenton', $child->city);
        $this->assertSame('Tom Tabwell', $child->father_name);
        $this->assertSame('Aunt Tilly', $child->pickup_3_name);
        $this->assertSame('D1234', $child->pickup_3_license_number);
        $this->assertSame('Peanuts', $child->important_notes);
    }

    public function test_missing_names_open_basics_with_the_errors_on_it(): void
    {
        $this->actingAs($this->admin)
            ->from(route('children.create'))
            ->post(route('children.store'), ['status' => 'Active', 'city' => 'Trenton'])
            ->assertSessionHasErrors(['first_name', 'last_name']);

        $html = $this->actingAs($this->admin)->get(route('children.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/x-data="\{\s*index: 0,/', $html);
        $this->assertMatchesRegularExpression('/id="ca-tab-0"[^>]*data-invalid="true"/s', $html);
        // What was typed elsewhere comes back with it.
        $this->assertStringContainsString('name="city" value="Trenton"', $html);
    }

    public function test_a_refused_field_opens_its_own_tab_and_its_own_person(): void
    {
        $this->actingAs($this->admin)
            ->from(route('children.create'))
            ->post(route('children.store'), ['first_name' => 'Tess', 'last_name' => 'Tabwell', 'status' => 'Active', 'father_name' => str_repeat('a', 300)])
            ->assertSessionHasErrors('father_name');

        $html = $this->actingAs($this->admin)->get(route('children.create'))->assertOk()->getContent();

        // Parents is the fourth tab, and it opens on the father.
        $this->assertMatchesRegularExpression('/x-data="\{\s*index: 3,/', $html);
        $this->assertStringContainsString("x-data=\"{ guardian: 'father' }\"", $html);

        $this->actingAs($this->admin)
            ->from(route('children.create'))
            ->post(route('children.store'), ['first_name' => 'Tess', 'last_name' => 'Tabwell', 'status' => 'Active', 'pickup_2_name' => str_repeat('a', 300)])
            ->assertSessionHasErrors('pickup_2_name');

        $html = $this->actingAs($this->admin)->get(route('children.create'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/x-data="\{\s*index: 4,/', $html);
        $this->assertStringContainsString('x-data="{ pickup: 2 }"', $html);
    }

    public function test_the_classroom_points_back_to_basics_for_the_date_of_birth(): void
    {
        $this->actingAs($this->admin)->get(route('children.create'))->assertOk()
            ->assertSee('Add a date of birth on Basics and the room follows automatically.');
    }
}
