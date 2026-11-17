<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesChildrenReadyForActive;
use Tests\TestCase;

/**
 * The tester's report on Add child and the Children list (Nov 2026), and the
 * second QA brief that followed it: activation needs a date of birth, a
 * guardian and an emergency contact; a child already on file is warned about
 * before a second is made, from the form and from the spreadsheet import;
 * leaving Add part-way asks first; the list's search lives in the address and
 * its counts follow it; an odd address opens the default list; hours outside
 * when the centre is open are flagged.
 *
 * The date picker's behaviour (Escape, Tab, outside click, submit, the inline
 * message, where the calendar sits) is driven in a headless DOM by
 * tests/js/date-fields.cjs — see DateFieldsReadMonthFirstTest.
 */
class ChildAddAndListTesterReportTest extends TestCase
{
    use MakesChildrenReadyForActive;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    /* ---- 5: what Active needs ---- */

    public function test_a_child_cannot_be_added_as_active_with_only_a_name(): void
    {
        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Ava', 'last_name' => 'Stone', 'status' => 'Active'])
            ->assertSessionHasErrors([
                'status' => 'Add a date of birth, a guardian and an emergency contact before marking Ava Active. Or save as Pending until then.',
                'birth_date', 'mother_name', 'emergency_contact',
            ]);
        $this->assertSame(0, Child::count());

        // Exactly what is missing, and nothing that is not.
        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Ava', 'last_name' => 'Stone', 'status' => 'Active', 'birth_date' => '06/16/2021', 'father_name' => 'Sam Stone'])
            ->assertSessionHasErrors(['status' => 'Add an emergency contact before marking Ava Active. Or save as Pending until then.'])
            ->assertSessionDoesntHaveErrors(['birth_date', 'mother_name']);

        // The form shows it above everything, and the typed values come back.
        $this->actingAs($this->admin)->get(route('children.create'))
            ->assertSee('data-activation-refused', false)
            ->assertSee('Add an emergency contact before marking Ava Active.')
            ->assertSee('value="Ava"', false);
    }

    public function test_pending_saves_with_less_and_active_saves_when_complete(): void
    {
        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Pia', 'last_name' => 'Pending', 'status' => 'Pending'])
            ->assertSessionHasNoErrors()->assertRedirect(route('children.index', ['status' => 'Pending']));

        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Ada', 'last_name' => 'Ready', 'status' => 'Active', 'birth_date' => '2023-05-01', 'mother_name' => 'Mara Ready', 'secondary_emergency_contact' => 'Gran Ready'])
            ->assertSessionHasNoErrors();

        $ada = Child::where('first_name', 'Ada')->firstOrFail()->load('personLinks');
        $this->assertSame('Active', $ada->status);
        $this->assertSame([], $ada->missingForActive());
    }

