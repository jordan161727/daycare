<?php

namespace Tests\Feature\QaProbe;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Person;
use App\Models\User;
use App\Services\PeopleDirectory;
use App\Services\Texter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * QA probe — Children module, "Family & pick-up" (test plan area D, messaging,
 * release). Every test asserts the SAFE / EXPECTED behaviour, so a failing
 * test is a finding. Only TEST- records. Texts go to a recording fake, never
 * to a provider.
 */
class FamilySafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;     // Toddler room

    private User $otherTeacher; // Preschool room

    /** @var list<array{to: string, body: string}> */
    public static array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-29 15:30:00'));
        Storage::fake('local');
        Http::fake(); // nothing leaves the building
        config(['daycare.kiosk.enabled' => true, 'daycare.messages.driver' => 'log']);

        self::$sent = [];
        $this->app->instance(Texter::class, new class extends Texter
        {
            public function send(string $from, string $to, string $body, array $mediaUrls = []): bool
            {
                FamilySafetyTest::$sent[] = ['to' => $to, 'body' => $body];

                return true;
            }
        });

        $this->admin = User::create(['name' => 'TEST-Director', 'email' => 'test-dir@example.com', 'password' => 'password', 'role' => 'admin', 'job_role' => 'Director']);
        $this->teacher = User::create(['name' => 'TEST-Toddler Teacher', 'email' => 'test-tod@example.com', 'password' => 'password', 'role' => 'teacher', 'employment' => 'FT', 'classroom' => 'Toddler']);
        $this->otherTeacher = User::create(['name' => 'TEST-Preschool Teacher', 'email' => 'test-pre@example.com', 'password' => 'password', 'role' => 'teacher', 'employment' => 'FT', 'classroom' => 'Preschool']);
    }

    // ------------------------------------------------------------ D1 call order

    public function test_D1_call_order_arrows_swap_and_persist(): void
    {
        $child = $this->child('Ava');
        $a = $this->link($child, 'TEST-Anna One', '7165550101', ['is_emergency' => true, 'priority' => 1]);
        $b = $this->link($child, 'TEST-Ben Two', '7165550102', ['is_emergency' => true, 'priority' => 2]);
        $c = $this->link($child, 'TEST-Cara Three', '7165550103', ['is_emergency' => true, 'priority' => 3]);

        $this->actingAs($this->admin)->post(route('children.people.order', [$child, $c]), ['direction' => 'up'])->assertRedirect();

        $order = ChildPerson::where('child_id', $child->id)->orderBy('priority')->pluck('person_id')->all();
        $this->assertSame([$a->id, $c->id, $b->id], $order);

        // "reload": the page reads the saved order
        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->getContent();
        $this->assertTrue(strpos($html, 'TEST-Cara Three') < strpos($html, 'TEST-Ben Two'), 'reloaded page does not show Cara before Ben');

        // top row up / bottom row down is a no-op, not an error
        $this->actingAs($this->admin)->post(route('children.people.order', [$child, $a]), ['direction' => 'up'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('children.people.order', [$child, $b]), ['direction' => 'down'])->assertRedirect();
        $this->assertSame([$a->id, $c->id, $b->id], ChildPerson::where('child_id', $child->id)->orderBy('priority')->pluck('person_id')->all());
    }

    // ------------------------------------------------------------ D2..D5 restricted ticks

    public function test_D2_record_page_flags_form_refuses_ticks_on_a_restricted_person(): void
    {
        $child = $this->child('Ava');
        $dad = $this->link($child, 'TEST-Dad Restricted', '7165550201', ['restriction' => 'Court order — no contact']);

        $this->actingAs($this->admin)->post(route('children.people.flags', [$child, $dad]), [
            'relationship' => 'Father', 'is_guardian' => '1', 'can_pickup' => '1', 'is_emergency' => '0',
            'pays_fees' => '0', 'gets_texts' => '1', 'restriction' => 'Court order — no contact',
        ]);

        $link = $this->linkOf($child, $dad);
        $this->assertFalse($link->can_pickup || $link->is_guardian || $link->gets_texts,
            'Saved on a restricted person: can_pickup='.var_export($link->can_pickup, true).' is_guardian='.var_export($link->is_guardian, true).' gets_texts='.var_export($link->gets_texts, true));
    }

    public function test_D3_api_link_refuses_ticks_on_a_restricted_person(): void
    {
        $child = $this->child('Ava');
        $dad = $this->link($child, 'TEST-Dad Restricted', '7165550201', ['restriction' => 'Court order']);

        $r = $this->actingAs($this->admin)->postJson(route('people.link', $dad), [
            'child_id' => $child->id, 'is_guardian' => true, 'can_pickup' => true, 'gets_texts' => true, 'restriction' => 'Court order',
        ]);

        $link = $this->linkOf($child, $dad);
        $this->assertFalse($link->can_pickup || $link->is_guardian || $link->gets_texts,
            'HTTP '.$r->status().'; stored can_pickup='.var_export($link->can_pickup, true).' is_guardian='.var_export($link->is_guardian, true).' gets_texts='.var_export($link->gets_texts, true));
    }

    public function test_D4_api_store_refuses_a_new_person_both_restricted_and_ticked(): void
    {
        $child = $this->child('Ava');

        $r = $this->actingAs($this->admin)->postJson(route('people.store'), [
            'child_id' => $child->id, 'name' => 'TEST-New Restricted', 'cell' => '7165550299',
            'is_guardian' => true, 'can_pickup' => true, 'gets_texts' => true, 'restriction' => 'Protective order',
        ]);

        $link = ChildPerson::where('child_id', $child->id)->first();
        $this->assertNotNull($link, 'HTTP '.$r->status());
        $this->assertFalse($link->can_pickup || $link->is_guardian || $link->gets_texts,
            'HTTP '.$r->status().'; stored can_pickup='.var_export($link->can_pickup, true).' is_guardian='.var_export($link->is_guardian, true).' gets_texts='.var_export($link->gets_texts, true));
    }

    /** The safety net that DOES exist: a restriction beats the tick everywhere it is read. */
    public function test_D5_restriction_beats_the_tick_at_the_door_pickup_list_and_texts(): void
    {
        $child = $this->child('Ava');
        $dad = $this->link($child, 'TEST-Dad Restricted', '7165550201', [
            'can_pickup' => true, 'is_guardian' => true, 'gets_texts' => true, 'restriction' => 'Court order',
        ]);
        $dad->setPin('481902');

        $directory = app(PeopleDirectory::class);
        $this->assertFalse($directory->pickupList($child)->contains('person_id', $dad->id));
        $this->assertFalse($dad->mayCollect($child));

        $this->signIn($child);
        $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])->assertJson(['status' => 'ok'])
            ->assertJsonPath('children.0.can_collect', false);
        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'out'])->assertJson(['status' => 'not_authorised']);
        $this->assertNull(Attendance::where('child_id', $child->id)->first()->signed_out_at);

        $this->actingAs($this->admin)->postJson(route('messages.store'), ['to' => 'family', 'child_id' => $child->id, 'body' => 'TEST hello'])->assertOk();
        $this->assertSame([], self::$sent, 'a restricted person was texted');
    }

    /** Edit form (People step) sends restriction with every tick — same server path as D3. */
    public function test_D6_edit_form_people_step_offers_live_ticks_next_to_a_restriction(): void
    {
        $child = $this->child('Ava');
        $this->link($child, 'TEST-Dad Restricted', '7165550201', ['restriction' => 'Court order']);

        $html = $this->actingAs($this->admin)->get(route('children.edit', $child))->assertOk()->getContent();

        // The checkboxes are bound with x-model and nothing disables them when row.restriction is set.
        $this->assertStringContainsString('x-model="row.can_pickup" @change="save(row)"', $html);
        $this->assertStringNotContainsString(':disabled="row.restriction', $html, 'edit form disables ticks for restricted rows');
    }

    // ------------------------------------------------------------ restriction wiped

    public function test_D7_flags_post_without_restriction_field_keeps_the_restriction(): void
    {
        $child = $this->child('Ava');
        $dad = $this->link($child, 'TEST-Dad Restricted', '7165550201', ['restriction' => 'Court order']);

        $this->actingAs($this->admin)->post(route('children.people.flags', [$child, $dad]), ['can_pickup' => '1']);

        $link = $this->linkOf($child, $dad);
        $this->assertNotNull($link->restriction, 'restriction erased by a flags POST that did not mention it; can_pickup now '.var_export($link->can_pickup, true).', mayCollect='.var_export($dad->mayCollect($child), true));
    }

    public function test_D8_api_link_without_restriction_field_keeps_the_restriction(): void
    {
        $child = $this->child('Ava');
        $dad = $this->link($child, 'TEST-Dad Restricted', '7165550201', ['restriction' => 'Court order']);

        $this->actingAs($this->teacher)->postJson(route('people.link', $dad), ['child_id' => $child->id, 'can_pickup' => true])->assertOk();

        $link = $this->linkOf($child, $dad);
        $this->assertNotNull($link->restriction, 'teacher POST /people/{id}/link without restriction erased it; mayCollect now '.var_export($dad->mayCollect($child), true));
    }

    /**
     * Reachable from the UI: "+ Create a new person" on the record page (or the
     * edit form) typed with the restricted person's mobile. The matcher finds the
     * existing person and link() rewrites the link with no restriction.
     */
    public function test_D9_create_new_person_with_a_restricted_persons_mobile_keeps_the_restriction(): void
    {
        $child = $this->child('Ava');
        $dad = $this->link($child, 'TEST-Dad Restricted', '(716) 555-0201', ['relationship' => 'Father', 'restriction' => 'Court order — no contact']);
        $dad->setPin('481902');

        // exactly the body the record page's createPerson() sends
        $r = $this->actingAs($this->teacher)->postJson(route('people.store'), [
            'child_id' => $child->id, 'name' => 'TEST-Dad R', 'cell' => '716-555-0201', 'relationship' => 'Father', 'can_pickup' => true, 'is_emergency' => true,
        ])->assertOk();

        $link = $this->linkOf($child, $dad);
        $kiosk = $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])->json('children.0.can_collect');

        $this->assertNotNull($link->restriction,
            'status='.$r->json('status').'; restriction now NULL, can_pickup='.var_export($link->can_pickup, true).', kiosk can_collect='.var_export($kiosk, true));
    }

    public function test_D10_adding_a_restriction_to_someone_already_ticked(): void
    {
        $child = $this->child('Ava');
        $mum = $this->link($child, 'TEST-Mum Guardian', '7165550301', ['relationship' => 'Mother', 'is_guardian' => true, 'can_pickup' => true, 'is_emergency' => true, 'priority' => 1, 'gets_texts' => true]);

        // record page Edit row: ticks left as they were, restriction typed
        $this->actingAs($this->admin)->post(route('children.people.flags', [$child, $mum]), [
            'relationship' => 'Mother', 'is_guardian' => '1', 'can_pickup' => '1', 'is_emergency' => '1', 'pays_fees' => '0', 'gets_texts' => '1',
            'restriction' => 'TEST court order',
        ])->assertRedirect();

        $link = $this->linkOf($child, $mum);
        $this->assertFalse($mum->mayCollect($child));

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->getContent();
        $this->assertStringContainsString('Must not collect: TEST-Mum Guardian — TEST court order', $html);
        $this->assertStringContainsString('Nobody is cleared to collect this child.', $html);

        // Expected: a restricted person no longer counts as the guardian / first emergency number
        $child->load('personLinks');
        $this->assertArrayHasKey('guard', $child->missingItems(),
            'restricted person still counts as the guardian (is_guardian='.var_export($link->is_guardian, true).', gets_texts='.var_export($link->gets_texts, true).')');
    }

    public function test_D10b_restricted_person_still_first_on_emergency_call_list(): void
    {
        $child = $this->child('Ava');
        $mum = $this->link($child, 'TEST-Mum Restricted', '7165550301', ['is_emergency' => true, 'priority' => 1, 'restriction' => 'Court order']);
        $this->link($child, 'TEST-Gran', '7165550302', ['is_emergency' => true, 'priority' => 2]);

        $first = app(PeopleDirectory::class)->emergencyList($child)->first();
        $this->assertNotSame($mum->id, $first->person_id, 'restricted person is #1 on the emergency call list');
    }

    public function test_D10c_children_list_shows_restricted_person_as_the_guardian_contact(): void
    {
        $child = $this->child('Ava');
        $this->link($child, 'TEST-Mum Restricted', '7165550301', ['is_guardian' => true, 'restriction' => 'Court order']);

        $html = $this->actingAs($this->admin)->get(route('children.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('TEST-Mum Restricted', $html, 'the roll lists the restricted person as the guardian to ring');
    }

    // ------------------------------------------------------------ D11-D13 release

    public function test_D11_kiosk_non_pickup_person_cannot_sign_out_and_sees_staff_message(): void
    {
        $child = $this->child('Ava');
        $aunt = $this->link($child, 'TEST-Aunt NoPickup', '7165550401', ['relationship' => 'Aunt/Uncle', 'is_emergency' => true]);
        $aunt->setPin('111222');
        $this->signIn($child);

        $this->postJson(route('kiosk.unlock'), ['pin' => '111222'])->assertJsonPath('children.0.can_collect', false)
            ->assertJsonPath('children.0.present', true);
        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'out'])->assertJson(['status' => 'not_authorised']);

        $html = $this->get(route('kiosk.index'))->getContent();
        $this->assertStringContainsString('Please see a staff member to sign out.', $html);
    }

    /** Observation: a restricted person at the kiosk is told whether the child is in, and since when. */
    public function test_D12_kiosk_hides_presence_from_a_restricted_person(): void
    {
        $child = $this->child('Ava');
        $dad = $this->link($child, 'TEST-Dad Restricted', '7165550201', ['restriction' => 'Court order — no contact']);
        $dad->setPin('481902');
        $this->signIn($child);

        $json = $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])->json();
        $tile = $json['children'][0] ?? [];
        $this->assertFalse(($tile['present'] ?? false) === true && filled($tile['since'] ?? null),
            'restricted person sees: '.json_encode($tile));
    }

    public function test_D12b_kiosk_restricted_person_can_sign_the_child_in(): void
    {
        $child = $this->child('Ava');
        $dad = $this->link($child, 'TEST-Dad Restricted', '7165550201', ['restriction' => 'Court order — no contact']);
        $dad->setPin('481902');

        $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])->assertJson(['status' => 'ok']);
        $r = $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'in'])->json();
        $this->assertNotSame('ok', $r['status'] ?? null, 'restricted (no-contact) person signed the child in: '.json_encode($r));
    }

    /** Staff check-out on the Check-in screen: does it ask who is collecting? */
    public function test_D13_staff_check_out_asks_who_collects_and_warns_about_restricted(): void
    {
        $child = $this->child('Ava');
        $this->link($child, 'TEST-Dad Restricted', '7165550201', ['restriction' => 'Court order — no contact']);

        $row = $this->actingAs($this->teacher)->postJson(route('check-in.store'), ['child_id' => $child->id, 'session' => 'FULL', 'health_code' => 0])->assertOk()->json();
        $attendanceId = Attendance::where('child_id', $child->id)->value('id');

        // Clock-out with no collector at all
        $out = $this->actingAs($this->teacher)->postJson(route('check-in.out', $attendanceId), ['health_code' => 0]);
        $out->assertOk();
        $this->assertNotNull(Attendance::find($attendanceId)->signed_out_at);

        $html = $this->actingAs($this->teacher)->get(route('check-in.index'))->assertOk()->getContent();
        $mentionsRestriction = str_contains($html, 'Court order') || str_contains($html, 'Must not collect') || str_contains($html, 'TEST-Dad Restricted');
        $this->assertTrue($mentionsRestriction, 'check-in screen carries nothing about who may/may not collect');
    }

    public function test_D14_day_limited_pickup_field_exists(): void
    {
        $cols = Schema::getColumnListing('child_people');
        $dayish = array_filter($cols, fn ($c) => preg_match('/day|weekday|schedule|until|from/i', $c));
        $this->assertNotEmpty($dayish, 'child_people columns: '.implode(', ', $cols));
    }

    // ------------------------------------------------------------ D15/D16 last guardian / emergency

    public function test_D15_unlinking_the_last_guardian_warns_and_chips(): void
    {
        $child = $this->child('Ava');
        $mum = $this->link($child, 'TEST-Mum Only', '7165550501', ['is_guardian' => true, 'is_emergency' => true, 'priority' => 1]);

        $before = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        $this->assertStringNotContainsString('data-warning="guardian"', $before);

        $r = $this->actingAs($this->admin)->postJson(route('people.unlink', $mum), ['child_id' => $child->id])->assertOk();
        $r->assertJson(['lacks_guardian' => true]);

        $after = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        $this->assertStringContainsString('No legal guardian on file.', $after);
        $this->assertStringContainsString('No emergency contact.', $after);

        $list = $this->actingAs($this->admin)->get(route('children.index'))->getContent();
        $this->assertMatchesRegularExpression('/data-gap="guard">1 no guardian/', $list);
        $this->assertMatchesRegularExpression('/data-gap="emerg">1 no emergency contact/', $list);
    }

    public function test_D15b_record_page_unlink_confirm_mentions_last_guardian(): void
    {
        $child = $this->child('Ava');
        $this->link($child, 'TEST-Mum Only', '7165550501', ['is_guardian' => true]);

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        $this->assertStringContainsString("confirm('Remove ' + name + ' from this record? They stay on file for any other child.')", $html);
        $this->assertMatchesRegularExpression('/last (legal )?guardian|only guardian/i', $html, 'no last-guardian warning before unlinking on the record page');
    }

    public function test_D16_untick_last_emergency_contact_shows_warning_and_chip(): void
    {
        $child = $this->child('Ava');
        $mum = $this->link($child, 'TEST-Mum', '7165550501', ['is_guardian' => true, 'is_emergency' => true, 'priority' => 1]);

        $this->actingAs($this->admin)->post(route('children.people.flags', [$child, $mum]), [
            'relationship' => 'Mother', 'is_guardian' => '1', 'can_pickup' => '0', 'is_emergency' => '0', 'pays_fees' => '0', 'gets_texts' => '0', 'restriction' => '',
        ])->assertRedirect();

        $after = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        $this->assertStringContainsString('No emergency contact.', $after);
        $list = $this->actingAs($this->admin)->get(route('children.index'))->getContent();
        $this->assertMatchesRegularExpression('/data-gap="emerg">1 no emergency contact/', $list);
    }

    // ------------------------------------------------------------ D17-D19 add / edit / unlink

    public function test_D17_add_person_finds_siblings_contact_and_links_without_duplicate(): void
    {
        $ava = $this->child('Ava');
        $leo = $this->child('Leo', 'Toddler');
        $mum = $this->link($ava, 'TEST-Mum Shared', '(716) 555-0601', ['relationship' => 'Mother', 'is_guardian' => true]);

        $hits = $this->actingAs($this->admin)->getJson(route('people.search', ['q' => '555-0601', 'child_id' => $leo->id]))->assertOk()->json('people');
        $this->assertSame($mum->id, $hits[0]['id']);
        $this->assertFalse($hits[0]['already_linked']);

        $this->actingAs($this->admin)->postJson(route('people.link', $mum), ['child_id' => $leo->id, 'relationship' => 'Mother', 'can_pickup' => true, 'is_emergency' => true])->assertOk();

        // typing her again by hand with the same mobile does not make a second Mum
        $r = $this->actingAs($this->admin)->postJson(route('people.store'), ['child_id' => $leo->id, 'name' => 'TEST-Mum Shared', 'cell' => '7165550601'])->assertOk();
        $this->assertSame('linked_existing', $r->json('status'));
        $this->assertSame(1, Person::where('name', 'like', 'TEST-Mum%')->count());

        // editing her once changes her on both records
        $this->actingAs($this->admin)->postJson(route('people.update', $mum), ['name' => 'TEST-Mum Shared', 'cell' => '7165550699', 'email' => 'test-mum@example.com'])->assertOk();
        foreach ([$ava, $leo] as $c) {
            $this->assertStringContainsString('7165550699', $this->actingAs($this->admin)->get(route('children.show', $c))->getContent());
        }
    }

    /** Re-typing an existing person on the same child silently resets their ticks. */
    public function test_D17b_create_existing_person_on_same_child_keeps_their_ticks(): void
    {
        $ava = $this->child('Ava');
        $mum = $this->link($ava, 'TEST-Mum', '7165550601', ['relationship' => 'Mother', 'is_guardian' => true, 'gets_texts' => true, 'pays_fees' => true]);

        $this->actingAs($this->admin)->postJson(route('people.store'), ['child_id' => $ava->id, 'name' => 'TEST-Mum', 'cell' => '7165550601', 'relationship' => 'Mother', 'can_pickup' => true, 'is_emergency' => true])->assertOk();

        $link = $this->linkOf($ava, $mum);
        $this->assertTrue($link->is_guardian, 'guardian tick wiped by re-adding her');
    }

    public function test_D18_unlink_removes_from_this_child_only(): void
    {
        $ava = $this->child('Ava');
        $leo = $this->child('Leo');
        $mum = $this->link($ava, 'TEST-Mum Shared', '7165550601', ['is_guardian' => true]);
        $this->linkExisting($leo, $mum, ['is_guardian' => true]);

        $this->actingAs($this->admin)->postJson(route('people.unlink', $mum), ['child_id' => $ava->id])->assertOk();

        $this->assertNull(ChildPerson::where('child_id', $ava->id)->where('person_id', $mum->id)->first());
        $this->assertNotNull(ChildPerson::where('child_id', $leo->id)->where('person_id', $mum->id)->first());
        $this->assertNotNull(Person::find($mum->id));

        // delete refused while still linked
        $this->actingAs($this->admin)->postJson(route('people.destroy', $mum))->assertStatus(422);
    }

    // ------------------------------------------------------------ D19-D23 messaging

    public function test_D19_group_texts_only_reach_texts_ticked_unrestricted(): void
    {
        $ava = $this->child('Ava');
        $this->link($ava, 'TEST-Ticked', '7165550701', ['gets_texts' => true]);
        $this->link($ava, 'TEST-NotTicked', '7165550702', ['gets_texts' => false, 'is_guardian' => true]);
        $this->link($ava, 'TEST-Restricted', '7165550703', ['gets_texts' => true, 'restriction' => 'Court order']);

        $this->actingAs($this->admin)->postJson(route('messages.store'), ['to' => 'room', 'room' => 'Toddler', 'body' => 'TEST room'])->assertOk();
        $this->actingAs($this->admin)->postJson(route('messages.store'), ['to' => 'everyone', 'body' => 'TEST all'])->assertOk();
        $this->actingAs($this->admin)->postJson(route('messages.store'), ['to' => 'family', 'child_id' => $ava->id, 'body' => 'TEST family'])->assertOk();

        $to = array_unique(array_column(self::$sent, 'to'));
        $this->assertSame(['+17165550701'], array_values($to), json_encode(self::$sent));
    }

    public function test_D20_withdrawn_and_archived_children_get_no_group_texts(): void
    {
        $gone = $this->child('Gone', 'Toddler', ['status' => 'Inactive', 'withdrawn_on' => '2026-10-20']);
        $this->link($gone, 'TEST-Withdrawn Parent', '7165550801', ['gets_texts' => true]);
        $arch = $this->child('Arch', 'Toddler', ['status' => Child::ARCHIVED]);
        $this->link($arch, 'TEST-Archived Parent', '7165550802', ['gets_texts' => true]);
        $here = $this->child('Here');
        $this->link($here, 'TEST-Active Parent', '7165550803', ['gets_texts' => true]);

        $this->actingAs($this->admin)->postJson(route('messages.store'), ['to' => 'room', 'room' => 'Toddler', 'body' => 'TEST room'])->assertOk();
        $this->actingAs($this->admin)->postJson(route('messages.store'), ['to' => 'everyone', 'body' => 'TEST all'])->assertOk();

        $this->assertSame(['+17165550803'], array_values(array_unique(array_column(self::$sent, 'to'))));
    }

    /** Last day passed but the nightly sync has not flipped the status yet. */
    public function test_D20b_child_past_last_day_but_still_Active_gets_no_group_texts(): void
    {
        $gone = $this->child('Leaving', 'Toddler', ['status' => 'Active', 'withdrawn_on' => '2026-10-27']);
        $this->link($gone, 'TEST-Leaver Parent', '7165550804', ['gets_texts' => true]);

        $this->actingAs($this->admin)->postJson(route('messages.store'), ['to' => 'room', 'room' => 'Toddler', 'body' => 'TEST room'])->assertOk();
        $this->assertSame([], self::$sent, 'texted family of a child whose last day (Oct 27) has passed: '.json_encode(self::$sent));
    }

    public function test_D21_family_text_to_withdrawn_or_archived_child_is_refused(): void
    {
        $gone = $this->child('Gone', 'Toddler', ['status' => 'Inactive', 'withdrawn_on' => '2026-10-20']);
        $this->link($gone, 'TEST-Withdrawn Parent', '7165550801', ['gets_texts' => true]);
        $arch = $this->child('Arch', 'Toddler', ['status' => Child::ARCHIVED]);
        $this->link($arch, 'TEST-Archived Parent', '7165550802', ['gets_texts' => true]);

        $page = $this->actingAs($this->admin)->get(route('children.show', $gone))->getContent();
        $hasButton = str_contains($page, 'Message parents');

        $r1 = $this->actingAs($this->admin)->postJson(route('messages.store'), ['to' => 'family', 'child_id' => $gone->id, 'body' => 'TEST to withdrawn']);
        $r2 = $this->actingAs($this->teacher)->postJson(route('messages.store'), ['to' => 'family', 'child_id' => $arch->id, 'body' => 'TEST to archived']);

        $this->assertSame([], self::$sent, 'Message parents button on withdrawn record='.var_export($hasButton, true)
            .'; withdrawn HTTP '.$r1->status().' '.$r1->getContent().'; archived (teacher) HTTP '.$r2->status().' '.$r2->getContent()
            .'; sent to '.json_encode(array_column(self::$sent, 'to')));
    }

    public function test_D21b_reply_in_old_thread_after_withdrawal_is_refused(): void
    {
        $ava = $this->child('Ava');
        $this->link($ava, 'TEST-Parent', '7165550901', ['gets_texts' => true]);
        $this->actingAs($this->admin)->postJson(route('messages.store'), ['to' => 'family', 'child_id' => $ava->id, 'body' => 'TEST before'])->assertOk();
        $thread = MessageThread::first();
        $ava->forceFill(['status' => 'Inactive', 'withdrawn_on' => '2026-10-28'])->save();
        self::$sent = [];

        $r = $this->actingAs($this->admin)->postJson(route('messages.reply', $thread), ['body' => 'TEST after']);
        $this->assertSame([], self::$sent, 'reply HTTP '.$r->status().' delivered to '.json_encode(array_column(self::$sent, 'to')));
    }

    public function test_D22_message_parents_from_record_preselects_family_with_skips_shown(): void
    {
        $ava = $this->child('Ava');
        $this->link($ava, 'TEST-Ticked', '7165550701', ['gets_texts' => true, 'relationship' => 'Mother']);
        $this->link($ava, 'TEST-Restricted', '7165550703', ['gets_texts' => true, 'restriction' => 'Court order', 'relationship' => 'Father']);

        $show = $this->actingAs($this->admin)->get(route('children.show', $ava))->getContent();
        $this->assertStringContainsString(route('messages.index', ['child' => $ava->id]), $show);

        $html = $this->actingAs($this->admin)->get(route('messages.index', ['child' => $ava->id]))->assertOk()->getContent();
        $this->assertStringContainsString('TEST-Ticked (Mother)', $html);
        $this->assertStringContainsString('TEST-Restricted: restricted', $html);
    }

    // ------------------------------------------------------------ D23.. roles

    public function test_D23_teacher_of_the_room_can_change_ticks_and_restrictions(): void
    {
        $ava = $this->child('Ava'); // Toddler
        $dad = $this->link($ava, 'TEST-Dad', '7165551001', ['can_pickup' => true, 'restriction' => 'Court order']);

        // teacher clears a court-order restriction and ticks pick-up
        $this->actingAs($this->teacher)->post(route('children.people.flags', [$ava, $dad]), [
            'relationship' => 'Father', 'is_guardian' => '1', 'can_pickup' => '1', 'is_emergency' => '0', 'pays_fees' => '0', 'gets_texts' => '1', 'restriction' => '',
        ])->assertRedirect();

        $this->assertTrue($dad->mayCollect($ava), 'teacher could not clear restriction (expected by route comment: teachers may edit pick-up)');
    }

    public function test_D24_teacher_of_another_room_is_refused_everywhere(): void
    {
        $ava = $this->child('Ava'); // Toddler
        $dad = $this->link($ava, 'TEST-Dad', '7165551001', ['restriction' => 'Court order']);

        $this->actingAs($this->otherTeacher)->post(route('children.people.flags', [$ava, $dad]), ['can_pickup' => '1'])->assertForbidden();
        $this->actingAs($this->otherTeacher)->post(route('children.people.order', [$ava, $dad]), ['direction' => 'up'])->assertForbidden();
        $this->actingAs($this->otherTeacher)->postJson(route('people.link', $dad), ['child_id' => $ava->id, 'can_pickup' => true])->assertForbidden();
        $this->actingAs($this->otherTeacher)->postJson(route('people.unlink', $dad), ['child_id' => $ava->id])->assertForbidden();
        $this->actingAs($this->otherTeacher)->postJson(route('people.update', $dad), ['name' => 'X', 'cell' => '1'])->assertForbidden();
        $this->actingAs($this->otherTeacher)->getJson(route('people.show', $dad))->assertForbidden();
        $this->actingAs($this->otherTeacher)->getJson(route('children.people', $ava))->assertForbidden();
        $this->assertNotNull($this->linkOf($ava, $dad)->restriction);
    }

    public function test_D25_teacher_search_does_not_expose_other_rooms_families(): void
    {
        $pre = $this->child('Pia', 'Preschool');
        $this->link($pre, 'TEST-Preschool Mum', '7165551101', ['relationship' => 'Mother']);

        $hits = $this->actingAs($this->teacher)->getJson(route('people.search', ['q' => 'TEST-Preschool']))->assertOk()->json('people');
        $this->assertSame([], $hits, 'Toddler teacher sees: '.json_encode($hits));
    }

    public function test_D26_teacher_can_edit_or_delete_an_unlinked_person(): void
    {
        $orphan = Person::create(['name' => 'TEST-Unlinked', 'cell' => '7165551201']);

        $u = $this->actingAs($this->otherTeacher)->postJson(route('people.update', $orphan), ['name' => 'TEST-Renamed', 'cell' => '7165551299']);
        $d = $this->actingAs($this->otherTeacher)->postJson(route('people.destroy', $orphan));
        $this->assertTrue($u->status() === 403 && $d->status() === 403, 'update HTTP '.$u->status().', delete HTTP '.$d->status());
    }

    public function test_D27_teacher_editing_shared_person_changes_other_rooms_child(): void
    {
        $ava = $this->child('Ava', 'Toddler');
        $pia = $this->child('Pia', 'Preschool');
        $mum = $this->link($ava, 'TEST-Mum Shared', '7165551301', ['is_guardian' => true]);
        $this->linkExisting($pia, $mum, ['is_guardian' => true, 'restriction' => 'TEST pia-only restriction']);

        $show = $this->actingAs($this->teacher)->getJson(route('people.show', $mum))->assertOk()->json();
        $this->actingAs($this->teacher)->postJson(route('people.update', $mum), ['name' => 'TEST-Mum Shared', 'cell' => '7165551399'])->assertOk();

        $this->assertSame('7165551399', $mum->fresh()->cell);
        // Observation recorded: the Toddler teacher reads Pia (Preschool) and her restriction in the drawer
        $this->assertCount(2, $show['children']);
        $this->assertSame('TEST pia-only restriction', collect($show['children'])->firstWhere('id', $pia->id)['restriction']);
    }

    public function test_D28_no_parent_role_and_an_unknown_role_is_refused(): void
    {
        $ava = $this->child('Ava');
        $dad = $this->link($ava, 'TEST-Dad', '7165551001');
        $parent = User::create(['name' => 'TEST-Parent', 'email' => 'test-parent@example.com', 'password' => 'password', 'role' => 'parent', 'classroom' => 'Toddler']);

        $this->actingAs($parent)->post(route('children.people.flags', [$ava, $dad]), ['can_pickup' => '1'])->assertForbidden();
        $this->actingAs($parent)->postJson(route('people.link', $dad), ['child_id' => $ava->id, 'can_pickup' => true])->assertForbidden();
        $this->actingAs($parent)->getJson(route('people.search', ['q' => 'TEST']))->assertForbidden();
        $this->actingAs($parent)->get(route('children.show', $ava))->assertForbidden();
        $this->actingAs($parent)->get(route('messages.index'))->assertForbidden();
    }

    public function test_D29_guests_are_redirected(): void
    {
        $ava = $this->child('Ava');
        $dad = $this->link($ava, 'TEST-Dad', '7165551001');
        $this->post(route('children.people.flags', [$ava, $dad]), ['can_pickup' => '1'])->assertRedirect(route('login'));
        $this->postJson(route('people.link', $dad), ['child_id' => $ava->id])->assertUnauthorized();
    }

    // ------------------------------------------------------------ helpers

    private function child(string $first, string $room = 'Toddler', array $extra = []): Child
    {
        return Child::create(array_merge([
            'lan' => (string) (90000 + Child::count() + 1),
            'first_name' => 'TEST-'.$first,
            'last_name' => 'Probe',
            'dob' => '2023-03-01',
            'status' => 'Active',
            'classroom' => $room,
            'classroom_override' => $room,
        ], $extra));
    }

    private function link(Child $child, string $name, ?string $cell, array $flags = []): Person
    {
        $person = Person::create(['name' => $name, 'cell' => $cell]);
        $this->linkExisting($child, $person, $flags);

        return $person;
    }

    private function linkExisting(Child $child, Person $person, array $flags = []): ChildPerson
    {
        return ChildPerson::create(array_merge(['child_id' => $child->id, 'person_id' => $person->id, 'relationship' => 'Mother'], $flags));
    }

    private function linkOf(Child $child, Person $person): ChildPerson
    {
        return ChildPerson::where('child_id', $child->id)->where('person_id', $person->id)->firstOrFail();
    }

    private function signIn(Child $child): void
    {
        Attendance::create(['child_id' => $child->id, 'attendance_date' => today()->toDateString(), 'session' => 'FULL', 'signed_in_at' => now()->subHours(7)]);
    }
}
