<?php

namespace Tests\Feature;

use App\Console\Commands\ChildrenDataReport;
use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** children:data-report — the QA #41 lists, read-only, with the QA examples. */
class ChildrenDataReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function child(array $attributes): Child
    {
        return Child::create($attributes + ['status' => 'Active']);
    }

    public function test_it_lists_the_qa_examples_and_writes_nothing(): void
    {
        // Sage Lester, 6, still stored in Infant: a room the nightly sync never moved.
        $sage = $this->child(['lan' => '10110', 'first_name' => 'Sage', 'last_name' => 'Lester', 'dob' => '2020-06-01']);
        DB::table('children')->where('id', $sage->id)->update(['classroom' => 'Infant']);

        // Ezio Szatkowski, 6, held in UPK-4 by a director's override.
        $this->child(['lan' => '10106', 'first_name' => 'Ezio', 'last_name' => 'Szatkowski', 'dob' => '2020-03-01', 'classroom_override' => 'UPK-4']);

        // A child in the right room is not listed.
        $this->child(['lan' => '10200', 'first_name' => 'Fine', 'last_name' => 'Fitting', 'dob' => '2024-06-01']);

        // A withdrawn child in the wrong room is not listed either.
        $gone = $this->child(['lan' => '10300', 'first_name' => 'Gone', 'last_name' => 'Away', 'dob' => '2019-01-01', 'status' => 'Inactive']);
        DB::table('children')->where('id', $gone->id)->update(['classroom' => 'Infant']);

        // Latavia Hedgespeth with two mobiles: one on a person record, one in the old column.
        $kid = $this->child([
            'lan' => '10120', 'first_name' => 'Kai', 'last_name' => 'Hedgespeth', 'dob' => '2023-01-01',
            'mother_name' => 'Latavia Hedgespeth', 'mother_cell' => '716 335 5680', 'nickname' => ' n/a ',
        ]);
        $latavia = Person::create(['name' => 'Latavia Hedgespeth', 'cell' => '+17163355080']);
        ChildPerson::create(['child_id' => $kid->id, 'person_id' => $latavia->id, 'relationship' => 'Mother', 'is_guardian' => true]);

        // Same number written two ways is not a conflict (a leading 1 is the same number).
        $this->child(['lan' => '10130', 'first_name' => 'Ana', 'last_name' => 'Same', 'dob' => '2023-01-01', 'mother_name' => 'Rosa Same', 'mother_cell' => '(716) 555-1234', 'nickname' => 'Annie']);
        Person::create(['name' => 'Rosa  same', 'cell' => '1-716-555-1234']);

        $this->child(['lan' => '10140', 'first_name' => 'Ned', 'last_name' => 'Dash', 'dob' => '2023-01-01', 'nickname' => '-', 'father_cell' => '7165550000']);
        $this->child(['lan' => '10150', 'first_name' => 'Nia', 'last_name' => 'Blank', 'dob' => '2023-01-01', 'nickname' => '   ']);

        // The model now saves a placeholder nickname as null (QA #20), so the
        // rows written before that — which is what the report is for — are
        // put in the table directly.
        foreach (['10120' => ' n/a ', '10140' => '-', '10150' => '   '] as $lan => $nickname) {
            DB::table('children')->where('lan', $lan)->update(['nickname' => $nickname]);
        }

        $before = $this->snapshot();

        $output = $this->report();

        foreach ([
            '10110',
            'Sage Lester',
            '6y 5m',
            'room not updated (classrooms:sync)',
            '10106',
            'Ezio Szatkowski',
            'override (age has passed it)',
            '2 child(ren). "override"',
            'Latavia Hedgespeth',
            '716 335 5680',
            '+17163355080',
            '1 name(s) with more than one number.',
            '1 disagreement(s).',
            '3 child(ren). These should be cleared',
            '(ddd) ddd-dddd',
            '+1dddddddddd',
            'dddddddddd',
            'other: ddd ddd dddd',
        ] as $text) {
            $this->assertStringContainsString($text, $output);
        }

        $this->assertStringNotContainsString('Fine Fitting', $output);
        $this->assertStringNotContainsString('Gone Away', $output);
        $this->assertStringNotContainsString('Rosa', $output);

        $this->assertSame($before, $this->snapshot(), 'The report must not write anything.');
    }

    public function test_phone_shapes(): void
    {
        $this->assertSame('(ddd) ddd-dddd', ChildrenDataReport::shape('(716) 335-5080'));
        $this->assertSame('ddd-ddd-dddd', ChildrenDataReport::shape('716-335-5080'));
        $this->assertSame('+1dddddddddd', ChildrenDataReport::shape('+17163355080'));
        $this->assertSame('dddddddddd', ChildrenDataReport::shape('7163355080'));
        $this->assertSame('other: ddd.ddd.dddd', ChildrenDataReport::shape('716.335.5080'));
    }

    public function test_an_empty_database_reports_cleanly(): void
    {
        $output = $this->report();

        $this->assertStringContainsString('0 child(ren). "override"', $output);
        $this->assertStringContainsString('0 name(s) with more than one number.', $output);
    }

    /** Runs the command, checks it exits 0, and hands back everything it printed. */
    private function report(): string
    {
        $this->assertSame(0, Artisan::call('children:data-report'));

        return Artisan::output();
    }

    private function snapshot(): array
    {
        return collect(['children', 'people', 'child_people'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])
            ->all();
    }
}
