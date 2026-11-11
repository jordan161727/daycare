<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every route that saves a child, once each, as the director would press it
 * (QA 12 Nov 2026: Withdraw, Archive, Move room, Overview, Add alert and the
 * family ticks all gave 500 on staging). Each must answer without an error
 * and leave the change on the record. A 500 on any of them fails here first.
 */
class ChildSaveRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->child = Child::create([
            'lan' => '10120', 'first_name' => 'Qa', 'last_name' => 'Tester', 'status' => 'Active',
            'classroom' => 'Toddler', 'dob' => now()->subMonths(26)->toDateString(), 'birth_date' => now()->subMonths(26)->toDateString(),
            'enrolled_on' => now()->subMonth()->toDateString(), 'started_on' => now()->subMonth()->toDateString(),
        ]);
    }

    private function ok($response): void
    {
        $this->assertLessThan(500, $response->getStatusCode(), 'Server error: '.substr((string) $response->getContent(), 0, 300));
        $response->assertSessionHasNoErrors();
    }

    public function test_post_children_adds_a_child(): void
    {
        $this->ok($this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'New', 'last_name' => 'Child', 'status' => 'Pending', 'birth_date' => '02/14/2024']));
        $this->assertDatabaseHas('children', ['first_name' => 'New', 'last_name' => 'Child']);
    }

    public function test_put_children_saves_the_edit_form_and_archives(): void
    {
        $this->ok($this->actingAs($this->admin)->put(route('children.update', $this->child), ['first_name' => 'Qa', 'last_name' => 'Tester', 'status' => 'Active', 'nickname' => 'Q']));
        $this->assertSame('Q', $this->child->fresh()->nickname);

        $this->ok($this->actingAs($this->admin)->put(route('children.update', $this->child), ['first_name' => 'Qa', 'last_name' => 'Tester', 'status' => Child::ARCHIVED]));
        $this->assertTrue($this->child->fresh()->isArchived());
    }

    public function test_put_overview(): void
    {
        $this->ok($this->actingAs($this->admin)->put(route('children.overview', $this->child), ['schedule_days' => [1, 3], 'drop_off_time' => '07:30', 'pick_up_time' => '18:00']));
        $this->assertEquals([1, 3], array_map('intval', $this->child->fresh()->schedule_days));
    }

    public function test_post_room(): void
    {
        $this->ok($this->actingAs($this->admin)->post(route('children.room', $this->child), ['room' => 'UPK-4', 'why' => 'Ready for the older room']));
        $this->assertSame('UPK-4', $this->child->fresh()->classroom);
    }

    public function test_post_withdraw(): void
    {
        $this->ok($this->actingAs($this->admin)->post(route('children.withdraw', $this->child), ['last_day' => now()->toDateString(), 'reason' => 'Moved out of the area', 'note' => 'QA', 'could_return' => 'No']));
        $fresh = $this->child->fresh();
        $this->assertSame('Moved out of the area', $fresh->withdrawal_reason);
    }

    public function test_post_alert(): void
    {
        $this->ok($this->actingAs($this->admin)->post(route('children.alerts.store', $this->child), ['type' => 'allergy', 'text' => 'QA test allergy']));
        $this->assertStringContainsString('QA test allergy', json_encode($this->child->fresh()->alerts));
    }

    public function test_put_health(): void
    {
        $this->ok($this->actingAs($this->admin)->put(route('children.health', $this->child), ['doctor' => 'Dr. Lee', 'medication' => 'None', 'custody_note' => 'Shared']));
        $this->assertSame('Dr. Lee', $this->child->fresh()->health['doctor'] ?? null);
    }

    public function test_put_enrollment_note(): void
    {
        $this->ok($this->actingAs($this->admin)->put(route('children.enrollment-note', $this->child), ['enrollment_note' => 'Waiting on the medical form']));
        $this->assertSame('Waiting on the medical form', $this->child->fresh()->enrollment_note);
    }

    public function test_post_note(): void
    {
        $this->ok($this->actingAs($this->admin)->post(route('children.notes.store', $this->child), ['body' => 'Called mum about pick-up']));
        $this->assertDatabaseHas('child_notes', ['child_id' => $this->child->id, 'body' => 'Called mum about pick-up']);
    }

    public function test_post_document(): void
    {
        $this->ok($this->actingAs($this->admin)->post(route('children.documents.store', $this->child), [
            'kind' => 'enrollment_packet', 'file' => UploadedFile::fake()->create('packet.pdf', 20, 'application/pdf'), 'signed_on' => now()->toDateString(),
        ]));
        $this->assertDatabaseHas('child_documents', ['child_id' => $this->child->id, 'kind' => 'enrollment_packet']);
    }

    public function test_post_person_flags_and_order(): void
    {
        $mum = Person::create(['name' => 'Kate Tester', 'cell' => '+17165550134']);
        $gran = Person::create(['name' => 'Gran Tester', 'cell' => '+17165550135']);
        ChildPerson::create(['child_id' => $this->child->id, 'person_id' => $mum->id, 'relationship' => 'Mother', 'is_emergency' => true, 'priority' => 1]);
        ChildPerson::create(['child_id' => $this->child->id, 'person_id' => $gran->id, 'relationship' => 'Grandmother', 'is_emergency' => true, 'priority' => 2]);

        $this->ok($this->actingAs($this->admin)->post(route('children.people.flags', [$this->child, $mum]), [
            'relationship' => 'Mother', 'is_guardian' => '1', 'can_pickup' => '1', 'is_emergency' => '0', 'pays_fees' => '1', 'gets_texts' => '1',
        ]));
        $link = ChildPerson::where('person_id', $mum->id)->sole();
        $this->assertTrue($link->is_guardian);
        $this->assertFalse($link->is_emergency);
        $this->assertTrue($link->pays_fees);

        ChildPerson::where('person_id', $mum->id)->update(['is_emergency' => true, 'priority' => 2]);
        ChildPerson::where('person_id', $gran->id)->update(['priority' => 1]);
        $this->ok($this->actingAs($this->admin)->post(route('children.people.order', [$this->child, $mum]), ['direction' => 'up']));
        $this->assertSame(1, ChildPerson::where('person_id', $mum->id)->value('priority'));
    }
}
