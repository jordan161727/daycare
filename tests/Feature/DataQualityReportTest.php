<?php

namespace Tests\Feature;

use App\Console\Commands\DataQualityReport;
use App\Models\Child;
use App\Models\ChildNote;
use App\Models\ChildPerson;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** data:quality-report — the tester's findings, read-only, with made-up TEST- records. */
class DataQualityReportTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['children', 'people', 'child_people', 'child_notes', 'users', 'message_threads', 'messages', 'attendances'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function child(string $lan, string $first, string $last, array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => $lan, 'first_name' => $first, 'last_name' => $last,
            'status' => 'Active', 'dob' => '2023-03-01',
        ]);
    }

    /** A guardian who is also the emergency contact, so the child is complete for section 5. */
    private function guardian(Child $child, ?Person $person = null): Person
    {
        $person ??= Person::create(['name' => 'TEST Parent '.$child->lan, 'cell' => '7165550100']);
        ChildPerson::create(['child_id' => $child->id, 'person_id' => $person->id, 'relationship' => 'Mother', 'is_guardian' => true, 'is_emergency' => true, 'priority' => 1]);

        return $person;
    }

    private function staff(string $name): User
    {
        return User::create(['name' => $name, 'email' => 'test-'.uniqid().'@example.test', 'password' => 'secret-test', 'role' => 'teacher']);
    }

    public function test_every_section_finds_the_planted_examples_and_writes_nothing(): void
    {
        // 1. Three copies of one child (same name, DOB, hours) — one of them real.
        $real = $this->child('TEST-102', 'Jace', 'Miller', ['dob' => '2022-05-04', 'drop_off_time' => '07:00', 'pick_up_time' => '20:00']);
        $this->guardian($real);
        DB::table('attendances')->insert(['child_id' => $real->id, 'attendance_date' => '2026-11-10', 'signed_in_at' => '2026-11-10 07:05:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->child('TEST-103', 'jace', ' MILLER ', ['dob' => '2022-05-04', 'drop_off_time' => '07:00', 'pick_up_time' => '20:00']);
        $this->child('TEST-104', 'Jace ', 'Miller', ['dob' => '2022-05-04', 'drop_off_time' => '07:00', 'pick_up_time' => '20:00']);
        // Same name, different birthday: the weaker list.
        $this->guardian($this->child('TEST-110', 'Ava', 'Stone', ['dob' => '2021-01-01']));
        $this->guardian($this->child('TEST-111', 'Ava', 'Stone', ['dob' => '2021-01-10']));

        // 2. One restriction on three unrelated families, as an alert, a custody note and a restriction.
        $a = $this->child('TEST-201', 'Una', 'First', ['alerts' => [['type' => 'custody', 'text' => 'No pickup by TEST Stranger']]]);
        $b = $this->child('TEST-202', 'Ben', 'Second', ['custody_note' => 'no pickup by  test stranger!']);
        $c = $this->child('TEST-203', 'Cat', 'Third');
        foreach ([$a, $b, $c] as $kid) {
            $this->guardian($kid);
        }
        $stranger = Person::create(['name' => 'TEST Stranger']);
        ChildPerson::create(['child_id' => $c->id, 'person_id' => $stranger->id, 'restriction' => 'No pickup by TEST Stranger']);
        // A note with a phone number on three families: listed, the number never printed.
        foreach ([$a, $b, $c] as $kid) {
            ChildNote::create(['child_id' => $kid->id, 'kind' => 'office', 'body' => 'Call 716-555-0199 first']);
        }

        // Three siblings (one shared guardian) with the same allergy: one family, not listed.
        $mum = null;
        foreach (['TEST-301', 'TEST-302', 'TEST-303'] as $i => $lan) {
            $sib = $this->child($lan, 'Sib'.$i, 'Family', ['alerts' => [['type' => 'allergy', 'text' => 'Kiwi fruit allergy']]]);
            $mum = $this->guardian($sib, $mum);
        }

        // 3. Junk and misspelt alerts.
        $this->guardian($this->child('TEST-401', 'Joy', 'Junk', ['alerts' => [
            ['type' => 'allergy', 'text' => '! Unknown'],
            ['type' => 'allergy', 'text' => '! Seasonal'],
            ['type' => 'note', 'text' => '-'],
            ['type' => 'medical', 'text' => 'Excema on arms'],
            ['type' => 'medical', 'text' => 'Asthama', 'action' => 'Epi pen in office'],
            ['type' => 'allergy', 'text' => 'Allergie to dogs'],
            ['type' => 'medical', 'text' => 'Inhaler in office'],
        ]]));

        // 4. Test messages and placeholder staff.
        $thread = MessageThread::create(['kind' => 'office', 'child_id' => $real->id]);
        foreach (['Hi', 'Test', 'zxczxcxz', 'See you at five'] as $body) {
            Message::create(['thread_id' => $thread->id, 'direction' => 'out', 'body' => $body]);
        }
        $this->staff('Infant Teacher');
        $this->staff('rona');
        $this->staff('Beth Jones');
        $this->staff('Beth Jones');
        $this->staff('Proper Name');

        // 5. Active children missing basics; a withdrawn one is not listed.
        $this->child('TEST-501', 'Nod', 'Dob', ['dob' => null]);
        $restricted = $this->child('TEST-502', 'Only', 'Restricted');
        ChildPerson::create(['child_id' => $restricted->id, 'person_id' => $stranger->id, 'is_guardian' => true, 'is_emergency' => true, 'restriction' => 'Court order']);
        $this->child('TEST-503', 'Gone', 'Withdrawn', ['status' => 'Inactive']);

        $before = $this->snapshot();

        $output = $this->report();

        foreach ([
            // 1
            'TEST-102', 'TEST-103', 'TEST-104', '2022-05-04', '07:00–20:00',
            '1 group(s). Keep the one with attendance',
            'TEST-110', '2021-01-10', '1 name(s).',
            // 2
            'No pickup by TEST Stranger', 'alert ×1', 'custody note ×1', 'restriction ×1',
            'TEST-201, TEST-202, TEST-203',
            'Call [number] first',
            '2 text(s).',
            // 3
            '"! Unknown"', '"! Seasonal"', '"-"', '3 alert(s).',
            'Excema', 'eczema', 'Asthama', 'asthma', 'Epi pen', 'EpiPen', 'Allergie', '4 word(s).',
            // 4
            '"Hi"', '"Test"', '"zxczxcxz"', 'keyboard mash', '3 message(s).',
            'Infant Teacher', 'placeholder name', 'rona', 'all lowercase',
            'duplicate name', 'badge shows "BJ2", not "BJ"',
            // 5
            'TEST-501', 'date of birth', 'TEST-502', 'guardian, emergency contact',
        ] as $text) {
            $this->assertStringContainsString($text, $output);
        }

        $this->assertStringNotContainsString('Kiwi fruit', $output);
        $this->assertStringNotContainsString('See you at five', $output);
        $this->assertStringNotContainsString('Proper Name', $output);
        $this->assertStringNotContainsString('Inhaler in office', $output);
        $this->assertStringNotContainsString('Withdrawn', $output);
        $this->assertStringNotContainsString('555', $output);

        $this->assertSame($before, $this->snapshot(), 'The report must not write anything.');
    }

    public function test_section_five_counts(): void
    {
        $this->child('TEST-501', 'Nod', 'Dob', ['dob' => null]);
        $this->guardian($this->child('TEST-502', 'All', 'There'));

        $output = $this->report();

        $this->assertStringContainsString('1 with no date of birth, 1 with no guardian, 1 with no emergency contact.', $output);
        $this->assertStringNotContainsString('All There', $output);
    }

    public function test_heuristics(): void
    {
        $this->assertTrue(DataQualityReport::isKeyboardMash('zxczxcxz'));
        $this->assertTrue(DataQualityReport::isKeyboardMash('asdasdasd'));
        $this->assertFalse(DataQualityReport::isKeyboardMash('thanks'));
        $this->assertFalse(DataQualityReport::isKeyboardMash('ok see you'));
        $this->assertSame('test word', DataQualityReport::testMessageReason(' test. '));
        $this->assertNull(DataQualityReport::testMessageReason('Hi, Mia had a good day'));

        $this->assertTrue(DataQualityReport::isPlaceholder('! Unknown'));
        $this->assertTrue(DataQualityReport::isPlaceholder('!!'));
        $this->assertTrue(DataQualityReport::isPlaceholder('N/A'));
        $this->assertFalse(DataQualityReport::isPlaceholder('Peanuts'));

        $this->assertSame(['Excema' => 'eczema'], DataQualityReport::misspellings('Excema'));
        $this->assertSame([], DataQualityReport::misspellings('eczema and asthma, EpiPen'));

        $this->assertSame(['placeholder name'], DataQualityReport::staffNameProblems('PreK Teacher'));
        $this->assertSame(['placeholder name'], DataQualityReport::staffNameProblems('Test Account'));
        $this->assertSame(['all lowercase'], DataQualityReport::staffNameProblems('jordan'));
        $this->assertSame([], DataQualityReport::staffNameProblems('Rona Smith'));

        $this->assertSame('Call [number] or 911', DataQualityReport::safeText('Call (716) 555-0199 or 911'));
    }

    public function test_an_empty_database_reports_cleanly(): void
    {
        $output = $this->report();

        $this->assertStringContainsString('0 group(s).', $output);
        $this->assertStringContainsString('0 text(s).', $output);
        $this->assertStringContainsString('0 message(s).', $output);
    }

    /** Runs the command, checks it exits 0, and hands back everything it printed. */
    private function report(): string
    {
        $this->assertSame(0, Artisan::call('data:quality-report'));

        return Artisan::output();
    }

    private function snapshot(): array
    {
        return collect(self::TABLES)
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])
            ->all();
    }
}
