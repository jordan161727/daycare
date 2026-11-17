<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Children, child record, DSS and Settings › Children fixes from the
 * QA pass of 12 Nov 2026 (#5, #6, #17/#33, #18, #20, #31, #32, #37, #38, #40).
 */
class QaChildrenFixesTwelveNovTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $lan = 20001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-12 21:30:00', 'America/New_York'));
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    /* ---- #5: the badges and the list they open are the same set ---- */

    public function test_the_children_page_opens_on_the_active_tab(): void
    {
        $this->kid('Stays', 'Here');
        $this->kid('Gone', 'Away', ['status' => 'Inactive', 'withdrawn_on' => '2026-10-01']);

        $html = $this->actingAs($this->admin)->get(route('children.index'))->assertOk()->getContent();

        $this->assertSame([$this->lanOf('Stays')], $this->rowLans($html));
        $this->assertMatchesRegularExpression('/class="seg on" data-count="Active"/', $html);
        // All is a tab of its own, asked for by name.
        $this->assertStringContainsString(e(route('children.index', ['sort' => 'lan', 'direction' => 'asc', 'status' => 'all'])), $html);

        $all = $this->actingAs($this->admin)->get(route('children.index', ['status' => 'all']))->assertOk()->getContent();
        $this->assertEqualsCanonicalizing([$this->lanOf('Stays'), $this->lanOf('Gone')], $this->rowLans($all));
        $this->assertMatchesRegularExpression('/class="seg on" data-count="all"/', $all);
    }

    public function test_each_records_to_finish_badge_lists_exactly_the_children_it_counts(): void
    {
        // Nothing on file for any of them: every gap applies to all three.
        $this->kid('One', 'Active');
        $this->kid('Two', 'Active');
        $this->kid('Left', 'Withdrawn', ['status' => 'Inactive', 'withdrawn_on' => '2026-10-01']);

        // Pressed from the All tab, the badge still opens the active roll.
        $html = $this->actingAs($this->admin)->get(route('children.index', ['status' => 'all']))->getContent();
        preg_match_all('/href="([^"]+)" class="gapb[^"]*" data-gap="(\w+)">(\d+) /', $html, $badges, PREG_SET_ORDER);
        $this->assertNotEmpty($badges);

        foreach ($badges as [, $href, $gap, $count]) {
            $url = html_entity_decode($href);
            $this->assertStringContainsString('status=Active', $url, "badge $gap carries the Active tab");
            $listed = $this->rowLans($this->actingAs($this->admin)->get($url)->assertOk()->getContent());
            $this->assertCount((int) $count, $listed, "badge $gap counts $count and lists ".count($listed));
            $this->assertNotContains($this->lanOf('Left'), $listed);
        }
    }

    public function test_the_export_follows_the_tab_and_a_bare_export_is_still_everybody(): void
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        $this->kid('Stays', 'Here');
        $this->kid('Gone', 'Away', ['status' => 'Inactive', 'withdrawn_on' => '2026-10-01']);

        $html = $this->actingAs($this->admin)->get(route('children.index'))->getContent();
        preg_match('/href="([^"]*children\/export[^"]*)"/', $html, $m);
        $this->assertStringContainsString('status=Active', html_entity_decode($m[1]));

        $this->actingAs($this->admin)->get(route('children.export'))->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('children-'.today()->format('Y-m-d').'.xlsx', fn (\App\Exports\ChildrenExport $export) => $export->collection()->count() === 2);
    }

    /* ---- #31: a new child with no days is "Hours not agreed" ---- */

    public function test_a_child_added_with_no_days_counts_as_hours_not_agreed(): void
    {
        // Posted as the Add form posts it: the usual 7:30 to 6 already in the
        // boxes, no day ticked (the hidden empty value in front of the boxes).
        $this->actingAs($this->admin)->post(route('children.store'), [
            'first_name' => 'Newby', 'last_name' => 'Kid', 'status' => 'Active', 'birth_date' => '2023-05-01', 'mother_name' => 'Ready Mother', 'emergency_contact' => 'Ready Mother',
            'drop_off_time' => '07:30', 'pick_up_time' => '18:00', 'schedule_days' => [''],
        ])->assertRedirect();

        $child = Child::where('first_name', 'Newby')->firstOrFail();
        $this->assertFalse($child->hoursAgreed());
        $this->assertArrayHasKey('hours', $child->missingItems());

        $html = $this->actingAs($this->admin)->get(route('children.index'))->getContent();
        $this->assertMatchesRegularExpression('/data-gap="hours">1 hours not agreed/', $html);
        $this->assertMatchesRegularExpression('/data-child-row="'.$child->lan.'".*?Hours not agreed/s', $html);

        // Days and times both: agreed, and off the badge.
        $child->update(['schedule_days' => [1, 2, 3, 4, 5]]);
        $this->assertTrue($child->fresh()->hoursAgreed());
    }

    /* ---- #32: Active with no enrolled date is enrolled today ---- */

    public function test_a_child_added_as_active_is_enrolled_on_the_day_it_was_saved(): void
    {
        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Today', 'last_name' => 'Kid', 'status' => 'Active', 'birth_date' => '2023-05-01', 'mother_name' => 'Ready Mother', 'emergency_contact' => 'Ready Mother',])->assertRedirect();
        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Given', 'last_name' => 'Kid', 'status' => 'Active', 'birth_date' => '2023-05-01', 'mother_name' => 'Ready Mother', 'emergency_contact' => 'Ready Mother', 'enrolled_on' => '2026-09-01'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Later', 'last_name' => 'Kid', 'status' => 'Pending'])->assertRedirect();

        // 9:30pm in the centre is still the 12th, whatever UTC says.
        $this->assertSame('2026-11-12', Child::where('first_name', 'Today')->first()->enrolled_on->toDateString());
        $this->assertSame('2026-09-01', Child::where('first_name', 'Given')->first()->enrolled_on->toDateString());
        $this->assertNull(Child::where('first_name', 'Later')->first()->enrolled_on);
    }

    /* ---- #20: "N/A" is not a nickname ---- */

    public function test_placeholder_nicknames_are_saved_and_read_as_none(): void
    {
        foreach (['N/A', ' na ', 'None', '-', '', 'n/a'] as $i => $typed) {
            $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Nick'.$i, 'last_name' => 'Kid', 'status' => 'Pending', 'nickname' => $typed])->assertRedirect();
            $this->assertNull(Child::where('first_name', 'Nick'.$i)->value('nickname'), "'$typed' stored as no nickname");
        }

        $child = $this->kid('Mia', 'Card');
        $this->actingAs($this->admin)->put(route('children.overview', $child), ['nickname' => 'NONE'])->assertRedirect();
        $this->assertNull($child->fresh()->getRawOriginal('nickname'));

        $this->actingAs($this->admin)->put(route('children.overview', $child), ['nickname' => '  Mimi '])->assertRedirect();
        $this->assertSame('Mimi', $child->fresh()->nickname);

        // A record saved before the fix reads as having none.
        \Illuminate\Support\Facades\DB::table('children')->where('id', $child->id)->update(['nickname' => 'N/A']);
        $this->actingAs($this->admin)->get(route('children.index'))->assertDontSee('(N/A)');
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertDontSee('(N/A)');

        $this->actingAs($this->admin)->get(route('children.create'))->assertSee('name="nickname" value="" class="cs-input" placeholder="Leave blank if none"', false);
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSee('name="nickname" value="" placeholder="Leave blank if none"', false);
    }

    /* ---- #40: the centre's name order on the roll and the record ---- */

    public function test_the_name_order_setting_is_applied_on_the_children_list_and_record(): void
    {
        $child = $this->kid('Ada', 'Lovelace');
        $this->kid('Gone', 'Away', ['status' => 'Inactive', 'withdrawn_on' => '2026-10-01']);

        Setting::put(User::NAME_FORMAT_SETTING, 'first_last');
        $this->actingAs($this->admin)->get(route('children.index'))->assertSee('>Ada Lovelace<', false)->assertDontSee('Lovelace, Ada');
        $this->actingAs($this->admin)->get(route('children.index', ['status' => 'Inactive']))->assertSee('>Gone Away<', false);
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSee('font-weight: 700;">Ada Lovelace', false);

        Setting::put(User::NAME_FORMAT_SETTING, 'last_first');
        $this->actingAs($this->admin)->get(route('children.index'))->assertSee('>Lovelace, Ada<', false);
        $this->actingAs($this->admin)->get(route('children.index', ['status' => 'Inactive']))->assertSee('>Away, Gone<', false);
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSee('font-weight: 700;">Lovelace, Ada', false);
    }

    /* ---- #38 and #37: pickers that start blank ---- */

    public function test_the_room_picker_and_the_alert_type_start_blank(): void
    {
        $child = $this->kid('Tod', 'Dler', ['birth_date' => '2024-06-01', 'dob' => '2024-06-01']);
        $room = $child->fresh()->classroom;
        $this->assertNotNull($room);

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();

        preg_match('/<select class="inp" name="room"[^>]*>(.*?)<\/select>/s', $html, $picker);
        $this->assertStringContainsString('required', $picker[0]);
        $this->assertStringStartsWith('<option value="" selected disabled>Choose a room…</option>', $picker[1]);
        $this->assertStringContainsString('<option value="'.$room.'" disabled>'.$room.' (current)</option>', $picker[1]);

        preg_match('/<select class="inp" name="type"[^>]*>(.*?)<\/select>/s', $html, $type);
        $this->assertStringStartsWith('<option value="" selected disabled>Choose…</option>', $type[1]);

        // And the server refuses either left blank, with a word for each.
        $this->actingAs($this->admin)->post(route('children.room', $child), ['room' => ''])->assertSessionHasErrors(['room' => 'Choose the room to move Tod to.']);
        $this->actingAs($this->admin)->post(route('children.alerts.store', $child), ['type' => '', 'text' => 'Peanuts'])->assertSessionHasErrors(['type' => 'Choose what kind of alert this is.']);
        $this->assertSame([], $child->fresh()->alerts ?? []);
    }

    /* ---- #17/#33: the Add tabs are named ---- */

    public function test_the_add_child_tabs_have_names(): void
    {
        $html = $this->actingAs($this->admin)->get(route('children.create'))->getContent();

        foreach (['Basics', 'Enrollment', 'Child details', 'Parents', 'Emergency &amp; pickup', 'Notes'] as $i => $label) {
            $this->assertMatchesRegularExpression('/role="tab" id="ca-tab-'.$i.'" aria-controls="ca-panel-'.$i.'" class="ca-tab"\s+aria-label="'.preg_quote($label, '/').', step '.($i + 1).' of 6"/', $html);
            $this->assertStringContainsString('id="ca-panel-'.$i.'" role="tabpanel" aria-labelledby="ca-tab-'.$i.'"', $html);
        }
    }

    /* ---- #18: the Settings wording matches the tabs ---- */

    public function test_settings_children_names_the_tabs_the_page_has(): void
    {
        $this->actingAs($this->admin)->get(route('settings.children'))->assertOk()
            ->assertDontSee('All, Active and Inactive')
            ->assertSee('Active, Pending, Withdrawn and All tabs');
    }

    /* ---- #6: the DSS Subsidy only toggle ---- */

    public function test_the_subsidy_only_toggle_filters_the_grid_and_says_its_state(): void
    {
        config()->set('daycare.dss.users', 'ray@example.com');
        $ray = User::create(['name' => 'Ray K', 'email' => 'ray@example.com', 'password' => 'password', 'role' => 'admin']);
        $this->kid('Sub', 'Sidy', ['dss_case_no' => 'S1100107A', 'dss_cin' => 'AC55667F']);
        $this->kid('Pri', 'Vate');

        $names = fn ($json) => collect($json['rows'])->pluck('first')->sort()->values()->all();

        $this->assertSame(['Sub'], $names($this->actingAs($ray)->getJson(route('dss.grid', ['month' => '2026-11']))->json()));
        $this->assertSame(['Sub'], $names($this->actingAs($ray)->getJson(route('dss.grid', ['month' => '2026-11', 'sub' => '1']))->json()));
        $this->assertSame(['Pri', 'Sub'], $names($this->actingAs($ray)->getJson(route('dss.grid', ['month' => '2026-11', 'sub' => '0']))->json()));

        $this->actingAs($ray)->get(route('dss.index'))->assertOk()->assertSee('aria-pressed="true"', false)->assertSee('data-subsidy-toggle', false);
        $this->actingAs($ray)->get(route('dss.index', ['sub' => '0']))->assertOk()->assertSee('aria-pressed="false"', false);
    }

    private function kid(string $first, string $last, array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => (string) $this->lan++,
            'status' => 'Active',
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    private function lanOf(string $first): string
    {
        return (string) Child::where('first_name', $first)->value('lan');
    }

    /** @return list<string> */
    private function rowLans(string $html): array
    {
        preg_match_all('/data-child-row="([^"]+)"|<tr class="row" x-show="!gone\.includes\(\'([^\']+)\'\)/', $html, $m);

        return array_values(array_filter(array_map(fn ($a, $b) => $a ?: $b, $m[1], $m[2])));
    }
}