    public function test_moving_a_child_to_active_needs_the_same_but_an_edit_to_an_active_child_does_not(): void
    {
        $pending = Child::create(['lan' => '10001', 'first_name' => 'Pia', 'last_name' => 'Pending', 'status' => 'Pending']);

        $this->actingAs($this->admin)->put(route('children.update', $pending), ['first_name' => 'Pia', 'last_name' => 'Pending', 'status' => 'Active'])
            ->assertSessionHasErrors(['status' => 'Add a date of birth, a guardian and an emergency contact before marking Pia Active.']);
        $this->assertSame('Pending', $pending->fresh()->status);

        // Start and Re-enroll are refused back to the record, saying the same.
        $this->actingAs($this->admin)->post(route('children.start', $pending), ['first_day' => '2026-11-16'])
            ->assertRedirect(route('children.show', $pending))
            ->assertSessionHasErrors(['status' => 'Add a date of birth, a guardian and an emergency contact before marking Pia Active.']);
        $this->assertSame('Pending', $pending->fresh()->status);

        $gone = Child::create(['lan' => '10002', 'first_name' => 'Wes', 'last_name' => 'Went', 'status' => 'Inactive', 'withdrawn_on' => '2026-10-01', 'dob' => '2022-01-01', 'birth_date' => '2022-01-01']);
        $this->actingAs($this->admin)->post(route('children.reenroll', $gone))
            ->assertSessionHasErrors(['status' => 'Add a guardian and an emergency contact before marking Wes Active.']);
        $this->assertSame('Inactive', $gone->fresh()->status);

        // Once the record has them, the same moves go through.
        $this->readyForActive($pending);
        $this->actingAs($this->admin)->post(route('children.start', $pending), ['first_day' => '2026-11-16'])->assertSessionHasNoErrors();
        $this->assertSame('Active', $pending->fresh()->status);

        // An Active child missing all three still takes an ordinary edit:
        // "Records to finish" chases those, not the save.
        $active = Child::create(['lan' => '10003', 'first_name' => 'Old', 'last_name' => 'Record', 'status' => 'Active']);
        $this->actingAs($this->admin)->put(route('children.update', $active), ['first_name' => 'Old', 'last_name' => 'Record', 'status' => 'Active', 'nickname' => 'Oldie'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Oldie', $active->fresh()->nickname);
    }

    /* ---- 4: the same child twice ---- */

    public function test_a_child_with_the_same_name_and_birthday_is_warned_about_before_saving(): void
    {
        // On file with the birthday in birth_date only — never matched before.
        $first = Child::create(['lan' => '10102', 'first_name' => 'Mary Ann', 'last_name' => 'Lee', 'status' => 'Active', 'classroom_override' => 'PreK', 'birth_date' => '2021-06-16']);
        $form = $this->activeFormFields(['first_name' => ' maryann ', 'last_name' => 'LEE', 'status' => 'Active', 'birth_date' => '06/16/2021']);

        $this->actingAs($this->admin)->post(route('children.store'), $form)
            ->assertSessionHasErrors(['duplicate' => 'A child with this name and birthday already exists: LAN 10102 · Mary Ann Lee · PreK · Active · born Jun 16, 2021. Open that record, or press "Continue anyway" if this is a different child.'])
            ->assertSessionHas('duplicate_of', $first->id);
        $this->assertSame(1, Child::count());

        // The form comes back with what was typed, a way to the record, and Save anyway.
        $this->actingAs($this->admin)->get(route('children.create'))
            ->assertSee('data-duplicate', false)
            ->assertSee('href="'.route('children.show', $first).'"', false)
            ->assertSee('name="confirm_duplicate" value="1"', false)
            ->assertSee('value="maryann"', false);

        // Saying so saves it.
        $this->actingAs($this->admin)->post(route('children.store'), $form + ['confirm_duplicate' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, Child::count());

        // A different birthday is a different child — no warning.
        $this->actingAs($this->admin)->post(route('children.store'), ['birth_date' => '06/17/2021'] + $form)->assertSessionHasNoErrors();
        $this->assertSame(3, Child::count());
    }

    public function test_the_spreadsheet_import_lists_a_child_already_on_file_for_confirmation(): void
    {
        Child::create(['lan' => '10102', 'first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Active', 'dob' => '2022-05-04', 'birth_date' => '2022-05-04']);
        $csv = fn () => UploadedFile::fake()->createWithContent('children.csv', "LAN,First Name,Last Name,Date of Birth\n20001,jace,MILLER ,2022-05-04\n20002,New,Kid,2023-01-02\n");

        $this->actingAs($this->admin)->post(route('children.import'), ['file' => $csv()])
            ->assertSessionHas('success', fn (string $m) => str_contains($m, '1 created') && str_contains($m, '1 row(s) look like a child already on file and were not added'))
            ->assertSessionHas('import_duplicates', fn (array $rows) => count($rows) === 1
                && str_starts_with($rows[0], 'Row 2: jace MILLER was not added. A child with this name and birthday already exists: LAN 10102 · Jace Miller'));
        $this->assertDatabaseMissing('children', ['lan' => '20001']);

        $this->actingAs($this->admin)->get(route('children.import.form'))
            ->assertSee('data-import-duplicates', false)
            ->assertSee('name="confirm_duplicates"', false);

        // Confirmed: added.
        $this->actingAs($this->admin)->post(route('children.import'), ['file' => $csv(), 'confirm_duplicates' => '1'])
            ->assertSessionHas('import_duplicates', []);
        $this->assertDatabaseHas('children', ['lan' => '20001']);
    }

    /* ---- 6: leaving Add part-way ---- */

    public function test_add_child_guards_unsaved_changes_and_edit_does_not(): void
    {
        $html = $this->actingAs($this->admin)->get(route('children.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-unsaved-guard', $html);
        $this->assertMatchesRegularExpression('/guardUnsaved: (JSON\.parse\(\'true\'\)|true)/', $html);
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $html);
        $this->assertStringContainsString('if (! $event.defaultPrevented) dirty = false', $html);

        $child = Child::create(['lan' => '10001', 'first_name' => 'Ed', 'last_name' => 'It', 'status' => 'Active']);
        $edit = $this->actingAs($this->admin)->get(route('children.edit', $child))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/guardUnsaved: (JSON\.parse\(\'false\'\)|false)/', $edit);
    }

    /* ---- 7, 8: the search follows into the counts and the address ---- */

    public function test_the_search_is_read_from_the_address_and_the_footer_counts_it(): void
    {
        Child::create(['lan' => '10001', 'first_name' => 'Ava', 'last_name' => 'Stone', 'status' => 'Active', 'classroom_override' => 'Toddler']);
        Child::create(['lan' => '10002', 'first_name' => 'Ben', 'last_name' => 'Brook', 'status' => 'Active', 'classroom_override' => 'Toddler']);

        $page = $this->actingAs($this->admin)->get(route('children.index', ['search' => 'stone']))->assertOk();
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/data-showing x-text="[^"]*shownCount[^"]*">Showing 1 of 2/', $html);
        $this->assertStringContainsString("search: 'stone'", $html);
        // Every child the chips count, with the room, for the browser to recount.
        $this->assertMatchesRegularExpression('/index: JSON\.parse\(.{0,30}10001.{0,30}h.{0,30}ava stone stone, ava  toddler 10001.{0,30}r.{0,30}Toddler/', $html);
        $this->assertStringContainsString('data-room-chip-all', $html);
        // Written back to the address as ?search=, the old ?q= read too.
        $this->assertStringContainsString("url.searchParams.set('search', q)", $html);
        $this->assertStringContainsString('history.replaceState', $html);
        $this->assertMatchesRegularExpression('/Showing 1 of 2/', $this->actingAs($this->admin)->get(route('children.index', ['q' => 'brook']))->getContent());

        // No search: every row.
        $this->assertMatchesRegularExpression('/>Showing 2 of 2/', $this->actingAs($this->admin)->get(route('children.index'))->getContent());
    }

    public function test_the_back_link_from_a_record_keeps_the_search(): void
    {
        $child = Child::create(['lan' => '10001', 'first_name' => 'Ava', 'last_name' => 'Stone', 'status' => 'Active']);
        $from = route('children.index', ['status' => 'Active', 'search' => 'ava']);

        $this->actingAs($this->admin)->withHeader('referer', $from)->get(route('children.show', $child))
            ->assertSee('href="'.e($from).'"', false);

        // After a save on the record reloads it, Back still goes to the search.
        $this->actingAs($this->admin)->withHeader('referer', route('children.show', $child))->get(route('children.show', $child))
            ->assertSee('href="'.e($from).'"', false);
    }

    /* ---- 9: an odd address ---- */

    public function test_an_unusual_sort_direction_or_status_opens_the_default_list(): void
    {
        Child::create(['lan' => '10001', 'first_name' => 'Ava', 'last_name' => 'Stone', 'status' => 'Active']);

        $this->actingAs($this->admin)->get('/children?sort=foo&direction=sideways&status=xyz')
            ->assertOk()
            ->assertViewHas('sort', 'lan')
            ->assertViewHas('direction', 'asc')
            ->assertViewHas('status', 'Active')
            ->assertSee('Ava');

        // The spreadsheet stays strict: it is not built from a different question.
        $this->actingAs($this->admin)->get(route('children.export', ['status' => 'xyz']))->assertNotFound();
    }

    /* ---- second brief: hours outside when the centre is open ---- */

    public function test_hours_outside_opening_are_flagged_the_same_from_the_form_and_the_overview(): void
    {
        $child = Child::create(['lan' => '10001', 'first_name' => 'Late', 'last_name' => 'Kid', 'status' => 'Active']);
        $expected = 'Hours 7:00 AM – 8:00 PM fall outside when the center is open (7:00 AM – 6:00 PM). Check the times.';

        $this->actingAs($this->admin)->put(route('children.update', $child), ['first_name' => 'Late', 'last_name' => 'Kid', 'status' => 'Active', 'drop_off_time' => '07:00', 'pick_up_time' => '20:00'])
            ->assertSessionHasNoErrors()->assertSessionHas('hours_warning', $expected);

        $this->actingAs($this->admin)->put(route('children.overview', $child), ['schedule_days' => ['', 1], 'drop_off_time' => '07:00', 'pick_up_time' => '20:00'])
            ->assertSessionHasNoErrors()->assertSessionHas('hours_warning', $expected);
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSee('data-hours-warning', false)->assertSee($expected);

        // Inside the hours: nothing said. Outside the hard bounds: refused, in the same words both places.
        $this->actingAs($this->admin)->put(route('children.overview', $child), ['schedule_days' => ['', 1], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00'])
            ->assertSessionMissing('hours_warning');
        $this->actingAs($this->admin)->put(route('children.overview', $child), ['schedule_days' => ['', 1], 'drop_off_time' => '08:00', 'pick_up_time' => '21:00'])
            ->assertSessionHasErrors(['pick_up_time' => "Pick-up can't be after 8:00 PM — the latest the center keeps a child."]);
        $this->actingAs($this->admin)->put(route('children.update', $child), ['first_name' => 'Late', 'last_name' => 'Kid', 'status' => 'Active', 'drop_off_time' => '08:00', 'pick_up_time' => '21:00'])
            ->assertSessionHasErrors(['pick_up_time' => "Pick-up can't be after 8:00 PM — the latest the center keeps a child."]);
    }
}
