<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * php artisan data:cleanup-2026-11: a dry run writes nothing; a run makes
 * only the default changes, each in data_cleanup_log; --also adds a held
 * change; --undo puts every field back; nothing is deleted.
 */
class DataCleanup202611Test extends TestCase
{
    use RefreshDatabase;

    private const JORDAN_KEY = ['text' => 'No pickup by Jordan Key', 'type' => 'court', 'severity' => 'severe', 'reaction' => null, 'action' => 'Call the office at once if he arrives; order on file', 'plan_on' => '2026-08-26'];

    private const PEANUTS = ['text' => 'Peanuts — all tree nuts', 'type' => 'allergy', 'severity' => 'severe', 'reaction' => 'Swelling', 'action' => 'EpiPen in the office cabinet; call 911 then mom', 'plan_on' => '2026-08-28'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00', config('app.timezone')));
    }

    public function test_dry_run_writes_nothing_run_logs_every_change_and_undo_restores(): void
    {
        $w = $this->world();
        $snapshot = fn () => [
            DB::table('children')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('users')->orderBy('id')->get(['id', 'name', 'employment'])->map(fn ($r) => (array) $r)->all(),
            DB::table('messages')->orderBy('id')->get(['id', 'archived_at'])->map(fn ($r) => (array) $r)->all(),
        ];
        $before = $snapshot();

        // Dry run: the plan, and nothing written.
        $this->artisan('data:cleanup-2026-11', ['--dry-run' => true])
            ->expectsOutputToContain('LAN 10013 Pharaoh Lumpkins [children #'.$w['pharaoh']->id.'] status: "Active" → "Inactive"')
            ->expectsOutputToContain('alerts: ["No pickup by Jordan Key"] → null')
            ->expectsOutputToContain('alerts: ["Excema"] → ["Eczema"]')
            ->expectsOutputToContain('"zxczxcxz" — no content.')
            ->expectsOutputToContain('name: "rona" → "Rona"')
            ->expectsOutputToContain('employment: "LEAD" → "FT"')
            ->expectsOutputToContain('NOT set inactive')
            ->expectsOutputToContain('two people. Badge initials are now BJ and BJ2')
            ->expectsOutputToContain('History is NOT moved')
            ->expectsOutputToContain('DRY RUN — nothing was changed.')
            ->assertSuccessful();
        $this->assertSame($before, $snapshot());
        $this->assertSame(0, DB::table('data_cleanup_log')->count());

        // The run: the default changes only.
        $this->artisan('data:cleanup-2026-11')->expectsOutputToContain('change(s) written. Batch: cleanup-2026-11-')->assertSuccessful();

        $this->assertSame('Inactive', $w['pharaoh']->fresh()->status);
        $this->assertSame('2026-11-06', $w['pharaoh']->fresh()->withdrawn_on->toDateString(), 'dates kept');
        $this->assertNull($w['keyOnly']->fresh()->alerts);
        $this->assertSame(['Peanuts — all tree nuts'], collect($w['keyAndPeanuts']->fresh()->alerts)->pluck('text')->all(), 'only the Jordan Key alert goes by default');
        $this->assertSame('Eczema', $w['excema']->fresh()->alerts[0]['text']);
        $this->assertSame(['UPK-4', null], [$w['kasey']->fresh()->classroom, $w['kasey']->fresh()->classroom_override]);
        $this->assertSame('Infant', $w['sanford']->fresh()->classroom, 'held until the DOB is checked');
        $this->assertSame(['Active', 'Active', 'Active'], Child::whereIn('id', collect($w['jace'])->pluck('id'))->pluck('status')->all(), 'Jace Millers with history are not archived by default');
        $this->assertSame(['Rona', 'FT'], [User::find($w['rona']->id)->name, User::find($w['lead']->id)->employment]);
        $this->assertSame('Jordan', User::find($w['lead']->id)->name);
        $this->assertSame('INACTIVE', User::find($w['emptyPlaceholder']->id)->employment, 'a placeholder with no history');
        $this->assertSame('FT', User::find($w['usedPlaceholder']->id)->employment, 'a placeholder with punches is listed, not changed');
        $this->assertSame(['hi'], Message::pluck('body')->all(), 'test messages archived, greetings held');
        $this->assertSame(3, Message::onlyTrashed()->count());
        $this->assertSame(4, DB::table('messages')->count(), 'archived, not deleted');
        $this->assertSame(9, Child::count(), 'no child deleted');

        $batch = DB::table('data_cleanup_log')->value('batch');
        $this->assertGreaterThan(10, DB::table('data_cleanup_log')->where('batch', $batch)->count());

        // Undo: every field back as it was.
        $this->artisan('data:cleanup-2026-11', ['--undo' => $batch])->expectsOutputToContain('change(s) undone')->assertSuccessful();
        $this->assertSame($before, $snapshot());
        $this->assertSame(0, DB::table('data_cleanup_log')->whereNull('undone_at')->count());
    }

