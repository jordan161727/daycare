<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Messages › New message › A family — the family search (QA #24, 12 Nov
 * 2026). A full name typed as it is said finds the child, and the page drops
 * the last search's names the moment the next one is typed.
 */
class MessageFamilySearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Ray Director', 'email' => 'ray@example.com', 'password' => 'password', 'role' => 'admin']);

        $ava = $this->child('Ava', 'Cruz');
        $this->contact($ava, 'Daniel Ortiz');
        $this->child('Ava', 'Bennett');
        $this->child('Noah', 'Cruz');
    }

    public function test_first_and_last_name_together_find_the_child(): void
    {
        $this->assertSame(['Ava Cruz'], $this->search('Ava Cruz'));
        $this->assertSame(['Ava Cruz'], $this->search('  cruz   ava '));
    }

    public function test_one_word_still_matches_either_name(): void
    {
        $this->assertEqualsCanonicalizing(['Ava Bennett', 'Ava Cruz'], $this->search('Ava'));
        $this->assertEqualsCanonicalizing(['Ava Cruz', 'Noah Cruz'], $this->search('Cruz'));
    }

    public function test_a_parents_full_name_finds_their_child(): void
    {
        $this->assertSame(['Ava Cruz'], $this->search('Daniel Ortiz'));
        // A word from the parent and one from the child narrow it together.
        $this->assertSame(['Ava Cruz'], $this->search('Daniel Ava'));
    }

    public function test_no_match_is_an_empty_list_and_no_query_is_everyone(): void
    {
        $this->assertSame([], $this->search('Ava Smith'));
        $this->assertCount(3, $this->search(''));
    }

    public function test_the_page_clears_old_results_while_it_looks_and_drops_late_answers(): void
    {
        $html = $this->actingAs($this->admin)->get(route('messages.index'))->assertOk()->getContent();

        $this->assertStringContainsString('@input="famTyped()"', $html);
        $this->assertStringNotContainsString('@input.debounce.250ms="searchFamilies()"', $html);
        $this->assertStringContainsString('data-fam-searching', $html);
        $this->assertStringContainsString('if (seq !== this.famSeq) return;', $html);
    }

    /** @return list<string> */
    private function search(string $q): array
    {
        return collect($this->actingAs($this->admin)->getJson(route('messages.search', ['q' => $q]))->assertOk()->json('children'))
            ->pluck('child')->all();
    }

    private function child(string $first, string $last): Child
    {
        static $lan = 2000;

        return Child::create(['lan' => (string) ++$lan, 'first_name' => $first, 'last_name' => $last, 'status' => 'Active', 'classroom' => 'Toddler', 'classroom_override' => 'Toddler']);
    }

    private function contact(Child $child, string $name): void
    {
        $person = Person::create(['name' => $name, 'cell' => '(716) 555-0101']);
        $child->personLinks()->create(['person_id' => $person->id, 'relationship' => 'Father', 'gets_texts' => true]);
    }
}
