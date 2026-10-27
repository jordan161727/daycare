<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildDocument;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Person;
use App\Models\User;
use App\Services\Messaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Messages — Phase 9: texts to families.
 *
 * What these pin: who gets a text is the child record's "Texts" tick less
 * the people the law or the record says to skip; an Office conversation
 * never reaches a teacher; a reply lands in the right family's thread; and
 * Polish changes the words, not the meaning.
 */
class MessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $maryJo;

    private Child $ava;

    private Person $daniel;

    private Person $rosa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-26 09:15:00'));
        Storage::fake('local');

        $this->admin = User::create(['name' => 'Ray Director', 'email' => 'ray@example.com', 'password' => 'password', 'role' => 'admin']);
        $this->maryJo = User::create(['name' => 'Mary Jo', 'email' => 'maryjo@example.com', 'password' => 'password', 'role' => 'teacher', 'employment' => 'FT', 'classroom' => 'Toddler']);

        $this->ava = Child::create(['lan' => '1001', 'first_name' => 'Ava', 'last_name' => 'Cruz', 'status' => 'Active', 'classroom' => 'Toddler', 'classroom_override' => 'Toddler']);
        $this->daniel = $this->contact($this->ava, 'Daniel Ortiz', 'Father', '(716) 555-0101', true);
        $this->rosa = $this->contact($this->ava, 'Rosa Cruz', 'Mother', '(716) 555-0102', true);
    }

    // ---------------- who gets a text ----------------

    public function test_recipients_are_the_texts_tick_less_the_people_to_skip(): void
    {
        $leo = Child::create(['lan' => '1002', 'first_name' => 'Leo', 'last_name' => 'Shah', 'status' => 'Active', 'classroom' => 'Toddler', 'classroom_override' => 'Toddler']);
        $this->contact($leo, 'Priya Shah', 'Mother', '(716) 555-0201', true);
        $this->contact($leo, 'Raj Shah', 'Father', '(716) 555-0202', false);                       // Texts not ticked
        $stopped = $this->contact($leo, 'Uncle Sam', 'Uncle', '(716) 555-0203', true);
        $stopped->forceFill(['texts_stopped_at' => now()])->save();                                 // replied STOP
        $this->contact($leo, 'Paul Ito', 'Father', '(716) 555-0204', true, 'court order');           // restricted
        $this->contact($leo, 'Grandma Shah', 'Grandmother', null, true);                            // no mobile

        $r = app(Messaging::class)->recipientsFor($leo->fresh());

        $this->assertSame(['Priya Shah'], array_column($r['gets'], 'name'));
        $this->assertSame([
            'Raj Shah' => 'Texts not ticked',
            'Uncle Sam' => 'replied STOP',
            'Paul Ito' => 'restricted — court order',
            'Grandma Shah' => 'no mobile number',
        ], array_column($r['skipped'], 'why', 'name'));
    }

    // ---------------- sending ----------------

    public function test_a_room_message_from_a_teacher_opens_the_family_conversation_and_delivers_to_both_parents(): void
    {
        $this->actingAs($this->maryJo)->postJson(route('messages.store'), [
            'to' => 'family', 'child_id' => $this->ava->id, 'body' => 'Hi, Ava is down to 2 diapers. Could you send a pack in tomorrow?',
        ])->assertOk()->assertJsonPath('summary', '2 delivered');

        $thread = MessageThread::sole();
        $this->assertSame(['room', $this->ava->id, 'Toddler'], [$thread->kind, $thread->child_id, $thread->classroom]);

        $message = Message::sole();
        $this->assertSame(2, $message->delivered);
        $this->assertSame($this->maryJo->id, $message->user_id);

        // On the page: the thread, named after the parent, signed from the Classroom line.
        $this->actingAs($this->maryJo)->get(route('messages.index'))->assertOk()->assertSee('Daniel Ortiz');
        $json = $this->actingAs($this->maryJo)->getJson(route('messages.show', $thread))->assertOk()->json();
        $this->assertSame('Daniel Ortiz', $json['name']);
        $this->assertSame('Ava Cruz · Toddler · Daniel Ortiz, Rosa Cruz', $json['sub']);
        $this->assertStringContainsString('Classroom line', $json['line']);
        $this->assertStringContainsString('Mary · Toddler', $json['line']);
    }

    public function test_an_office_conversation_is_hidden_from_teachers(): void
    {
        $this->actingAs($this->admin)->postJson(route('messages.store'), [
            'to' => 'family', 'child_id' => $this->ava->id, 'kind' => 'office', 'body' => 'A reminder that October tuition ($1,240) is due this Friday.',
        ])->assertOk();

        $thread = MessageThread::sole();
        $this->assertSame('office', $thread->kind);

        // The office sees it.
        $this->actingAs($this->admin)->get(route('messages.index'))->assertOk()->assertSee('tuition');
        $this->actingAs($this->admin)->getJson(route('messages.show', $thread))->assertOk();

        // The Toddler teacher does not — not on the list, not by address, not to reply.
        $this->actingAs($this->maryJo)->get(route('messages.index'))->assertOk()->assertDontSee('tuition');
        $this->actingAs($this->maryJo)->getJson(route('messages.show', $thread))->assertNotFound();
        $this->actingAs($this->maryJo)->postJson(route('messages.reply', $thread), ['body' => 'hi'])->assertNotFound();

        // And a teacher cannot start one.
        $this->actingAs($this->maryJo)->postJson(route('messages.store'), ['to' => 'family', 'child_id' => $this->ava->id, 'kind' => 'office', 'body' => 'x'])->assertStatus(422);
    }

    public function test_a_room_message_lands_in_every_family_of_the_room_except_the_ones_unticked(): void
    {
        $leo = Child::create(['lan' => '1002', 'first_name' => 'Leo', 'last_name' => 'Shah', 'status' => 'Active', 'classroom' => 'Toddler', 'classroom_override' => 'Toddler']);
        $this->contact($leo, 'Priya Shah', 'Mother', '(716) 555-0201', true);
        $mia = Child::create(['lan' => '1003', 'first_name' => 'Mia', 'last_name' => 'Alvarez', 'status' => 'Active', 'classroom' => 'Infant', 'classroom_override' => 'Infant']);
        $this->contact($mia, 'Amira Haddad', 'Mother', '(716) 555-0301', true);

        // Who it would reach, first.
        $rows = $this->actingAs($this->admin)->getJson(route('messages.recipients', ['to' => 'room', 'room' => 'Toddler']))->assertOk()->json('rows');
        $this->assertSame(['Ava Cruz', 'Leo Shah'], array_column($rows, 'child'));
        $this->assertTrue($rows[0]['no_photo'], 'no photo consent on file should be flagged');

        $this->actingAs($this->admin)->postJson(route('messages.store'), [
            'to' => 'room', 'room' => 'Toddler', 'body' => 'Tomorrow we are painting, so please send a spare shirt.', 'exclude' => [$leo->id],
        ])->assertOk()->assertJsonPath('summary', '1 families · 2 delivered · 0 skipped');

        // One thread, Ava's; Leo was left out, Mia is in another room.
        $this->assertSame([$this->ava->id], MessageThread::pluck('child_id')->all());
    }

    public function test_an_announcement_reaches_everyone_once_and_counts_who_was_skipped(): void
    {
        $leo = Child::create(['lan' => '1002', 'first_name' => 'Leo', 'last_name' => 'Shah', 'status' => 'Active', 'classroom' => 'Infant', 'classroom_override' => 'Infant']);
        // Daniel also has Leo: one text, not two.
        $leo->personLinks()->create(['person_id' => $this->daniel->id, 'relationship' => 'Father', 'gets_texts' => true]);
        $stopped = $this->contact($leo, 'Sam Patel', 'Uncle', '(716) 555-0401', true);
        $stopped->forceFill(['texts_stopped_at' => now()])->save();

        $json = $this->actingAs($this->admin)->postJson(route('messages.store'), [
            'to' => 'everyone', 'body' => 'Little Angels is closed Monday Oct 26 for staff training.',
        ])->assertOk()->json();

        $this->assertSame('2 families · 2 delivered · 1 skipped', $json['summary']);

        $thread = MessageThread::find($json['thread_id']);
        $this->assertSame('ann', $thread->kind);
        $message = $thread->messages()->sole();
        $this->assertSame(2, $message->delivered);
        $this->assertSame('replied STOP', $message->skipped[0]['why']);

        // One-way: no reply box, and the teacher can read it.
        $this->actingAs($this->maryJo)->getJson(route('messages.show', $thread))->assertOk()->assertJsonPath('canReply', false);
        $this->actingAs($this->maryJo)->postJson(route('messages.reply', $thread), ['body' => 'ok'])->assertStatus(422);
    }

    // ---------------- replies ----------------

    public function test_a_parents_reply_lands_in_the_family_thread_and_is_unread_until_opened(): void
    {
        $this->actingAs($this->maryJo)->postJson(route('messages.store'), ['to' => 'family', 'child_id' => $this->ava->id, 'body' => 'Is picture day this Friday?'])->assertOk();
        $thread = MessageThread::sole();

        // Nothing waiting yet: the centre spoke last.
        $this->assertSame(0, MessageThread::unreadFor($this->maryJo));

        $this->post(route('sms.inbound'), ['From' => '+17165550101', 'To' => '+17165550143', 'Body' => 'Next Friday I think?'])->assertOk();

        $reply = $thread->messages()->where('direction', 'in')->sole();
        $this->assertSame($this->daniel->id, $reply->person_id);

        // Waiting on the Toddler teacher and the office; not on an Infant teacher.
        $this->assertSame(1, MessageThread::unreadFor($this->maryJo));
        $this->assertSame(1, MessageThread::unreadFor($this->admin));
        $infant = User::create(['name' => 'Farhana', 'email' => 'f@example.com', 'password' => 'password', 'role' => 'teacher', 'classroom' => 'Infant']);
        $this->assertSame(0, MessageThread::unreadFor($infant));

        // The badge on the menu, then opening it clears it.
        $this->actingAs($this->maryJo)->get(route('dashboard'))->assertSee('data-unread', false);
        $this->actingAs($this->maryJo)->getJson(route('messages.show', $thread))->assertOk();
        $this->assertSame(0, MessageThread::unreadFor($this->maryJo));
    }

    public function test_a_reply_to_the_office_line_is_an_office_conversation_and_stop_is_honoured(): void
    {
        $this->post(route('sms.inbound'), ['From' => '7165550102', 'To' => '+17165550142', 'Body' => 'Can I pay on Monday instead?'])->assertOk();

        $thread = MessageThread::sole();
        $this->assertSame(['office', $this->ava->id], [$thread->kind, $thread->child_id]);

        $this->post(route('sms.inbound'), ['From' => '7165550102', 'To' => '+17165550143', 'Body' => 'STOP'])->assertOk();
        $this->assertNotNull($this->rosa->fresh()->texts_stopped_at);
        $this->assertSame(1, Message::count(), 'STOP is not a message');

        // From then on Rosa is skipped, and the page says why.
        $r = app(Messaging::class)->recipientsFor($this->ava->fresh());
        $this->assertSame(['Daniel Ortiz'], array_column($r['gets'], 'name'));
        $this->assertSame('replied STOP', $r['skipped'][0]['why']);
    }

    public function test_a_text_from_an_unknown_number_is_ignored(): void
    {
        $this->post(route('sms.inbound'), ['From' => '+15550000000', 'To' => '+17165550143', 'Body' => 'hello?'])->assertOk();

        $this->assertSame(0, MessageThread::count());
    }

    // ---------------- polish and media ----------------

    public function test_polish_fixes_the_words_and_keeps_the_meaning(): void
    {
        config()->set('services.gemini.key', null);

        $json = $this->actingAs($this->maryJo)->postJson(route('messages.polish'), ['text' => 'leo eat all his lunch today and nap good, 2 hour'])->assertOk()->json();

        $this->assertSame('Leo eat all his lunch today and nap good, 2 hour.', $json['text']);

        $json = $this->actingAs($this->maryJo)->postJson(route('messages.polish'), ['text' => 'tomorow we doing painting so pls send extra shirt'])->assertOk()->json();
        $this->assertSame('Tomorrow we doing painting so please send extra shirt.', $json['text']);
    }

    public function test_a_photo_is_uploaded_then_sent_as_a_picture_message_with_a_signed_link(): void
    {
        $upload = $this->actingAs($this->maryJo)->post(route('messages.upload'), ['file' => UploadedFile::fake()->image('finger-painting.jpg', 640, 480)])->assertOk()->json();

        $this->assertSame('photo', $upload['kind']);
        Storage::disk('local')->assertExists($upload['path']);

        $this->actingAs($this->maryJo)->postJson(route('messages.store'), [
            'to' => 'family', 'child_id' => $this->ava->id, 'body' => 'First finger painting!', 'media' => [['path' => $upload['path'], 'kind' => 'photo', 'name' => 'finger-painting.jpg', 'size' => '0.1 MB']],
        ])->assertOk();

        $message = Message::sole();
        $this->assertSame($upload['path'], $message->media[0]['path']);

        // The link in the text opens without a login, and nothing else does.
        $this->get($upload['url'])->assertOk();
        $this->get(route('messages.media', ['path' => $upload['path']]))->assertForbidden();
        $this->get(route('messages.media', ['path' => '../.env', 'signature' => 'x']))->assertForbidden();
    }

    public function test_the_child_record_opens_a_new_message_to_that_family(): void
    {
        $html = $this->actingAs($this->maryJo)->get(route('messages.index', ['child' => $this->ava->id]))->assertOk()->getContent();

        $this->assertStringContainsString('Daniel Ortiz (Father) and Rosa Cruz (Mother)', $html);
        $this->assertStringContainsString('data-view="new"', $html);
    }

    // ---------------- helpers ----------------

    private function contact(Child $child, string $name, string $rel, ?string $cell, bool $texts, ?string $restriction = null): Person
    {
        $person = Person::create(['name' => $name, 'cell' => $cell]);
        $child->personLinks()->create(['person_id' => $person->id, 'relationship' => $rel, 'gets_texts' => $texts, 'restriction' => $restriction]);

        return $person;
    }
}
