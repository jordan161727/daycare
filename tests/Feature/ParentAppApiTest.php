<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\ParentDevice;
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
 * The parent app's API (docs/parent-app-api.md): sign in by texted code,
 * see only your own children's conversations, write into the same thread
 * the office reads, and hear by push when the centre writes back.
 */
class ParentAppApiTest extends TestCase
{
    use RefreshDatabase;

    /** Every text "sent", so a test can read the code off it. */
    private array $texts = [];

    private Child $mia;

    private Person $kate;

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
        $this->kate = $this->parentOf($this->mia, 'Kate Testwell', '(585) 555-0134');
    }

    /* ---- signing in ---- */

    public function test_a_parent_signs_in_with_a_texted_code_and_gets_a_token(): void
    {
        $this->postJson('/api/parent/login/start', ['phone' => '585-555-0134'])
            ->assertOk()->assertJson(['sent' => true, 'expires_in' => 600]);

        $this->assertCount(1, $this->texts);
        $this->assertSame('+15855550134', $this->texts[0]['to']);
        $this->assertMatchesRegularExpression('/Your AngelNest code is \d{6}\./', $this->texts[0]['body']);

        $response = $this->postJson('/api/parent/login/verify', ['phone' => '5855550134', 'code' => $this->lastCode(), 'device_name' => 'Kate’s iPhone'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('parent.name', 'Kate Testwell')
            ->assertJsonPath('parent.children.0.name', $this->mia->displayName())
            ->assertJsonMissingPath('parent.ssn');

        $token = $response->json('token');
        $this->withToken($token)->getJson('/api/parent/me')->assertOk()->assertJsonPath('id', $this->kate->id);

        // The token runs out, and says it is a parent's.
        $row = $this->kate->tokens()->first();
        $this->assertSame(['parent'], $row->abilities);
        $this->assertTrue($row->expires_at->between(now()->addDays(89), now()->addDays(91)));
    }

    public function test_an_unknown_or_not_allowed_number_gets_the_same_answer_and_no_text(): void
    {
        $other = Child::create(['lan' => '10002', 'status' => 'Active', 'first_name' => 'Leo', 'last_name' => 'Nottexted']);
        $this->parentOf($other, 'No Texts', '585 555 0001', ['gets_texts' => false]);
        $this->parentOf($other, 'Court Order', '585 555 0002', ['restriction' => 'No contact']);
        $gone = Child::create(['lan' => '10003', 'status' => 'Inactive', 'first_name' => 'Gus', 'last_name' => 'Gone']);
        $this->parentOf($gone, 'Left Already', '585 555 0003');

        foreach (['585 555 9999', '585 555 0001', '585 555 0002', '585 555 0003'] as $phone) {
            $this->postJson('/api/parent/login/start', ['phone' => $phone])->assertOk()->assertJson(['sent' => true]);
            $this->postJson('/api/parent/login/verify', ['phone' => $phone, 'code' => '123456'])->assertStatus(422)->assertJsonValidationErrors('code');
        }

        $this->assertSame([], $this->texts);
    }

    public function test_a_code_is_spent_once_dies_after_ten_minutes_and_five_wrong_tries(): void
    {
        $this->postJson('/api/parent/login/start', ['phone' => '5855550134']);
        $code = $this->lastCode();

        $this->postJson('/api/parent/login/verify', ['phone' => '5855550134', 'code' => $code])->assertOk();
        $this->postJson('/api/parent/login/verify', ['phone' => '5855550134', 'code' => $code])->assertStatus(422);

        // Five wrong guesses and even the right code is refused.
        $this->postJson('/api/parent/login/start', ['phone' => '5855550134']);
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/parent/login/verify', ['phone' => '5855550134', 'code' => $wrong])->assertStatus(422);
        }
        $this->postJson('/api/parent/login/verify', ['phone' => '5855550134', 'code' => $code])->assertStatus(422);

        // Asking again gives a fresh code; ten minutes later it is dead.
        $this->travelBack();
        $this->postJson('/api/parent/login/start', ['phone' => '5855550134']);
        $code = $this->lastCode();
        $this->travel(11)->minutes();
        $this->postJson('/api/parent/login/verify', ['phone' => '5855550134', 'code' => $code])->assertStatus(422);
    }

    public function test_the_code_comes_back_in_the_answer_only_on_a_local_sandbox(): void
    {
        // Off by default, and the setting alone is not enough.
        $this->postJson('/api/parent/login/start', ['phone' => '5855550134'])->assertOk()->assertJsonMissingPath('debug_code');
        config(['daycare.parent_app.show_codes' => true]);
        $this->postJson('/api/parent/login/start', ['phone' => '5855550134'])->assertOk()->assertJsonMissingPath('debug_code');

        $this->app['env'] = 'local';
        $this->postJson('/api/parent/login/start', ['phone' => '5855550134'])->assertOk()->assertJsonPath('debug_code', $this->lastCode());
        // Still nothing to give away for a number that is not on file.
        $this->postJson('/api/parent/login/start', ['phone' => '5855559999'])->assertOk()->assertJsonMissingPath('debug_code');
    }

    public function test_sign_in_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/parent/login/start', ['phone' => '585 555 0134'])->assertOk();
        }
        $this->postJson('/api/parent/login/start', ['phone' => '585 555 0134'])->assertStatus(429);
    }

    public function test_ping_says_whether_the_server_answers_and_who_is_signed_in(): void
    {
        $this->getJson('/api/parent/ping')->assertOk()
            ->assertJson(['connected' => true, 'signed_in' => false, 'parent' => null]);

        $token = $this->signIn();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/parent/ping')->assertOk()
            ->assertJson(['connected' => true, 'signed_in' => true, 'parent' => ['id' => $this->kate->id, 'name' => 'Kate Testwell']]);

        // A dead token is not an error here, just "not signed in".
        $this->app['auth']->forgetGuards();
        $this->withToken('1|nope')->getJson('/api/parent/ping')->assertOk()->assertJsonPath('signed_in', false);
    }

    public function test_without_a_parent_token_nothing_answers(): void
    {
        $this->getJson('/api/parent/threads')->assertStatus(401);
        $this->withToken('1|not-a-real-token')->getJson('/api/parent/threads')->assertStatus(401);

        // Logging out kills the token.
        $this->app['auth']->forgetGuards();
        $token = $this->signIn();
        $this->withToken($token)->postJson('/api/parent/logout')->assertOk();
        $this->assertSame(0, $this->kate->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/parent/threads')->assertStatus(401);

        // A staff account is not a parent, even signed in.
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->actingAs($this->director)->getJson('/api/parent/threads')->assertStatus(403);
    }

    /* ---- conversations ---- */

    public function test_the_inbox_is_your_own_children_and_announcements_only(): void
    {
        $messaging = app(Messaging::class);
        $messaging->sendToFamily($this->mia, MessageThread::ROOM, 'Picnic Friday, please bring sunscreen.', [], $this->director);
        $messaging->sendToFamily($this->mia, MessageThread::OFFICE, 'Tuition reminder for October.', [], $this->director);
        $messaging->announce('We are closed Monday for staff training.', [], $this->director);

        $other = Child::create(['lan' => '10002', 'status' => 'Active', 'first_name' => 'Leo', 'last_name' => 'Other', 'classroom' => 'PreK']);
        $this->parentOf($other, 'Other Parent', '585 555 0777');
        $theirs = $messaging->sendToFamily($other, MessageThread::ROOM, 'Not for Kate.', [], $this->director);

        $token = $this->signIn();
        $threads = $this->withToken($token)->getJson('/api/parent/threads')->assertOk()->json('threads');

        $this->assertEqualsCanonicalizing(['room', 'office', 'ann'], array_column($threads, 'kind'));
        $room = collect($threads)->firstWhere('kind', 'room');
        $this->assertSame('Room · Toddler', $room['kind_label']);
        $this->assertSame(1, $room['unread']);
        $this->assertTrue($room['can_reply']);
        $this->assertFalse(collect($threads)->firstWhere('kind', 'ann')['can_reply']);

        // Another family's conversation does not exist, as far as Kate can tell.
        $this->withToken($token)->getJson('/api/parent/threads/'.$theirs->thread_id)->assertNotFound();
        $this->withToken($token)->postJson('/api/parent/threads/'.$theirs->thread_id.'/messages', ['body' => 'hi'])->assertNotFound();
    }

    public function test_announcements_from_before_the_parent_was_on_file_are_not_shown(): void
    {
        $old = app(Messaging::class)->announce('Old news.', [], $this->director);
        MessageThread::whereKey($old['thread_id'])->update(['created_at' => now()->subYear()]);

        $token = $this->signIn();
        $this->assertSame([], $this->withToken($token)->getJson('/api/parent/threads')->json('threads'));
    }

    public function test_a_message_from_the_app_lands_in_the_conversation_the_office_reads(): void
    {
        $sent = app(Messaging::class)->sendToFamily($this->mia, MessageThread::ROOM, 'Picnic Friday.', [], $this->director);
        $token = $this->signIn();

        $this->withToken($token)->postJson('/api/parent/threads/'.$sent->thread_id.'/messages', ['body' => 'Thanks, we will!'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Thanks, we will!')
            ->assertJsonPath('message.from.is_me', true)
            ->assertJsonPath('message.channel', 'app');

        $message = Message::latest('id')->first();
        $this->assertSame(Message::IN, $message->direction);
        $this->assertSame(Message::APP, $message->channel);
        $this->assertSame($this->kate->id, $message->person_id);

        // The staff Messages page shows it, marked as from the app, and unread.
        $payload = app(Messaging::class)->payload($message->thread, $this->director);
        $this->assertStringContainsString('Kate Testwell', end($payload['msgs'])['meta']);
        $this->assertStringContainsString('via the parent app', end($payload['msgs'])['meta']);
        $this->assertTrue(collect(app(Messaging::class)->listFor($this->director))->firstWhere('id', $sent->thread_id)['unread']);

        // Kate's own message is not unread for her.
        $this->assertSame(0, collect($this->withToken($token)->getJson('/api/parent/threads')->json('threads'))->firstWhere('id', $sent->thread_id)['unread']);
    }

    public function test_the_conversation_pages_and_polls_and_marks_read(): void
    {
        $messaging = app(Messaging::class);
        foreach (['One', 'Two', 'Three'] as $text) {
            $last = $messaging->sendToFamily($this->mia, MessageThread::ROOM, $text, [], $this->director);
        }
        $token = $this->signIn();
        $id = $last->thread_id;

        $page = $this->withToken($token)->getJson("/api/parent/threads/{$id}?limit=2")->assertOk()->json('messages');
        $this->assertSame(['Two', 'Three'], array_column($page, 'body'));
        $this->assertSame('centre', $page[0]['from']['type']);
        $this->assertSame('Ray · Toddler', $page[0]['from']['name']);

        $older = $this->withToken($token)->getJson("/api/parent/threads/{$id}?before_id=".$page[0]['id'])->json('messages');
        $this->assertSame(['One'], array_column($older, 'body'));

        $messaging->sendToFamily($this->mia, MessageThread::ROOM, 'Four', [], $this->director);
        $newer = $this->withToken($token)->getJson("/api/parent/threads/{$id}?after_id=".$page[1]['id'])->json('messages');
        $this->assertSame(['Four'], array_column($newer, 'body'));

        $this->withToken($token)->postJson("/api/parent/threads/{$id}/read")->assertOk();
        $this->assertSame(0, $this->withToken($token)->getJson('/api/parent/threads')->json('threads.0.unread'));
    }

    public function test_announcements_cannot_be_answered_but_an_office_conversation_can_be_started(): void
    {
        $ann = app(Messaging::class)->announce('Closed Monday.', [], $this->director);
        $token = $this->signIn();

        $this->withToken($token)->postJson('/api/parent/threads/'.$ann['thread_id'].'/messages', ['body' => 'Why?'])->assertStatus(422);

        $thread = $this->withToken($token)->postJson('/api/parent/children/'.$this->mia->id.'/threads', ['kind' => 'office'])
            ->assertOk()->assertJsonPath('thread.kind', 'office')->json('thread');
        $this->withToken($token)->postJson('/api/parent/threads/'.$thread['id'].'/messages', ['body' => 'Is Monday a holiday?'])->assertCreated();

        // Not for a child who is not theirs.
        $other = Child::create(['lan' => '10002', 'status' => 'Active', 'first_name' => 'Leo', 'last_name' => 'Other']);
        $this->withToken($token)->postJson('/api/parent/children/'.$other->id.'/threads', ['kind' => 'office'])->assertNotFound();
    }

    public function test_a_photo_is_uploaded_then_sent_and_only_your_own_uploads_count(): void
    {
        $thread = app(Messaging::class)->sendToFamily($this->mia, MessageThread::ROOM, 'Hi', [], $this->director)->thread;
        $token = $this->signIn();

        $upload = $this->withToken($token)->post('/api/parent/uploads', ['file' => UploadedFile::fake()->image('mia.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('kind', 'photo')->json();
        $this->assertStringStartsWith('messages/media/parent-'.$this->kate->id.'/', $upload['path']);

        $this->withToken($token)->postJson("/api/parent/threads/{$thread->id}/messages", ['media' => [['path' => $upload['path'], 'kind' => 'photo']]])
            ->assertCreated()->assertJsonPath('message.media.0.kind', 'photo');

        // Somebody else's file, or a staff upload, is refused.
        $this->withToken($token)->postJson("/api/parent/threads/{$thread->id}/messages", ['media' => [['path' => 'messages/media/someone-else.jpg', 'kind' => 'photo']]])
            ->assertStatus(422)->assertJsonValidationErrors('media.0.path');
        $this->withToken($token)->postJson("/api/parent/threads/{$thread->id}/messages", [])->assertStatus(422);
    }

    public function test_withdrawing_the_child_or_unticking_texts_closes_the_app_at_once(): void
    {
        $sent = app(Messaging::class)->sendToFamily($this->mia, MessageThread::ROOM, 'Hi', [], $this->director);
        $token = $this->signIn();

        ChildPerson::where('person_id', $this->kate->id)->update(['gets_texts' => false]);

        $this->withToken($token)->getJson('/api/parent/me')->assertOk()->assertJsonPath('children', []);
        $this->assertSame([], $this->withToken($token)->getJson('/api/parent/threads')->json('threads'));
        $this->withToken($token)->getJson('/api/parent/threads/'.$sent->thread_id)->assertNotFound();
    }

    /* ---- push ---- */

    public function test_a_staff_message_pushes_to_the_parents_phone(): void
    {
        config(['daycare.parent_app.push_driver' => 'expo']);
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);

        $token = $this->signIn();
        $this->withToken($token)->postJson('/api/parent/devices', ['push_token' => 'ExponentPushToken[kate]', 'platform' => 'ios'])->assertCreated();

        app(Messaging::class)->sendToFamily($this->mia, MessageThread::ROOM, 'Picnic Friday!', [], $this->director);

        Http::assertSent(fn ($request) => $request->url() === 'https://exp.host/--/api/v2/push/send'
            && $request[0]['to'] === 'ExponentPushToken[kate]'
            && $request[0]['title'] === 'Mia · Toddler'
            && $request[0]['body'] === 'Picnic Friday!'
            && is_int($request[0]['data']['thread_id']));

        // Still texted too, unless the app is set to replace texts.
        $this->assertCount(2, $this->texts); // the sign-in code and the message
    }

    public function test_with_replaces_sms_on_a_parent_with_the_app_is_not_texted(): void
    {
        config(['daycare.parent_app.replaces_sms' => true]);
        $token = $this->signIn();
        $this->withToken($token)->postJson('/api/parent/devices', ['push_token' => 'ExponentPushToken[kate]'])->assertCreated();
        $before = count($this->texts);

        $message = app(Messaging::class)->sendToFamily($this->mia, MessageThread::ROOM, 'Picnic Friday!', [], $this->director);

        $this->assertCount($before, $this->texts);
        $this->assertSame(1, $message->fresh()->delivered);
    }

    public function test_a_device_moves_to_whoever_signed_in_on_it_last_and_can_be_forgotten(): void
    {
        $token = $this->signIn();
        $this->withToken($token)->postJson('/api/parent/devices', ['push_token' => 'ExponentPushToken[shared]'])->assertCreated();

        $dan = $this->parentOf($this->mia, 'Dan Testwell', '585 555 0135');
        // One app serves every request in a test, and the guard keeps the
        // last parent it resolved; a real request always starts fresh.
        $danToken = $this->signIn('5855550135');
        $this->app['auth']->forgetGuards();
        $this->withToken($danToken)->getJson('/api/parent/me')->assertJsonPath('id', $dan->id);
        $this->withToken($danToken)->postJson('/api/parent/devices', ['push_token' => 'ExponentPushToken[shared]'])->assertCreated();
        $this->assertSame($dan->id, ParentDevice::where('push_token', 'ExponentPushToken[shared]')->value('person_id'));

        $this->withToken($danToken)->deleteJson('/api/parent/devices', ['push_token' => 'ExponentPushToken[shared]'])->assertOk();
        $this->assertSame(0, ParentDevice::count());
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
