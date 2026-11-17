<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One name per page (QA, 16 Nov 2026): the page a menu link opens is titled
 * what the link says. "Director Attendance" opened "Class Attendance" and
 * "Teacher Attendance" opened "Check in".
 */
class PageTitlesMatchMenuTest extends TestCase
{
    use RefreshDatabase;

    private function title(string $html): string
    {
        preg_match('/<title>\s*(.*?)\s*<\/title>/s', $html, $m);

        return html_entity_decode($m[1] ?? '');
    }

    private function h1(string $html): string
    {
        preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $m);

        return trim(html_entity_decode(strip_tags($m[1] ?? '')));
    }

    public function test_each_live_menu_link_opens_a_page_titled_the_same(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
        Child::create(['lan' => '10401', 'first_name' => 'Ava', 'last_name' => 'Cruz', 'status' => 'Active', 'classroom' => 'Toddler']);

        $pages = [
            'Director Attendance' => route('attendance.index'),
            'Teacher Attendance' => route('check-in.index'),
            'Children' => route('children.index'),
            'Staff' => route('staff.index'),
            'Staff attendance' => route('staff.timesheets'),
            'Schedule' => route('staff-schedule.index'),
            'Messages' => route('messages.index'),
            'Reports' => route('reports.index'),
            'Floor plan' => route('floor-plan.index'),
            'Dashboard' => route('dashboard'),
        ];

        foreach ($pages as $label => $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringStartsWith($label, $this->title($html), "$label: <title>");
            $this->assertStringStartsWith($label, $this->h1($html), "$label: <h1>");
        }

        $html = $this->actingAs($admin)->get(route('settings.children'))->assertOk()->getContent();
        $this->assertStringStartsWith('Settings', $this->title($html));
        $this->assertStringStartsWith('Settings', $this->h1($html));
    }

    public function test_a_teacher_sees_children_under_the_children_link_too(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'classrooms' => ['Toddler']]);

        $html = $this->actingAs($teacher)->get(route('children.index'))->assertOk()->getContent();

        $this->assertSame('Children', $this->h1($html));
        $this->assertStringNotContainsString('My Students', $html);
    }

    public function test_a_child_who_is_out_keeps_a_readable_name_in_both_themes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Child::create(['lan' => '10402', 'first_name' => 'Owen', 'last_name' => 'Hart', 'status' => 'Active', 'classroom' => 'Toddler']);

        $html = $this->actingAs($admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString("isIn(card) ? '' : 'text-slate-600 dark:text-slate-300'\" x-text=\"card.display\"", $html);
        $this->assertStringNotContainsString("'text-slate-400 dark:text-slate-500'\" x-text=\"card.display\"", $html);
    }
}
