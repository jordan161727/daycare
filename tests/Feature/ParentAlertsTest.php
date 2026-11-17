<?php

namespace Tests\Feature;

use App\Enums\CommentTag;
use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\DoorAttendance;
use App\Models\HealthAudit;
use App\Models\ParentDevice;
use App\Models\ParentNotificationPref;
use App\Models\Person;
use App\Models\SymptomCode;
use App\Models\User;
use App\Services\HealthScreening;
use App\Services\Texter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What staff note on the register reaches the parents' app (17 Nov 2026):
 * the day's comment and a health check that found a symptom, as a push and
 * in the Daily report — only to that child's own family.
 */
class ParentAlertsTest extends TestCase
{
    use RefreshDatabase;

    private array $texts = [];

    private Child $mia;

    private Person $kate;

    private Person $olivia;

    private User $teacher;

    private DoorAttendance $row;

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

        config(['daycare.parent_app.push_driver' => 'expo']);
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);

        $this->teacher = User::factory()->create(['role' => 'admin', 'name' => 'Tina Teacher']);
        $this->mia = Child::create(['lan' => '10001', 'status' => 'Active', 'first_name' => 'Mia', 'last_name' => 'Testwell', 'classroom' => 'Toddler']);
        $ava = Child::create(['lan' => '10002', 'status' => 'Active', 'first_name' => 'Ava', 'last_name' => 'Othersen', 'classroom' => 'Toddler']);
        $this->kate = $this->parentOf($this->mia, 'Kate Testwell', '585-555-0134');
        $this->olivia = $this->parentOf($ava, 'Olivia Othersen', '585-555-0177');
        ParentDevice::create(['person_id' => $this->kate->id, 'push_token' => 'ExponentPushToken[kate]', 'platform' => 'ios']);
        ParentDevice::create(['person_id' => $this->olivia->id, 'push_token' => 'ExponentPushToken[olivia]', 'platform' => 'android']);

        // symptom_codes is seeded by its migration: 0 Normal, 4 Fever.
        $this->assertSame('Fever', SymptomCode::find(4)?->label);

        $this->row = DoorAttendance::create(['child_id' => $this->mia->id, 'attendance_date' => now()->toDateString(), 'signed_in_at' => now()->setTime(8, 5), 'session' => 'am']);
    }

    public function test_a_register_comment_pushes_to_the_family_and_shows_in_the_daily_report(): void
    {
        $this->row->comments()->create(['child_id' => $this->mia->id, 'user_id' => $this->teacher->id, 'tag' => CommentTag::Health->value, 'body' => 'fever']);

        Http::assertSent(fn ($r) => $r[0]['to'] === 'ExponentPushToken[kate]'
            && $r[0]['body'] === 'Note from the room: fever'
            && $r[0]['data']['kind'] === 'daily'
            && $r[0]['data']['child_id'] === $this->mia->id);
        Http::assertNotSent(fn ($r) => collect($r->data())->contains(fn ($m) => ($m['to'] ?? null) === 'ExponentPushToken[olivia]'));

        $this->withToken($this->signIn())->getJson("/api/parent/children/{$this->mia->id}/daily")
            ->assertOk()
            ->assertJsonPath('note.body', 'fever')
            ->assertJsonPath('note.author', 'Tina')
            ->assertJsonPath('note.tag', 'Health');
    }

    public function test_changing_the_words_pushes_again_but_retagging_does_not(): void
    {
        $comment = $this->row->comments()->create(['child_id' => $this->mia->id, 'user_id' => $this->teacher->id, 'tag' => CommentTag::Other->value, 'body' => 'Bring a hat']);
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);

        $comment->update(['tag' => CommentTag::Belongings->value]);
        Http::assertNothingSent();

        $comment->update(['body' => 'Bring a hat and sunscreen']);
        Http::assertSent(fn ($r) => $r[0]['body'] === 'Note updated: Bring a hat and sunscreen');
    }

    public function test_a_symptom_at_drop_off_pushes_and_appears_in_the_timeline_but_normal_does_not(): void
    {
        $screening = app(HealthScreening::class);

        $screening->record($this->row, HealthAudit::IN, 0, null, $this->teacher);
        Http::assertNothingSent();

        $screening->record($this->row->fresh(), HealthAudit::IN, 4, 'Felt warm', $this->teacher);
        Http::assertSent(fn ($r) => $r[0]['to'] === 'ExponentPushToken[kate]'
            && $r[0]['title'] === 'Mia · Health'
            && $r[0]['body'] === 'Health check at drop-off: Fever — Felt warm');

        $entries = collect($this->withToken($this->signIn())->getJson("/api/parent/children/{$this->mia->id}/daily")->assertOk()->json('entries'));
        $health = $entries->firstWhere('type', 'health');
        $this->assertSame('Health check at drop-off', $health['title']);
        $this->assertSame('Fever — Felt warm', $health['detail']);
    }

    public function test_a_parent_who_switched_daily_report_pushes_off_gets_none(): void
    {
        ParentNotificationPref::create(['person_id' => $this->kate->id, 'messages' => true, 'announcements' => true, 'daily' => false, 'photos' => true]);

        $this->row->comments()->create(['child_id' => $this->mia->id, 'user_id' => $this->teacher->id, 'tag' => CommentTag::Health->value, 'body' => 'fever']);

        Http::assertNothingSent();
    }

    /* ---- helpers ---- */

    private function parentOf(Child $child, string $name, string $cell): Person
    {
        $person = Person::create(['name' => $name, 'cell' => $cell]);
        ChildPerson::create(['child_id' => $child->id, 'person_id' => $person->id, 'relationship' => 'Mother', 'gets_texts' => true, 'is_guardian' => true]);

        return $person;
    }

    private function signIn(string $phone = '5855550134'): string
    {
        $this->postJson('/api/parent/login/start', ['phone' => $phone])->assertOk();
        preg_match('/code is (\d{6})/', (string) end($this->texts)['body'], $m);

        return $this->postJson('/api/parent/login/verify', ['phone' => $phone, 'code' => $m[1] ?? ''])->assertOk()->json('token');
    }
}
