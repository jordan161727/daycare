<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildAttendancePunch;
use App\Models\ChildPerson;
use App\Models\ClosureDay;
use App\Models\DssDayMark;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\ParentAbsenceReport;
use App\Models\ParentPickupPlan;
use App\Models\Person;
use App\Models\User;
use App\Services\Messaging;
use App\Services\Texter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Version 2 of the parent app (docs/parent-app-api.md §4.12–4.17): the day,
 * photos, calendar, absences, pickup and notification settings — read from
 * what the centre records, and only ever about the parent's own children.
 */
class ParentAppDayTest extends TestCase
{
    use RefreshDatabase;

    private array $texts = [];

    private Child $mia;

    private Child $ava;

    private Person $kate;

    private Person $dan;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $texts = &$this->texts;
        $this->app->instance(Texter::class, new class($texts) extends Texter
        {
            public function __construct(private array &$sent) {}

            public function send(string $from, string $to, string $body, array $mediaUrls = []): bool
            {
                $this->sent[] = compact('from', 'to', 'body');

                return true;
            }
        });

        Storage::fake('local');

        $this->director = User::factory()->create(['role' => 'admin', 'name' => 'Ray Kader']);
        $this->mia = Child::create(['lan' => '10001', 'status' => 'Active', 'first_name' => 'Mia', 'last_name' => 'Testwell', 'classroom' => 'Toddler']);
        $this->ava = Child::create(['lan' => '10002', 'status' => 'Active', 'first_name' => 'Ava', 'last_name' => 'Othersen', 'classroom' => 'Toddler']);
        $this->kate = $this->parentOf($this->mia, 'Kate Testwell', '585-555-0134', ['can_pickup' => true, 'relationship' => 'Mother']);
        $this->dan = $this->parentOf($this->mia, 'Dan Testwell', '585-555-0135', ['can_pickup' => true, 'relationship' => 'Father']);
        $this->parentOf($this->ava, 'Olivia Othersen', '585-555-0177', ['can_pickup' => true]);
    }

    /* ---- the day ---- */

    public function test_the_day_is_the_register_with_who_brought_and_collected(): void
    {
        $token = $this->signIn();
        $today = now()->toDateString();

        $this->withToken($token)->getJson("/api/parent/children/{$this->mia->id}/daily")
            ->assertOk()
            ->assertJsonPath('date', $today)
            ->assertJsonPath('status.state', 'not_arrived')
            ->assertJsonPath('totals', null)
            ->assertJsonPath('note', null)
            ->assertJsonCount(0, 'entries');

        $in = now()->setTime(8, 5);
        Attendance::create(['child_id' => $this->mia->id, 'attendance_date' => $today, 'signed_in_at' => $in, 'session' => 'am']);
        ChildAttendancePunch::create(['child_id' => $this->mia->id, 'person_id' => $this->kate->id, 'direction' => 'in', 'occurred_at' => $in, 'service_date' => $today, 'session' => 'am', 'method' => 'pin']);

        $this->withToken($token)->getJson("/api/parent/children/{$this->mia->id}/daily?date={$today}")
            ->assertOk()
            ->assertJsonPath('status.state', 'checked_in')
            ->assertJsonPath('status.by', 'Kate Testwell')
            ->assertJsonPath('entries.0.type', 'checkin')
            ->assertJsonPath('entries.0.detail', 'Dropped off by Kate Testwell');

        $out = now()->setTime(16, 45);
        Attendance::where('child_id', $this->mia->id)->update(['signed_out_at' => $out]);
        ChildAttendancePunch::create(['child_id' => $this->mia->id, 'person_id' => $this->dan->id, 'direction' => 'out', 'occurred_at' => $out, 'service_date' => $today, 'session' => 'am', 'method' => 'pin']);

        $this->withToken($token)->getJson("/api/parent/children/{$this->mia->id}/daily")
            ->assertOk()
            ->assertJsonPath('status.state', 'checked_out')
            ->assertJsonPath('status.by', 'Dan Testwell')
            ->assertJsonCount(2, 'entries')
            ->assertJsonPath('entries.1.detail', 'Picked up by Dan Testwell');
    }

    public function test_a_dss_absent_mark_shows_as_absent(): void
    {
        DssDayMark::create(['child_id' => $this->mia->id, 'marked_on' => now()->toDateString(), 'mark' => DssDayMark::ABSENT]);

        $this->withToken($this->signIn())->getJson("/api/parent/children/{$this->mia->id}/daily")
            ->assertOk()->assertJsonPath('status.state', 'absent');
    }

    public function test_another_familys_child_answers_404_everywhere(): void
    {
        $token = $this->signIn();
        $ava = $this->ava->id;

        $this->withToken($token)->getJson("/api/parent/children/{$ava}/daily")->assertNotFound();
        $this->withToken($token)->getJson("/api/parent/children/{$ava}/photos")->assertNotFound();
        $this->withToken($token)->getJson("/api/parent/children/{$ava}/pickup")->assertNotFound();
        $this->withToken($token)->putJson("/api/parent/children/{$ava}/pickup/today", ['person_id' => 1])->assertNotFound();
        $this->withToken($token)->postJson("/api/parent/children/{$ava}/pickup/one-time", ['name' => 'X', 'relation' => 'Y', 'phone' => '5855550100'])->assertNotFound();
        $this->withToken($token)->postJson('/api/parent/absences', ['child_id' => $ava, 'dates' => [now()->toDateString()], 'reason' => 'sick'])->assertNotFound();
    }

    public function test_every_new_endpoint_needs_a_parent_token(): void
    {
        $this->getJson("/api/parent/children/{$this->mia->id}/daily")->assertUnauthorized();
        $this->getJson('/api/parent/events')->assertUnauthorized();
        $this->getJson('/api/parent/notification-preferences')->assertUnauthorized();
    }

    /* ---- photos ---- */

    public function test_photos_are_the_ones_staff_sent_about_this_child_by_day(): void
    {
        Storage::disk('local')->put('messages/media/staff/picnic.jpg', 'jpg');
        Storage::disk('local')->put('messages/media/staff/ava.jpg', 'jpg');
        $messaging = app(Messaging::class);
        $messaging->sendToFamily($this->mia, MessageThread::ROOM, 'Picnic time!', [['kind' => 'photo', 'path' => 'messages/media/staff/picnic.jpg']], $this->director);
        $messaging->sendToFamily($this->ava, MessageThread::ROOM, 'Ava painting', [['kind' => 'photo', 'path' => 'messages/media/staff/ava.jpg']], $this->director);
        $messaging->sendToFamily($this->mia, MessageThread::OFFICE, 'No photo here.', [], $this->director);

        $this->withToken($this->signIn())->getJson("/api/parent/children/{$this->mia->id}/photos")
            ->assertOk()
            ->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.label', 'TODAY')
            ->assertJsonPath('groups.0.caption', 'Picnic time!')
            ->assertJsonCount(1, 'groups.0.photos')
            ->assertJsonPath('next_page', null);
    }

    /* ---- calendar ---- */

    public function test_the_calendar_shows_the_closure_days_of_the_month(): void
    {
        ClosureDay::create(['closed_on' => '2026-11-26', 'reason' => 'Thanksgiving']);
        ClosureDay::create(['closed_on' => '2026-12-25', 'reason' => 'Christmas Day']);

        $this->withToken($this->signIn())->getJson('/api/parent/events?month=2026-11')
            ->assertOk()
            ->assertJsonPath('month', '2026-11')
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.date', '2026-11-26')
            ->assertJsonPath('events.0.kind', 'closed')
            ->assertJsonPath('events.0.title', 'Thanksgiving');
    }

    /* ---- absences ---- */

    public function test_an_absence_is_recorded_and_posted_in_the_room_conversation(): void
    {
        $token = $this->signIn();
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $this->withToken($token)->postJson('/api/parent/absences', [
            'child_id' => $this->mia->id, 'dates' => [$tomorrow, $today], 'reason' => 'sick', 'symptoms' => ['fever'], 'note' => 'Back Thursday.',
        ])->assertCreated()
            ->assertJsonCount(2, 'absences')
            ->assertJsonPath('absences.0.date', $today)
            ->assertJsonPath('absences.0.reason', 'sick');

        $this->assertSame(2, ParentAbsenceReport::where('child_id', $this->mia->id)->count());
        // The register's DSS marks are the director's, and untouched.
        $this->assertSame(0, DssDayMark::count());

        $room = MessageThread::where('kind', MessageThread::ROOM)->where('child_id', $this->mia->id)->firstOrFail();
        $message = Message::where('thread_id', $room->id)->latest('id')->firstOrFail();
        $this->assertSame(Message::IN, $message->direction);
        $this->assertStringContainsString('Absence: Mia will be away', $message->body);
        $this->assertStringContainsString('Reason: Sick (fever)', $message->body);
        $this->assertStringContainsString('Back Thursday.', $message->body);

        $this->withToken($token)->getJson("/api/parent/children/{$this->mia->id}/daily")
            ->assertJsonPath('status.state', 'absent')
            ->assertJsonPath('status.by', 'Kate Testwell');
    }

    public function test_an_absence_cannot_be_in_the_past_or_use_an_unknown_reason(): void
    {
        $token = $this->signIn();

        $this->withToken($token)->postJson('/api/parent/absences', ['child_id' => $this->mia->id, 'dates' => [now()->subDay()->toDateString()], 'reason' => 'sick'])
            ->assertUnprocessable()->assertJsonValidationErrors('dates.0');
        $this->withToken($token)->postJson('/api/parent/absences', ['child_id' => $this->mia->id, 'dates' => [now()->toDateString()], 'reason' => 'bored'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    /* ---- pickup ---- */

    public function test_pickup_lists_only_people_allowed_to_collect_and_never_a_pin(): void
    {
        $this->parentOf($this->mia, 'Grace Gran', '585-555-0140', ['can_pickup' => true, 'relationship' => 'Grandmother', 'is_guardian' => false, 'gets_texts' => false]);
        $this->parentOf($this->mia, 'Rex Restricted', '585-555-0141', ['can_pickup' => true, 'restriction' => 'Court order', 'gets_texts' => false]);
        $this->parentOf($this->mia, 'Una Unticked', '585-555-0142', ['can_pickup' => false, 'gets_texts' => false]);

        $response = $this->withToken($this->signIn())->getJson("/api/parent/children/{$this->mia->id}/pickup")
            ->assertOk()
            ->assertJsonPath('code', null)
            ->assertJsonPath('today_person_id', null);

        $names = collect($response->json('people'))->pluck('name')->all();
        $this->assertSame(['Dan Testwell', 'Kate Testwell', 'Grace Gran'], $names); // guardians first
        $this->assertStringContainsString('door PIN', $response->json('code_hint'));
    }

    public function test_choosing_who_collects_today_tells_the_office(): void
    {
        $token = $this->signIn();

        $this->withToken($token)->putJson("/api/parent/children/{$this->mia->id}/pickup/today", ['person_id' => $this->dan->id])
            ->assertOk()->assertJsonPath('today_person_id', $this->dan->id);

        $office = MessageThread::where('kind', MessageThread::OFFICE)->where('child_id', $this->mia->id)->firstOrFail();
        $this->assertStringContainsString('Dan Testwell (Father) will collect Mia', Message::where('thread_id', $office->id)->latest('id')->value('body'));

        // Somebody not on the list is refused, not added.
        $stranger = Person::create(['name' => 'Stranger', 'cell' => '585-555-0199']);
        $this->withToken($token)->putJson("/api/parent/children/{$this->mia->id}/pickup/today", ['person_id' => $stranger->id])
            ->assertUnprocessable()->assertJsonValidationErrors('person_id');
    }

    public function test_a_one_time_person_is_a_note_for_the_office_not_a_new_pickup_adult(): void
    {
        $token = $this->signIn();
        $before = ChildPerson::count();

        $this->withToken($token)->postJson("/api/parent/children/{$this->mia->id}/pickup/one-time", ['name' => 'Sam Lee', 'relation' => 'Neighbour', 'phone' => '585-555-0100'])
            ->assertCreated()
            ->assertJsonPath('today_person_id', 0)
            ->assertJsonFragment(['name' => 'Sam Lee', 'one_time' => true]);

        $this->assertSame($before, ChildPerson::count());
        $this->assertTrue(ParentPickupPlan::where('child_id', $this->mia->id)->firstOrFail()->isOneTime());
        $this->assertStringContainsString('please check photo ID', Message::latest('id')->value('body'));
    }

    /* ---- notification settings ---- */

    public function test_notification_settings_default_on_and_switching_messages_off_stops_their_push(): void
    {
        config(['daycare.parent_app.push_driver' => 'expo']);
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);

        $token = $this->signIn();
        $this->withToken($token)->getJson('/api/parent/notification-preferences')
            ->assertOk()->assertExactJson(['messages' => true, 'announcements' => true, 'daily' => true, 'photos' => true]);

        $this->withToken($token)->putJson('/api/parent/notification-preferences', ['messages' => false])
            ->assertOk()->assertJson(['messages' => false, 'announcements' => true]);
        $this->withToken($token)->putJson('/api/parent/notification-preferences', ['photos' => 'nope'])
            ->assertUnprocessable();

        $this->withToken($token)->postJson('/api/parent/devices', ['push_token' => 'ExponentPushToken[kate]', 'platform' => 'ios'])->assertCreated();
        app(Messaging::class)->sendToFamily($this->mia, MessageThread::ROOM, 'Picnic Friday!', [], $this->director);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'exp.host'));

        // Announcements are still on.
        app(Messaging::class)->announce('Closed Monday.', [], $this->director);
        Http::assertSent(fn ($request) => $request[0]['to'] === 'ExponentPushToken[kate]' && $request[0]['body'] === 'Closed Monday.');
    }

    /* ---- helpers ---- */

    private function parentOf(Child $child, string $name, string $cell, array $link = []): Person
    {
        $person = Person::create(['name' => $name, 'cell' => $cell]);
        ChildPerson::create(array_merge(['child_id' => $child->id, 'person_id' => $person->id, 'relationship' => 'Mother', 'gets_texts' => true, 'is_guardian' => true], $link));

        return $person;
    }

    private function lastCode(): string
    {
        preg_match('/code is (\d{6})/', (string) end($this->texts)['body'], $m);

        return $m[1] ?? '';
    }

    private function signIn(string $phone = '5855550134'): string
    {
        $this->postJson('/api/parent/login/start', ['phone' => $phone])->assertOk();

        return $this->postJson('/api/parent/login/verify', ['phone' => $phone, 'code' => $this->lastCode()])->assertOk()->json('token');
    }
}
