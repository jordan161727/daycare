<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A box's class follows its state.
 *
 * Reported from the floor: "I went to edit next week, clicked on a child, and
 * it created a dot and left the dotted lines around the dot." The tap had
 * worked — the plan was saved, the contents redrew as a dot — but the box kept
 * the dashed outline of "expected".
 *
 * The cause is how Alpine treats an x-bind object: it is applied once at
 * mount, each key frozen into a static literal, and never evaluated again.
 * x-html is live. So a class that lived in the object was stuck at whatever
 * it was when the cell was first drawn. The class, the title and the label
 * all change with the cell, so they must be bound on their own.
 *
 * Nothing here runs a browser. What can be pinned from the rendered page is
 * the shape of the binding — that the live attributes are real directives and
 * that the object no longer carries them — and that is enough to stop the
 * regression from being reintroduced by somebody tidying the partial back to
 * "two bindings".
 */
class CellClassIsLiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);

        Child::create([
            'lan' => '2001',
            'status' => 'Active',
            'first_name' => 'Amaan',
            'last_name' => 'Bin Alam',
            'classroom' => 'Toddler',
            'birth_date' => '2024-02-10',
            'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        app(WeekSchedule::class)->open('2026-09-21');
    }

    public function test_the_class_title_and_label_are_bound_live(): void
    {
        $html = $this->sheet();

        // Real directives, which Alpine re-runs on every change and, for the
        // class, undoes before re-applying.
        $this->assertStringContainsString(':class="cellClass(child.id, ', $html);
        $this->assertStringContainsString(':title="boxTitle(child.id, ', $html);
        $this->assertStringContainsString(':aria-label="cellLabel(child, ', $html);

        // Beside the object, not instead of it: the static identity stays.
        $this->assertStringContainsString('x-bind="cellAttrs(child, ', $html);
    }

    public function test_the_static_object_carries_nothing_that_changes(): void
    {
        $html = $this->sheet();

        // The body of cellAttrs(), as rendered. If any of these come back it
        // means the class has gone back into the object, and the dotted box
        // round the dot comes back with it.
        $this->assertStringNotContainsString("'class': this.cellClass(", $html);
        $this->assertStringNotContainsString("'title': this.boxTitle(", $html);
        $this->assertStringNotContainsString("'aria-label': this.cellLabel(", $html);

        // What is allowed to be frozen: who the box is, and whether it can be
        // tapped at all — a mode switch reloads the sheet, so that is stable
        // for the life of the element.
        $this->assertStringContainsString("'data-cell': this.cellKey(", $html);
        $this->assertStringContainsString("'role': tappable ? 'button' : null,", $html);
    }

    private function sheet(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('attendance.index', ['mode' => 'edit']))
            ->assertOk()
            ->getContent();
    }
}
