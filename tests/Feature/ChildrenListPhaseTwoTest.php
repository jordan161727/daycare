<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildDocument;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Children list as Phase 2.1 of docs/work.md draws it: the "Records to
 * finish" banner, the status tabs, the room and pay chips, the columns, and
 * the Withdrawn and Archived tabs that say how a child left.
 */
class ChildrenListPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    public function test_the_banner_counts_what_is_missing_and_a_press_lists_just_those_children(): void
    {
        $done = $this->complete($this->child('Ada', 'Done'));
        $noGuardian = $this->complete($this->child('Ben', 'Orphan', ['lan' => '10002']), guardian: false);
        $noConsent = $this->complete($this->child('Cleo', 'Shy', ['lan' => '10003']), photo: false);

        $html = $this->actingAs($this->admin)->get(route('children.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Records to finish:', $html);
        $this->assertStringContainsString('1 no guardian', $html);
        $this->assertStringContainsString('1 photo consent unsigned', $html);
        $this->assertStringNotContainsString('no emergency contact', $html, 'everybody has one');

        // The Record column says so on the row.
        $this->assertStringContainsString('>Complete<', $html);
        $this->assertStringContainsString('>No guardian<', $html);

        // A press lists just those children.
        $this->actingAs($this->admin)->get(route('children.index', ['gap' => 'photo']))
            ->assertOk()
            ->assertSee('Shy')
            ->assertDontSee('Done')
            ->assertDontSee('Orphan');

        $this->actingAs($this->admin)->get(route('children.index', ['gap' => 'shoes']))->assertNotFound();
    }

    public function test_the_tabs_the_room_chips_and_the_pay_chips_narrow_the_list(): void
    {
        $this->child('Ada', 'Infant', ['classroom' => 'Infant', 'dss_case_no' => 'S1100101A']);
        $this->child('Ben', 'Toddler', ['lan' => '10002', 'classroom' => 'Toddler']);
        $this->child('Cleo', 'Soon', ['lan' => '10003', 'classroom' => 'Toddler', 'status' => 'Pending', 'enrolled_on' => '2026-11-02']);

        $page = $this->actingAs($this->admin)->get(route('children.index'))->assertOk();
        $page->assertSee('Active <span class="ml-0.5 opacity-60">2</span>', false)
            ->assertSee('Pending <span class="ml-0.5 opacity-60">1</span>', false)
            ->assertSee('Withdrawn <span class="ml-0.5 opacity-60">0</span>', false)
            ->assertSee('data-room-chip="Toddler"', false)
            ->assertSee('data-pay-chip="dss"', false)
            // The pending child says when she starts.
            ->assertSee('Starts Nov 2');

        $this->actingAs($this->admin)->get(route('children.index', ['room' => 'Toddler']))
            ->assertOk()->assertSee('Toddler')->assertSee('Soon')->assertDontSee('Ada');

        $this->actingAs($this->admin)->get(route('children.index', ['pay' => 'dss']))
            ->assertOk()->assertSee('Ada')->assertDontSee('Ben');

        $this->actingAs($this->admin)->get(route('children.index', ['pay' => 'private']))
            ->assertOk()->assertSee('Ben')->assertDontSee('Ada');

        $this->actingAs($this->admin)->get(route('children.index', ['room' => 'Attic']))->assertNotFound();
        $this->actingAs($this->admin)->get(route('children.index', ['pay' => 'gold']))->assertNotFound();
    }

    public function test_a_row_carries_the_nickname_the_guardian_the_pay_and_the_schedule(): void
    {
        $child = $this->child('Ella', 'Foster', ['nickname' => 'Ellie', 'dss_case_no' => 'S1177706D', 'schedule_days' => [1, 3, 5], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00']);
        $this->link($child, 'Kate Foster', '(716) 555-0116', ['is_guardian' => true, 'is_emergency' => true, 'priority' => 1]);

        $html = $this->actingAs($this->admin)->get(route('children.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Foster, Ella', $html);
        $this->assertStringContainsString('(Ellie)', $html);
        $this->assertStringContainsString('Kate Foster', $html);
        $this->assertStringContainsString('(716) 555-0116', $html);
        $this->assertStringContainsString('>DSS<', $html);
        $this->assertStringContainsString('8:00a – 5:00p', $html);
        // Mon, Wed, Fri ticked; Tue and Thu not.
        $this->assertSame(3, substr_count($html, 'class="d y"'));
        $this->assertSame(2, substr_count($html, 'class="d n"'));
        // A click anywhere on the row opens the record.
        $this->assertStringContainsString('data-child-row="10001"', $html);
        $this->assertStringContainsString('window.location = ', $html);
        $this->assertStringContainsString('href="'.route('children.show', $child).'"', $html);
    }

    public function test_the_withdrawn_tab_says_how_each_child_left_and_offers_re_enroll(): void
    {
        $this->child('Iris', 'Tan', [
            'status' => 'Inactive', 'withdrawn_on' => '2026-08-28',
            'withdrawal_reason' => 'Family choice', 'withdrawal_note' => 'Grandmother is home full-time now', 'could_return' => 'Yes',
        ]);

        $this->actingAs($this->admin)->get(route('children.index', ['status' => 'Inactive']))
            ->assertOk()
            ->assertSee('data-withdrawn-table', false)
            ->assertSeeInOrder(['Last room', 'Last day', 'Reason', 'Note', 'Could return'])
            ->assertSee('Aug 28, 2026')
            ->assertSee('Family choice')
            ->assertSee('Grandmother is home full-time now')
            ->assertSee('Re-enroll Iris Tan');
    }

    public function test_the_archived_tab_offers_restore(): void
    {
        \App\Models\Setting::put(\App\Http\Controllers\ChildController::SHOW_ARCHIVED_SETTING, '1');
        $child = $this->child('Sam', 'Okoye', ['status' => 'Inactive', 'withdrawn_on' => '2025-06-27']);
        $child->archive($this->admin);

        $this->actingAs($this->admin)->get(route('children.index', ['status' => 'Archived']))
            ->assertOk()
            ->assertSee('Restore Sam Okoye')
            ->assertSee('Archived <span class="ml-0.5 opacity-60">1</span>', false);
    }

    public function test_a_teacher_sees_their_room_without_the_office_banner(): void
    {
        $this->complete($this->child('Ada', 'Done', ['classroom' => 'Infant']), guardian: false);
        $this->child('Ben', 'Elsewhere', ['lan' => '10002', 'classroom' => 'PreK']);

        $this->actingAs(User::factory()->create(['role' => 'teacher', 'classroom' => 'Infant', 'must_change_password' => false]))
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('Done')
            ->assertDontSee('Elsewhere')
            ->assertDontSee('Records to finish')
            ->assertDontSee('Add child');
    }

    private function child(string $first, string $last, array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '10001',
            'status' => 'Active',
            'first_name' => $first,
            'last_name' => $last,
            'classroom' => 'Toddler',
            'classroom_override' => $attributes['classroom'] ?? 'Toddler',
            'birth_date' => '2024-04-05',
        ]);
    }

    /** Everything the Record column checks, so the row reads Complete. */
    private function complete(Child $child, bool $guardian = true, bool $photo = true): Child
    {
        $child->forceFill([
            'schedule_days' => [1, 2, 3, 4, 5], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00',
            'health' => ['immunizations_due' => today()->addYear()->toDateString()],
        ])->save();

        $this->link($child, 'Parent '.$child->last_name, '555-0100', ['is_guardian' => $guardian, 'is_emergency' => true, 'priority' => 1]);

        if ($photo) {
            ChildDocument::create(['child_id' => $child->id, 'kind' => 'photo_consent', 'name' => 'Photo consent']);
        }

        return $child;
    }

    private function link(Child $child, string $name, string $cell, array $flags): void
    {
        $person = Person::create(['name' => $name, 'cell' => $cell]);

        ChildPerson::create(['child_id' => $child->id, 'person_id' => $person->id, 'relationship' => 'Mother'] + $flags);
    }
}
