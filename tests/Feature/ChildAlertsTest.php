<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a room has to know about a child before the day starts.
 *
 * The record already carried a paragraph — "Allergies, medication, court
 * orders" — and it still does, because an allergy needs more said about it
 * than fits on a chip. What the paragraph could not do is be read at a glance
 * down a roll of sixty, so a teacher covering a room they do not usually have
 * found out by opening records one at a time.
 */
class ChildAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    public function test_alerts_are_saved_against_the_child(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->form($child, [
                'alerts' => [
                    ['type' => 'allergy', 'text' => 'Banana'],
                    ['type' => 'court', 'text' => 'No pickup by Jordan Key'],
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [['type' => 'allergy', 'text' => 'Banana'], ['type' => 'court', 'text' => 'No pickup by Jordan Key']],
            $child->fresh()->alerts
        );
    }

    /**
     * A row opened and left empty is not an alert.
     *
     * The form posts whatever rows are on screen, so somebody who presses "add"
     * and changes their mind posts a blank one. A chip reading "Allergy:" with
     * nothing after it says a question was answered when it was not.
     */
    public function test_a_blank_row_is_dropped_rather_than_stored(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->form($child, [
                'alerts' => [
                    ['type' => 'allergy', 'text' => '  '],
                    ['type' => 'medical', 'text' => 'Inhaler in office'],
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([['type' => 'medical', 'text' => 'Inhaler in office']], $child->fresh()->alerts);
    }

    /** Clearing the last alert is an answer, and has to survive being given. */
    public function test_removing_every_alert_empties_the_list(): void
    {
        $child = $this->child(['alerts' => [['type' => 'allergy', 'text' => 'Milk']]]);

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->form($child, ['alerts' => []]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([], $child->fresh()->alerts);
    }

    /** A kind that is not one of ours is drawn as a remark, not dropped. */
    public function test_an_unknown_kind_falls_back_to_a_note(): void
    {
        $child = $this->child(['alerts' => [['type' => 'whatever', 'text' => 'Still worth reading']]]);

        $alerts = $child->alertList();

        $this->assertCount(1, $alerts);
        $this->assertSame('note', $alerts[0]['type']);
        $this->assertSame('Still worth reading', $alerts[0]['text']);
    }

    /** An invented kind cannot be posted, though — that is somebody's typo. */
    public function test_an_unknown_kind_is_refused_from_the_form(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->form($child, [
                'alerts' => [['type' => 'invented', 'text' => 'Something']],
            ]))
            ->assertSessionHasErrors('alerts.0.type');
    }

    public function test_the_roll_shows_each_alert_as_a_chip(): void
    {
        $this->child([
            'alerts' => [
                ['type' => 'court', 'text' => 'No pickup by Jordan Key'],
                ['type' => 'allergy', 'text' => 'Banana'],
            ],
        ]);

        $this->actingAs($this->admin)
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('Court Order: No pickup by Jordan Key')
            ->assertSee('Allergy: Banana')
            // Rose for a rule that must not be broken, amber for a thing to watch.
            ->assertSee('bg-rose-100', false)
            ->assertSee('bg-amber-100', false);
    }

    public function test_the_record_shows_them_above_the_paragraph(): void
    {
        $child = $this->child([
            'alerts' => [['type' => 'medical', 'text' => 'Inhaler in office']],
            'important_notes' => 'Blue inhaler, two puffs, kept in the office cupboard.',
        ]);

        $this->actingAs($this->admin)
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSeeInOrder([
                'Medical: Inhaler in office',
                'Blue inhaler, two puffs',
            ]);
    }

    /** The form offers every kind there is, from the one list. */
    public function test_the_form_offers_the_kinds_the_model_knows(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('children.create'))
            ->assertOk()
            ->getContent();

        foreach (Child::ALERT_TYPES as $value => $type) {
            $this->assertStringContainsString('value="'.$value.'">'.$type['label'], $html);
        }
    }

    /**
     * The empty field offers an example of its own kind.
     *
     * One allergy example on every row had a court order prompting with
     * "Banana — swells, no epi-pen", which teaches the wrong thing about both.
     * The examples live beside the labels they belong to, so a kind cannot be
     * added later with nothing to show for it.
     */
    public function test_each_kind_offers_its_own_example(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('children.create'))
            ->assertOk()
            ->getContent();

        foreach (Child::ALERT_TYPES as $type) {
            $this->assertNotSame('', $type['example'], 'every kind needs an example of its own');
        }

        // Matched the way the page writes them rather than the way they are
        // typed: Blade's @js hands the map to JSON.parse through an HTML
        // attribute, so it is escaped twice and an em dash arrives as \\u2014.
        // Asking for the whole map in that form is one assertion that every
        // example is there and none was mangled on the way.
        $this->assertStringContainsString(
            \Illuminate\Support\Js::from(collect(Child::ALERT_TYPES)->map->example)->toHtml(),
            $html
        );
        // Bound to the row rather than printed once, so changing the kind
        // changes the example under it.
        $this->assertStringContainsString(':placeholder="examples[row.type]"', $html);
    }
    private function child(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '10001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
            'birth_date' => '2023-10-01',
        ]);
    }

    /** The whole form, since an update posts every field the page carries. */
    private function form(Child $child, array $overrides = []): array
    {
        return $overrides + [
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => $child->status,
            'birth_date' => $child->birthDate()?->toDateString(),
        ];
    }
}
