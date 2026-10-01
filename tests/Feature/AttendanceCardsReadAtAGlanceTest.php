<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cards on both attendance pages read at a glance: the faces in colour
 * are the children in the building, with a Checked in pill under the face;
 * the rest are faded until they arrive, and faded again with a grey pill
 * once they have gone for the day.
 *
 * And the Director's register opens on the cards, not the sheet.
 */
class AttendanceCardsReadAtAGlanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_directors_register_opens_on_the_cards(): void
    {
        $html = $this->page('attendance.index');

        $this->assertStringContainsString("mode: 'avatar',", $html);
        // The sheet is still offered, and still remembered once chosen.
        $this->assertStringContainsString("if (localStorage.getItem('attendance.view') === 'sheet') this.mode = 'sheet';", $html);
    }

    public function test_the_directors_cards_fade_until_the_child_is_here_and_say_so_once_they_are(): void
    {
        $html = $this->page('attendance.index');

        $this->assertStringContainsString(":data-presence=\"isHere(child) ? 'in' : (isDone(child) ? 'out' : 'absent')\"", $html);
        $this->assertStringContainsString(":class=\"isHere(child) ? '' : 'opacity-40 grayscale group-hover:opacity-70 group-hover:grayscale-0'\"", $html);
        $this->assertStringContainsString('x-show="isHere(child)" x-cloak class="absolute -bottom-2 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-teal-500', $html);
        $this->assertStringContainsString('>Checked in</span>', $html);
        $this->assertStringContainsString('x-show="isDone(child)" x-cloak class="absolute -bottom-2 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-slate-400', $html);
        $this->assertStringContainsString('>Checked out</span>', $html);
    }

    public function test_the_teachers_cards_read_the_same_way(): void
    {
        $html = $this->page('check-in.index');

        $this->assertStringContainsString(":data-presence=\"isIn(card) ? 'in' : (isOut(card) ? 'out' : 'absent')\"", $html);
        $this->assertStringContainsString(":class=\"isIn(card) ? '' : 'opacity-40 grayscale group-hover:opacity-70 group-hover:grayscale-0'\"", $html);
        $this->assertStringContainsString('x-show="isIn(card)" x-cloak class="absolute -bottom-2 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-teal-500', $html);
        $this->assertStringContainsString('>Checked in</span>', $html);
        $this->assertStringContainsString('x-show="isOut(card)" x-cloak class="absolute -bottom-2 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-slate-400', $html);
        $this->assertStringContainsString('>Checked out</span>', $html);
    }

    private function page(string $route): string
    {
        Child::create([
            'lan' => '3001', 'status' => 'Active', 'first_name' => 'Russ', 'last_name' => 'Johnson',
            'classroom' => 'Toddler', 'birth_date' => '2024-03-02', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        return $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route($route))
            ->assertOk()
            ->getContent();
    }
}