    public function test_held_changes_need_also_and_an_unknown_option_is_refused(): void
    {
        $w = $this->world();

        $this->artisan('data:cleanup-2026-11', ['--also' => ['nonsense']])->assertFailed();

        $this->artisan('data:cleanup-2026-11', ['--also' => ['jace-archive-others,seeded-alerts,10023-room,greetings,beth-names']])->assertSuccessful();

        // Jace: the one with the most history kept, the others archived — not deleted.
        $statuses = Child::whereIn('id', collect($w['jace'])->pluck('id'))->orderBy('id')->pluck('status', 'lan')->all();
        $this->assertSame(['10102' => 'Active', '10104' => 'Archived', '10106' => 'Archived'], $statuses);
        $this->assertSame('Active', Child::where('lan', '10104')->value('status_before_archive'));
        $this->assertNull($w['keyAndPeanuts']->fresh()->alerts);
        $this->assertSame('UPK-4', $w['sanford']->fresh()->classroom);
        $this->assertSame(0, Message::count());
        $this->assertSame(['Beth Jones (Infant)', 'Beth Jones (PreK)'], User::where('name', 'like', 'Beth Jones%')->orderBy('id')->pluck('name')->all());
    }

    public function test_a_run_refuses_a_field_changed_since_the_plan_and_undo_skips_one_changed_since(): void
    {
        $w = $this->world();
        $this->artisan('data:cleanup-2026-11')->assertSuccessful();
        $batch = DB::table('data_cleanup_log')->value('batch');

        // Somebody renamed Rona again after the cleanup: undo leaves that alone.
        User::whereKey($w['rona']->id)->update(['name' => 'Rona Smith']);
        $this->artisan('data:cleanup-2026-11', ['--undo' => $batch])->expectsOutputToContain('Skipped users #'.$w['rona']->id.' name')->assertFailed();
        $this->assertSame('Rona Smith', User::find($w['rona']->id)->name);
        $this->assertSame('Active', $w['pharaoh']->fresh()->status, 'the rest undone');
    }

    private function world(): array
    {
        $child = fn (string $lan, string $first, string $last, array $extra = []) => Child::create($extra + [
            'lan' => $lan, 'first_name' => $first, 'last_name' => $last, 'status' => 'Active', 'dob' => '2022-01-01',
        ]);

        $w['pharaoh'] = $child('10013', 'Pharaoh', 'Lumpkins', ['classroom_override' => 'PreK', 'dob' => '2023-10-22', 'enrolled_on' => '2026-04-20', 'withdrawn_on' => '2026-11-06', 'withdrawal_reason' => 'Changed provider']);
        $w['keyOnly'] = $child('10014', 'Mika', 'Mac', ['alerts' => [self::JORDAN_KEY]]);
        $w['keyAndPeanuts'] = $child('10015', 'Ez', 'Mc', ['alerts' => [self::JORDAN_KEY, self::PEANUTS]]);
        $w['excema'] = $child('10029', 'Hazel', 'Whit', ['alerts' => [['text' => 'Excema', 'type' => 'allergy', 'severity' => 'mild', 'reaction' => null, 'action' => 'Avoid', 'plan_on' => '2026-06-26']]]);
        $w['kasey'] = $child('10004', 'Kasey', 'Bolton', ['dob' => '2021-11-01', 'classroom_override' => 'PreK']);
        $w['sanford'] = $child('10023', 'Brinton', 'Sanford', ['dob' => '2021-10-10', 'classroom_override' => 'Infant']);
        foreach (['10102' => 3, '10104' => 2, '10106' => 1] as $lan => $days) {
            $jace = $child($lan, 'Jace', 'Miller', ['dob' => '2020-01-07', 'birth_date' => '2020-01-07']);
            foreach (range(1, $days) as $d) {
                DB::table('attendances')->insert(['child_id' => $jace->id, 'attendance_date' => "2026-11-0{$d}", 'session' => 'FULL', 'signed_in_at' => "2026-11-0{$d} 08:00:00", 'created_at' => now(), 'updated_at' => now()]);
            }
            $w['jace'][] = $jace;
        }

        $w['rona'] = User::factory()->create(['name' => 'rona', 'role' => 'teacher', 'employment' => 'FT']);
        $w['lead'] = User::factory()->create(['name' => 'jordan', 'role' => 'teacher', 'job_role' => 'Director', 'employment' => 'LEAD']);
        $w['emptyPlaceholder'] = User::factory()->create(['name' => 'Toddler Teacher', 'role' => 'teacher', 'employment' => 'FT']);
        $w['usedPlaceholder'] = User::factory()->create(['name' => 'Infant Teacher', 'role' => 'teacher', 'employment' => 'FT']);
        DB::table('time_punches')->insert(['user_id' => $w['usedPlaceholder']->id, 'work_date' => '2026-11-02', 'punched_at' => '2026-11-02 08:00:00', 'type' => 'in', 'created_at' => now(), 'updated_at' => now()]);
        User::factory()->create(['name' => 'Beth Jones', 'role' => 'teacher', 'email' => 'beth-a@example.test', 'phone' => '1', 'start_date' => '2022-07-28', 'classroom' => 'Infant']);
        User::factory()->create(['name' => 'Beth Jones', 'role' => 'teacher', 'email' => 'beth-b@example.test', 'phone' => '2', 'start_date' => '2023-08-28', 'classroom' => 'PreK']);

        $thread = DB::table('message_threads')->insertGetId(['kind' => 'office', 'child_id' => $w['keyOnly']->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['zxczxcxz', 'test', 'Test', 'hi'] as $body) {
            DB::table('messages')->insert(['thread_id' => $thread, 'direction' => 'out', 'channel' => 'sms', 'body' => $body, 'user_id' => $w['rona']->id, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $w;
    }
}
