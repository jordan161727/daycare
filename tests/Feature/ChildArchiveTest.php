<?php

namespace Tests\Feature;

use App\Http\Controllers\ChildController;
use App\Models\Child;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Archiving a child: off every list, nothing deleted, and a way back.
 *
 * The thing to check is the hiding, in every place the roll is read — the
 * list, the search, the export and the counts — and that the two ways back
 * put the child where they say: Undo as they were, Settings as Inactive.
 */
class ChildArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Child $stays;

    private Child $goes;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::forget();

        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);

        $this->stays = Child::create(['lan' => '10001', 'first_name' => 'Ada', 'last_name' => 'Stays', 'status' => 'Active', 'classroom' => 'PreK']);
        $this->goes = Child::create(['lan' => '10002', 'first_name' => 'Ben', 'last_name' => 'Goes', 'status' => 'Active', 'classroom' => 'PreK']);
    }

    public function test_archiving_takes_the_child_off_the_list_search_export_and_counts(): void
    {
        $this->actingAs($this->admin)
            ->post(route('children.archive', $this->goes))
            ->assertRedirect(route('children.index'))
            ->assertSessionHas('success', 'Ben Goes was archived and hidden from this list. Find them in Settings › Children.');

        $goes = $this->goes->fresh();
        $this->assertSame('Archived', $goes->status);
        $this->assertSame('Active', $goes->status_before_archive);
        $this->assertNotNull($goes->archived_at);
        $this->assertSame($this->admin->id, $goes->archived_by);

        // The list and its chips: All and Active both count one, not two.
        // A fresh session, so the flash naming the child does not stand in
        // for the row.
        $this->flushSession();
        $this->actingAs($this->admin)->get(route('children.index'))
            ->assertOk()
            ->assertSee('Stays')
            ->assertDontSee('Goes')
            ->assertSee('All <span class="ml-0.5 opacity-60">1</span>', false)
            ->assertSee('Active <span class="ml-0.5 opacity-60">1</span>', false)
            ->assertDontSee('Archived <span', false);

        // The status filters: Active no longer lists them, and the Archived
        // tab is not an address while the setting is off.
        $this->actingAs($this->admin)->get(route('children.index', ['status' => 'Active']))->assertOk()->assertDontSee('Goes');
        $this->actingAs($this->admin)->get(route('children.index', ['status' => 'Archived']))->assertNotFound();

        // The export is built from the same roll.
        Excel::fake();
        $this->actingAs($this->admin)->get(route('children.export'))->assertOk();
        Excel::assertDownloaded('children-'.today()->format('Y-m-d').'.xlsx', function ($export) {
            $names = $export->collection()->pluck('last_name')->all();

            return in_array('Stays', $names, true) && ! in_array('Goes', $names, true);
        });

        // The dashboard's head count reads the same roll.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $this->assertSame(1, Child::notArchived()->count());
    }

    public function test_the_search_index_on_the_page_does_not_carry_the_archived_child(): void
    {
        $this->goes->archive($this->admin);

        // The search is client-side over the rows drawn, so a child not drawn
        // cannot be found: the row's search text is what to look for.
        $this->actingAs($this->admin)->get(route('children.index'))
            ->assertOk()
            ->assertDontSee('ben goes');
    }

    public function test_undo_restores_the_previous_status(): void
    {
        $this->goes->update(['status' => 'Pending']);

        $this->actingAs($this->admin)->postJson(route('children.archive', $this->goes))
            ->assertOk()
            ->assertJsonPath('counts.all', 1)
            ->assertJsonPath('counts.byStatus.Active', 1)
            ->assertJsonPath('counts.archived', 1);

        $this->actingAs($this->admin)->postJson(route('children.unarchive', $this->goes), ['to' => 'previous'])
            ->assertOk()
            ->assertJsonPath('status', 'Pending')
            ->assertJsonPath('counts.all', 2)
            ->assertJsonPath('counts.archived', 0);

        $goes = $this->goes->fresh();
        $this->assertSame('Pending', $goes->status);
        $this->assertNull($goes->archived_at);
        $this->assertNull($goes->archived_by);
        $this->assertNull($goes->status_before_archive);
    }

    public function test_the_archived_tab_appears_only_when_the_setting_is_on(): void
    {
        $this->goes->archive($this->admin);

        $this->actingAs($this->admin)->get(route('children.index'))
            ->assertOk()
            ->assertDontSee('Archived <span', false);

        Setting::put(ChildController::SHOW_ARCHIVED_SETTING, '1');

        $this->actingAs($this->admin)->get(route('children.index'))
            ->assertOk()
            ->assertSee('Archived <span class="ml-0.5 opacity-60">1</span>', false)
            // The other counts still leave them out.
            ->assertSee('All <span class="ml-0.5 opacity-60">1</span>', false)
            ->assertDontSee('Goes');

        $this->actingAs($this->admin)->get(route('children.index', ['status' => 'Archived']))
            ->assertOk()
            ->assertSee('Goes')
            ->assertDontSee('Stays')
            ->assertSee("Archived children are not counted in All, Active or Inactive and don't appear in search or Export.", false)
            ->assertSee('Unarchive Ben Goes')
            ->assertDontSee(route('children.export'));
    }

    public function test_the_setting_is_saved_from_settings_children(): void
    {
        $this->actingAs($this->admin)->put(route('settings.children.update'), ['show_archived' => '1'])
            ->assertRedirect(route('settings.children'));

        Setting::forget();
        $this->assertTrue(ChildController::showsArchived());

        $this->actingAs($this->admin)->put(route('settings.children.update'), [])
            ->assertRedirect(route('settings.children'));

        Setting::forget();
        $this->assertFalse(ChildController::showsArchived());
    }

    public function test_unarchive_from_settings_returns_the_child_as_inactive(): void
    {
        $this->goes->archive($this->admin);

        $this->actingAs($this->admin)->get(route('settings.children'))
            ->assertOk()
            ->assertSee('Archived children (<span x-text="count">1</span>)', false)
            ->assertSee('Goes')
            ->assertSee('Unarchive Ben Goes')
            ->assertSee('Unarchived children go back to the Children page as Inactive.');

        $this->actingAs($this->admin)->postJson(route('children.unarchive', $this->goes))
            ->assertOk()
            ->assertJsonPath('status', 'Inactive')
            ->assertJsonPath('message', 'Ben Goes is back on the Children page as Inactive.');

        $this->assertSame('Inactive', $this->goes->fresh()->status);
        $this->assertNull($this->goes->fresh()->archived_at);

        $this->actingAs($this->admin)->get(route('settings.children'))
            ->assertOk()
            ->assertSee('No archived children.');

        $this->actingAs($this->admin)->get(route('children.index'))
            ->assertOk()
            ->assertSee('Goes')
            ->assertSee('Inactive <span class="ml-0.5 opacity-60">1</span>', false);
    }

    public function test_non_admins_cannot_archive_unarchive_or_change_the_setting(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'PreK', 'must_change_password' => false]);

        $this->actingAs($teacher)->post(route('children.archive', $this->goes))->assertForbidden();
        $this->assertSame('Active', $this->goes->fresh()->status);

        $this->goes->archive($this->admin);

        $this->actingAs($teacher)->post(route('children.unarchive', $this->goes))->assertForbidden();
        $this->assertSame('Archived', $this->goes->fresh()->status);

        $this->actingAs($teacher)->put(route('settings.children.update'), ['show_archived' => '1'])->assertForbidden();
        $this->actingAs($teacher)->get(route('settings.children'))->assertForbidden();

        // Nor through the edit form: Archived is not a status a teacher may pick.
        $this->actingAs($teacher)->put(route('children.update', $this->stays), [
            'first_name' => 'Ada', 'last_name' => 'Stays', 'status' => 'Archived',
        ])->assertSessionHasErrors('status');
        $this->assertSame('Active', $this->stays->fresh()->status);

        // And the roster the teacher sees leaves the archived child out.
        $this->actingAs($teacher)->get(route('children.index'))->assertOk()->assertSee('Stays')->assertDontSee('Goes');
    }

    public function test_archiving_from_the_edit_form_leaves_the_same_trail(): void
    {
        $this->actingAs($this->admin)->get(route('children.edit', $this->goes))
            ->assertOk()
            ->assertSee('value="Archived"', false)
            ->assertSee('Save and archive')
            ->assertSee('will disappear from the Children page, search, Export and all counts');

        $this->actingAs($this->admin)->put(route('children.update', $this->goes), [
            'first_name' => 'Ben', 'last_name' => 'Goes', 'status' => 'Archived',
        ])->assertRedirect(route('children.index'));

        $goes = $this->goes->fresh();
        $this->assertSame('Archived', $goes->status);
        $this->assertSame('Active', $goes->status_before_archive);
        $this->assertNotNull($goes->archived_at);
        $this->assertSame($this->admin->id, $goes->archived_by);

        // And back out again, by the same form, clears it.
        $this->actingAs($this->admin)->put(route('children.update', $this->goes), [
            'first_name' => 'Ben', 'last_name' => 'Goes', 'status' => 'Active',
        ])->assertRedirect(route('children.index'));

        $this->assertNull($this->goes->fresh()->archived_at);
    }

    public function test_the_attendance_sheet_leaves_an_archived_child_out(): void
    {
        $this->goes->archive($this->admin);

        $this->assertSame(['Stays'], Child::onRollDuring(today()->startOfWeek()->toDateString(), today()->endOfWeek()->toDateString())->pluck('last_name')->all());
    }
}
