<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The cards on the child record — Phase 2.3 of docs/work.md — and what each
 * one saves in place.
 */
class ChildRecordSectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-22 10:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    public function test_the_header_says_how_far_the_record_is_from_done(): void
    {
        $child = $this->child();

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->getContent();

        $this->assertStringContainsString('Record '.$child->completeness().'% complete', $html);
        $this->assertStringContainsString('>No guardian<', $html);
        $this->assertStringContainsString('>Photo consent unsigned<', $html);
        $this->assertStringContainsString('data-warning="guardian"', $html);
        $this->assertStringContainsString('data-warning="emergency"', $html);
        $this->assertStringContainsString('Message parents', $html);
        $this->assertStringContainsString('Sign in / out', $html);
    }

    public function test_the_overview_saves_the_days_and_hours_and_works_out_the_week(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->put(route('children.overview', $child), [
            'schedule_days' => ['', 1, 2, 3, 4, 5], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00',
            'dss_case_no' => 'S1177706D', 'dss_cin' => 'AB33445D', 'nickname' => 'Ellie',
        ])->assertRedirect(route('children.show', $child));

        $child->refresh();
        $this->assertSame([1, 2, 3, 4, 5], $child->scheduleDays());
        $this->assertSame(45.0, $child->hoursPerWeek());
        $this->assertSame('Ellie', $child->nickname);

        // The history: the schedule changing and the subsidy starting.
        $events = $child->statusChanges()->pluck('event')->all();
        $this->assertContains('schedule', $events);
        $this->assertContains('subsidy', $events);

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()
            ->assertSee('45 h a week')
            ->assertSee('(Ellie)')
            ->assertSee('S1177706D');
    }

    public function test_a_room_set_by_hand_stays_until_back_to_automatic(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->post(route('children.room', $child), ['room' => 'PreK', 'from' => '2026-10-26', 'why' => 'Ready early'])
            ->assertRedirect();

        $child->refresh();
        $this->assertSame('PreK', $child->classroom_override);
        $this->assertSame('2026-10-26', $child->classroom_override_from->toDateString());
        $this->assertSame('Toddler', $child->classroom, 'not until the 26th');
        $this->assertStringContainsString('PreK', $child->statusChanges()->where('event', 'room')->first()->summary());

        $this->travelTo(Carbon::parse('2026-10-26 09:00:00'));
        $child->save();
        $this->assertSame('PreK', $child->fresh()->classroom);
        $this->assertSame('Toddler → PreK', $child->statusChanges()->where('event', 'room')->first()->summary());

        $this->actingAs($this->admin)->post(route('children.room', $child), ['automatic' => '1'])->assertRedirect();
        $this->assertNull($child->fresh()->classroom_override);
        $this->assertSame('Toddler', $child->fresh()->classroom);

        // A teacher cannot move a child between rooms.
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'must_change_password' => false]);
        $this->actingAs($teacher)->post(route('children.room', $child), ['room' => 'PreK'])->assertForbidden();
    }

    public function test_the_enrollment_note_is_one_note_saved_and_cleared(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->put(route('children.enrollment-note', $child), ['enrollment_note' => 'Subsidy authorization ends Nov 30'])->assertRedirect();
        $this->assertSame('Subsidy authorization ends Nov 30', $child->fresh()->enrollment_note);
        $this->assertNotNull($child->fresh()->enrollment_note_at);

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->assertSee('Subsidy authorization ends Nov 30')->assertSee('On DSS page');

        $this->actingAs($this->admin)->put(route('children.enrollment-note', $child), ['enrollment_note' => ''])->assertRedirect();
        $this->assertNull($child->fresh()->enrollment_note);
    }

    public function test_health_facts_and_alerts_are_their_own_entries(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->put(route('children.health', $child), [
            'doctor' => 'Dr. Patel · (716) 555-0190', 'immunizations_due' => '2027-01-05', 'medication' => 'None',
        ])->assertRedirect();

        $child->refresh();
        $this->assertSame('Dr. Patel · (716) 555-0190', $child->health('doctor'));
        $this->assertFalse($child->immunizationsDue(), 'a date next year is up to date');

        $this->actingAs($this->admin)->post(route('children.alerts.store', $child), [
            'type' => 'allergy', 'text' => 'Milk protein (dairy)', 'severity' => 'severe',
            'reaction' => 'hives, vomiting', 'action' => 'oat milk only; call parent', 'plan_on' => '2025-08-20',
        ])->assertRedirect();

        $alerts = $child->fresh()->alertList();
        $this->assertCount(1, $alerts);
        $this->assertSame('severe', $alerts[0]['severity']);

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()
            ->assertSee('Up to date')
            ->assertSee('Milk protein (dairy)')
            ->assertSee('Reaction: hives, vomiting')
            ->assertSee('Care plan signed Aug 20, 2025');

        // And the edit form keeps the extra fields through a save.
        $this->actingAs($this->admin)->put(route('children.update', $child), [
            'first_name' => 'Ella', 'last_name' => 'Foster', 'status' => 'Active', 'birth_date' => '2024-04-05',
            'alerts' => [['type' => 'allergy', 'text' => 'Milk protein (dairy)', 'severity' => 'severe', 'reaction' => 'hives, vomiting']],
        ])->assertRedirect();
        $this->assertSame('severe', $child->fresh()->alertList()[0]['severity']);

        $this->actingAs($this->admin)->delete(route('children.alerts.destroy', [$child, 0]))->assertRedirect();
        $this->assertSame([], $child->fresh()->alertList());
    }

    public function test_notes_and_comments_are_listed_together_newest_first(): void
    {
        $child = $this->child();
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'must_change_password' => false]);

        $row = Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-10-20', 'signed_in_at' => '2026-10-20 12:06:00']);
        \App\Models\AttendanceComment::create(['attendance_id' => $row->id, 'child_id' => $child->id, 'user_id' => $teacher->id, 'tag' => 'health', 'body' => 'Bit of a runny nose after lunch.', 'date' => '2026-10-20']);

        $this->actingAs($this->admin)->post(route('children.notes.store', $child), ['body' => 'Mom asked us to watch for a rash.', 'kind' => 'office'])->assertRedirect();

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()
            ->assertSeeInOrder(['Mom asked us to watch for a rash.', 'Bit of a runny nose after lunch.'])
            ->assertSee('teacher, at check-in')
            ->assertSee('Health <span style="opacity: .7;">1</span>', false);
    }

    public function test_a_document_is_uploaded_served_and_clears_the_consent_gap(): void
    {
        Storage::fake('local');
        $child = $this->child();

        $this->actingAs($this->admin)->post(route('children.documents.store', $child), [
            'kind' => 'photo_consent', 'file' => UploadedFile::fake()->create('consent.pdf', 40, 'application/pdf'),
        ])->assertRedirect();

        $document = $child->fresh()->documents()->first();
        $this->assertSame('photo_consent', $document->kind);
        Storage::disk('local')->assertExists($document->path);
        $this->assertArrayNotHasKey('photo', $child->fresh()->load('documents')->missingItems());

        $this->actingAs($this->admin)->get(route('children.documents.show', [$child, $document]))->assertOk();

        // The medical statement renews a year from signing.
        $this->actingAs($this->admin)->post(route('children.documents.store', $child), ['kind' => 'medical_statement', 'signed_on' => '2026-01-12'])->assertRedirect();
        $this->assertSame('2027-01-12', $child->documents()->where('kind', 'medical_statement')->first()->expires_on->toDateString());

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->assertSee('Renews Jan 12');
    }

    public function test_the_family_list_carries_pays_and_texts_and_the_call_order(): void
    {
        $child = $this->child();
        $kate = $this->link($child, 'Kate Foster', ['is_guardian' => true, 'is_emergency' => true, 'priority' => 1]);
        $dan = $this->link($child, 'Dan Foster', ['is_guardian' => true, 'is_emergency' => true, 'priority' => 2]);

        $this->actingAs($this->admin)->post(route('children.people.flags', [$child, $kate]), [
            'relationship' => 'Mother', 'is_guardian' => '1', 'can_pickup' => '1', 'is_emergency' => '1', 'pays_fees' => '1', 'gets_texts' => '1',
        ])->assertRedirect();

        $link = ChildPerson::where('child_id', $child->id)->where('person_id', $kate->id)->first();
        $this->assertTrue($link->pays_fees);
        $this->assertTrue($link->gets_texts);
        $this->assertTrue($link->can_pickup);

        // Dan rung first.
        $this->actingAs($this->admin)->post(route('children.people.order', [$child, $dan]), ['direction' => 'up'])->assertRedirect();
        $this->assertSame(1, ChildPerson::where('person_id', $dan->id)->value('priority'));
        $this->assertSame(2, ChildPerson::where('person_id', $kate->id)->value('priority'));

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->getContent();
        $this->assertStringContainsString('data-people-table', $html);
        $this->assertStringContainsString('Pays', $html);
        $this->assertStringContainsString('Texts', $html);
        $this->assertStringContainsString('Cleared to collect: Kate Foster', $html);

        // The JSON endpoints the Add person panel uses carry the ticks too.
        $this->actingAs($this->admin)->postJson(route('people.link', $dan), ['child_id' => $child->id, 'gets_texts' => true, 'is_emergency' => true])
            ->assertOk()->assertJsonPath('person.gets_texts', true);
    }

    public function test_this_months_attendance_card_counts_days_in_and_absent(): void
    {
        $child = $this->child(['schedule_days' => [1, 2, 3, 4, 5], 'enrolled_on' => '2026-09-01']);

        foreach (['2026-10-19', '2026-10-20'] as $day) {
            Attendance::create(['child_id' => $child->id, 'attendance_date' => $day, 'signed_in_at' => $day.' 12:04:00', 'signed_out_at' => $day.' 20:58:00']);
        }

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->getContent();

        $this->assertStringContainsString('Attendance · October', $html);
        $this->assertStringContainsString('<b style="color: #15803d;">2</b> days in', $html);
        // Booked weekdays before today with no sign-in: Oct 1–16 (12) and Oct 21 (1).
        $this->assertStringContainsString('<b style="color: #be123c;">13</b> absent', $html);
        $this->assertStringContainsString('class="cd c-today"', $html);
    }

    public function test_the_attendance_card_reads_the_marks_the_register_sets(): void
    {
        // The absent and not-scheduled marks Director Attendance and the DSS
        // page write (Phase 3 / 8.1) override the booked-but-no-sign-in rule.
        $child = $this->child(['schedule_days' => [1, 2, 3, 4, 5], 'enrolled_on' => '2026-09-01']);

        foreach (['2026-10-19', '2026-10-20'] as $day) {
            Attendance::create(['child_id' => $child->id, 'attendance_date' => $day, 'signed_in_at' => $day.' 12:04:00', 'signed_out_at' => $day.' 20:58:00']);
        }
        // Eleven of the thirteen empty weekdays were not days after all…
        foreach (['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'] as $day) {
            \App\Models\DssDayMark::create(['child_id' => $child->id, 'marked_on' => $day, 'mark' => \App\Models\DssDayMark::NONE, 'created_by' => $this->admin->id]);
        }
        // …and a Saturday the child was booked for by hand and missed is an absence.
        \App\Models\DssDayMark::create(['child_id' => $child->id, 'marked_on' => '2026-10-16', 'mark' => \App\Models\DssDayMark::ABSENT, 'created_by' => $this->admin->id]);

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->getContent();

        // Oct 16 (marked) and Oct 21 (booked, empty): two.
        $this->assertStringContainsString('<b style="color: #be123c;">2</b> absent', $html);
    }

    public function test_the_cards_are_behind_the_same_door_as_the_record(): void
    {
        $child = $this->child();
        $other = User::factory()->create(['role' => 'teacher', 'classroom' => 'PreK', 'must_change_password' => false]);

        $this->actingAs($other)->put(route('children.overview', $child), ['nickname' => 'X'])->assertForbidden();
        $this->actingAs($other)->post(route('children.notes.store', $child), ['body' => 'Hi'])->assertForbidden();
        $this->actingAs($other)->put(route('children.health', $child), ['doctor' => 'X'])->assertForbidden();

        $mine = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'must_change_password' => false]);
        $this->actingAs($mine)->post(route('children.notes.store', $child), ['body' => 'Napped well.'])->assertRedirect();
        $this->assertSame(1, $child->notes()->count());
    }

    private function child(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '10001',
            'status' => 'Active',
            'first_name' => 'Ella',
            'last_name' => 'Foster',
            'classroom' => 'Toddler',
            'classroom_override' => 'Toddler',
            'birth_date' => '2024-04-05',
        ]);
    }

    private function link(Child $child, string $name, array $flags): Person
    {
        $person = Person::create(['name' => $name, 'cell' => '555-0100']);

        ChildPerson::create(['child_id' => $child->id, 'person_id' => $person->id, 'relationship' => 'Mother'] + $flags);

        return $person;
    }
}
