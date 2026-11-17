<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * QA, Nov 2026: Jace / Miller / 01/07/2020 typed into Add child gave no
 * warning though five Jace Millers were on file. The form now checks as it
 * is typed (children.duplicate-check), and the save's own check is no longer
 * hidden behind the "Not ready to be Active" refusal.
 */
class ChildDuplicateCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    /** Five Jace Millers born 01/07/2020, one in every status, birthday in either column. */
    private function fiveJaces(): array
    {
        return [
            Child::create(['lan' => '10102', 'first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Active', 'birth_date' => '2020-01-07', 'dob' => '2020-01-07']),
            Child::create(['lan' => '10103', 'first_name' => 'JACE ', 'last_name' => 'miller', 'status' => 'Pending', 'birth_date' => '2020-01-07']),
            Child::create(['lan' => '10104', 'first_name' => 'Jace', 'last_name' => 'Mil ler', 'status' => 'Inactive', 'dob' => '2020-01-07']),
            Child::create(['lan' => '10105', 'first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Archived', 'birth_date' => '2020-01-07', 'dob' => '2020-01-07']),
            // No birthday on file: nothing says this is a different child.
            Child::create(['lan' => '10106', 'first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Pending']),
        ];
    }

    public function test_the_endpoint_lists_every_match_in_every_status(): void
    {
        $jaces = $this->fiveJaces();
        Child::create(['lan' => '10107', 'first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Active', 'birth_date' => '2021-03-03']);
        Child::create(['lan' => '10108', 'first_name' => 'Jake', 'last_name' => 'Miller', 'status' => 'Active', 'birth_date' => '2020-01-07']);

        $response = $this->actingAs($this->admin)
            ->getJson(route('children.duplicate-check', ['first_name' => ' jace', 'last_name' => 'MILLER', 'birth_date' => '01/07/2020']))
            ->assertOk()
            ->assertJsonPath('count', 5)
            ->assertJsonCount(5, 'matches')
            ->assertJsonPath('matches.0', [
                'id' => $jaces[0]->id,
                'lan' => '10102',
                'name' => 'Jace Miller',
                'status' => 'Active',
                'room' => $jaces[0]->classroom ?: null,
                'born' => '01/07/2020',
                'url' => route('children.show', $jaces[0]),
            ]);

        $this->assertSame(['10102', '10103', '10104', '10105', '10106'], array_column($response->json('matches'), 'lan'));
        $this->assertSame(['Active', 'Pending', Child::statusLabel('Inactive'), Child::statusLabel('Archived'), 'Pending'], array_column($response->json('matches'), 'status'));

        // Y-m-d reads the same.
        $this->actingAs($this->admin)->getJson(route('children.duplicate-check', ['first_name' => 'Jace', 'last_name' => 'Miller', 'birth_date' => '2020-01-07']))
            ->assertJsonPath('count', 5);
    }

    public function test_the_endpoint_leaves_out_the_record_being_edited_and_caps_the_list(): void
    {
        $jaces = $this->fiveJaces();
        Child::create(['lan' => '10109', 'first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Active', 'birth_date' => '2020-01-07']);

        $response = $this->actingAs($this->admin)
            ->getJson(route('children.duplicate-check', ['first_name' => 'Jace', 'last_name' => 'Miller', 'birth_date' => '01/07/2020', 'except' => $jaces[0]->id]))
            ->assertJsonPath('count', 5)
            ->assertJsonCount(5, 'matches');
        $this->assertNotContains($jaces[0]->id, array_column($response->json('matches'), 'id'));

        $this->actingAs($this->admin)->getJson(route('children.duplicate-check', ['first_name' => 'Jace', 'last_name' => 'Miller', 'birth_date' => '01/07/2020']))
            ->assertJsonPath('count', 6)
            ->assertJsonCount(5, 'matches');
    }

    public function test_the_endpoint_needs_a_name_and_a_whole_date(): void
    {
        $this->fiveJaces();

        foreach ([
            ['first_name' => '', 'last_name' => 'Miller', 'birth_date' => '01/07/2020'],
            ['first_name' => 'Jace', 'last_name' => '  ', 'birth_date' => '01/07/2020'],
            ['first_name' => 'Jace', 'last_name' => 'Miller', 'birth_date' => '13/45/2020'],
            ['first_name' => 'Jace', 'last_name' => 'Miller', 'birth_date' => '01/07/20x'],
            ['first_name' => 'Ava', 'last_name' => 'Miller', 'birth_date' => '01/07/2020'],
        ] as $query) {
            $this->actingAs($this->admin)->getJson(route('children.duplicate-check', $query))
                ->assertOk()->assertExactJson(['count' => 0, 'matches' => []]);
        }
    }

    public function test_the_endpoint_is_the_directors_like_add_child(): void
    {
        $this->fiveJaces();
        $query = ['first_name' => 'Jace', 'last_name' => 'Miller', 'birth_date' => '01/07/2020'];

        $this->getJson(route('children.duplicate-check', $query))->assertUnauthorized();

        $teacher = User::factory()->create(['role' => 'teacher', 'must_change_password' => false]);
        $this->actingAs($teacher)->get(route('children.create'))->assertForbidden();
        $this->actingAs($teacher)->getJson(route('children.duplicate-check', $query))->assertForbidden();
    }

    public function test_add_child_as_active_with_missing_fields_still_says_it_is_a_duplicate(): void
    {
        $jaces = $this->fiveJaces();

        // Active, no guardian and no emergency contact: both refusals together.
        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Active', 'birth_date' => '01/07/2020'])
            ->assertSessionHasErrors(['duplicate', 'activation', 'mother_name', 'emergency_contact'])
            ->assertSessionHas('duplicate_of', $jaces[0]->id);
        $this->assertStringContainsString('LAN 10102', session('errors')->first('duplicate'));
        $this->assertStringEndsWith('Open that record, or press "Continue anyway" if this is a different child.', session('errors')->first('duplicate'));
        $this->assertSame(5, Child::count());

        // And alongside an ordinary field error too.
        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Pending', 'birth_date' => '01/07/2020', 'gender' => 'nonsense'])
            ->assertSessionHasErrors(['duplicate', 'gender']);

        // The page comes back with the banner and Continue anyway.
        $this->actingAs($this->admin)->get(route('children.create'))
            ->assertSee('data-duplicate', false)
            ->assertSee('Continue anyway', false)
            ->assertSee('href="'.route('children.show', $jaces[0]).'"', false);

        // Continue anyway: only the Active refusal is left.
        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Active', 'birth_date' => '01/07/2020', 'confirm_duplicate' => '1'])
            ->assertSessionHasErrors('activation')
            ->assertSessionDoesntHaveErrors('duplicate');
        $this->assertSame(5, Child::count());

        $this->actingAs($this->admin)->post(route('children.store'), ['first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Pending', 'birth_date' => '01/07/2020', 'confirm_duplicate' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(6, Child::count());
    }

    public function test_an_edit_and_the_import_still_warn(): void
    {
        $jaces = $this->fiveJaces();
        $jake = Child::create(['lan' => '10200', 'first_name' => 'Jake', 'last_name' => 'Miller', 'status' => 'Pending', 'birth_date' => '2020-01-07']);

        $this->actingAs($this->admin)->put(route('children.update', $jake), ['first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Pending', 'birth_date' => '01/07/2020'])
            ->assertSessionHasErrors('duplicate')
            ->assertSessionHas('duplicate_of', $jaces[0]->id);
        $this->assertSame('Jake', $jake->fresh()->first_name);

        // The edit form carries the live check, leaving itself out.
        $this->actingAs($this->admin)->get(route('children.edit', $jake))
            ->assertOk()
            ->assertSee('data-duplicate-live', false)
            ->assertSee('children\/duplicate-check', false)
            ->assertSee('dupExcept: '.$jake->id, false);

        // An archived child on file holds an imported row back too.
        $csv = UploadedFile::fake()->createWithContent('children.csv', "LAN,First Name,Last Name,Date of Birth\n20001,jace,MILLER,2020-01-07\n");
        Child::whereKeyNot($jaces[3]->id)->whereKeyNot($jake->id)->delete();
        $this->actingAs($this->admin)->post(route('children.import'), ['file' => $csv])
            ->assertSessionHas('import_duplicates', fn (array $rows) => count($rows) === 1 && str_contains($rows[0], 'LAN 10105'));
        $this->assertDatabaseMissing('children', ['lan' => '20001']);
    }

    public function test_typing_a_child_already_on_file_shows_the_warning(): void
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json')) || ! is_file(base_path('node_modules/alpinejs/dist/cdn.js'))) {
            $this->markTestSkipped('node, jsdom and Alpine are needed to type into the page.');
        }

        $this->travelTo(Carbon::parse('2026-11-17 10:00:00'));

        $address = route('children.create');
        $html = $this->actingAs($this->admin)->get($address)->assertOk()->getContent();
        $page = tempnam(sys_get_temp_dir(), 'addchild').'.html';
        file_put_contents($page, $html);

        $process = new Process([$node, base_path('tests/js/duplicate-check.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js'), $address], base_path(), ['NODE_PATH' => base_path('node_modules')]);
        $process->setTimeout(60)->run();
        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The check script failed:\n".$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, 'No JSON: '.$process->getOutput());
        $this->assertSame([], $result['errors']);

        $this->assertFalse($result['shownAtFirst']);
        $this->assertSame(0, $result['fetchesBeforeWholeDate'], 'half a date is not checked');
        $this->assertFalse($result['shownBeforeWholeDate']);
        $this->assertSame(0, $result['fetchesTooSoon'], 'the check waits for typing to stop');
        $this->assertCount(1, $result['fetches']);
        $this->assertStringContainsString('first_name=Jace', $result['fetches'][0]);
        $this->assertStringContainsString('last_name=Miller', $result['fetches'][0]);
        $this->assertStringContainsString('birth_date=01%2F07%2F2020', $result['fetches'][0]);
        $this->assertTrue($result['shown']);
        $this->assertSame('A child named Jace Miller born 01/07/2020 already exists (LAN 10102) and 4 more.', $result['text']);
        $this->assertSame(['/children/1', '/children/2'], $result['links']);
        $this->assertTrue($result['heldWithoutConfirm'], 'saving past the warning needs Continue anyway');
        $this->assertSame('1', $result['confirmPosts']);
        $this->assertFalse($result['heldWithConfirm']);
    }

    private function node(): ?string
    {
        foreach (['node', 'node.exe'] as $candidate) {
            $which = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', $candidate] : ['which', $candidate]);
            $which->run();

            if ($which->getExitCode() === 0) {
                return trim(strtok($which->getOutput(), "\r\n")) ?: $candidate;
            }
        }

        return null;
    }
}
