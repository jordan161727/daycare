<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The month on one page.
 *
 * It replaces a paper form people have read for years, so what these check is
 * that it says the same things in the same places: four lines a child, a
 * column a day headed by weekday and date, the two totals, and the code list.
 */
class MonthSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->child = Child::create([
            'lan' => '10064',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'status' => 'Active',
            'dob' => Carbon::parse('2026-09-23')->subYears(4)->toDateString(),
            'drop_off_time' => '07:00',
            'pick_up_time' => '20:00',
            'schedule_days' => [1, 2, 3, 4, 5],
        ]);
    }

    public function test_the_sheet_prints_four_lines_a_child(): void
    {
        $html = $this->sheet();

        $this->assertStringContainsString('Adkins, Maeve', $html);
        $this->assertStringContainsString('>IN</td>', $html);
        $this->assertStringContainsString('>OUT</td>', $html);
        // In, its check, Out, its check.
        $this->assertSame(2, substr_count($html, '>health</td>'));
    }

    public function test_the_name_block_carries_the_name_room_and_number(): void
    {
        /*
         * Three facts, not four.
         *
         * The contracted hours used to sit here too, as they do on the paper
         * form. They came off: they never change, they are on the child's own
         * record, and a third line down every row of the page was costing the
         * month several days of columns.
         */
        $html = $this->sheet();

        $this->assertStringContainsString('Adkins, Maeve', $html);
        // The room and the number on one line under the name, the way the
        // register writes them.
        $this->assertStringContainsString('· 10064', $html);
        $this->assertStringNotContainsString('7:00 AM – 8:00 PM', $html);
    }

    public function test_every_day_is_headed_by_its_weekday_and_date(): void
    {
        /*
         * The thing that makes a thirty-column grid readable: the day of the
         * week above the date, so a reader running along a row knows which
         * column is Tuesday without counting from the first of the month.
         */
        $html = $this->sheet();

        // 1 September 2026 is a Tuesday, the 2nd a Wednesday.
        $this->assertStringContainsString('>TU</span>', $html);
        $this->assertStringContainsString('>WE</span>', $html);
        $this->assertStringContainsString('>SA</span>', $html);
        $this->assertStringContainsString('>SU</span>', $html);

        // Thirty columns for a thirty-day month, the last of them numbered.
        $this->assertStringContainsString('>30</span>', $html);
    }

    public function test_a_days_times_and_codes_land_in_their_own_column(): void
    {
        $this->attendance('2026-09-09', '08:05', '17:30', 0, 4);

        $html = $this->sheet();

        // The app's own short clock — one letter for the half of the day, so
        // an afternoon pick-up cannot be misread as a morning one and a
        // column a few millimetres wide still holds it.
        $this->assertStringContainsString('8:05a', $html);
        $this->assertStringContainsString('5:30p', $html);
    }

    public function test_a_healthy_code_of_zero_is_printed_rather_than_left_blank(): void
    {
        // The one that a truthiness test would quietly drop, leaving a child
        // who was checked looking like one who never was.
        $this->attendance('2026-09-09', '08:05', '17:30', 0, 0);

        $html = $this->sheet();

        $this->assertStringContainsString('att-chip att-chip-ok">0</span>', $html);
    }

    public function test_a_symptom_reads_differently_from_a_clear_check(): void
    {
        $this->attendance('2026-09-09', '08:05', '17:30', 0, 4);

        $this->assertStringContainsString('att-chip att-chip-sick">4</span>', $this->sheet());
    }

    public function test_the_totals_count_the_children_who_were_in(): void
    {
        $this->attendance('2026-09-09', '08:05', '17:30', 0, 0);

        $other = Child::create([
            'lan' => '10065',
            'first_name' => 'Mark',
            'last_name' => 'Allen',
            'status' => 'Active',
            'dob' => Carbon::parse('2026-09-23')->subYears(4)->toDateString(),
        ]);

        Attendance::create([
            'child_id' => $other->id,
            'attendance_date' => '2026-09-09',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-09 08:20'),
        ]);

        $html = $this->sheet();

        $this->assertStringContainsString('Total kids per day', $html);
        // Two children in on one day and nothing on any other, so the month
        // total is those two child-days.
        $this->assertStringContainsString('Total kids for month:', $html);
        $this->assertMatchesRegularExpression('/Total kids for month:.{0,120}>2</s', $html);
    }

    public function test_the_code_list_is_printed_along_the_foot(): void
    {
        // A sheet handed to somebody who was not there has to explain its own
        // numbers, and the legend panel is a toggle nobody will have open when
        // they press Print.
        $html = $this->sheet();

        $this->assertStringContainsString('Symptom codes:', $html);
        $this->assertStringContainsString('0=normal', $html);
        $this->assertStringContainsString('11=other(specify)', $html);
    }

    public function test_the_sheet_prints_landscape(): void
    {
        $this->assertStringContainsString('size: 11in 8.5in', $this->sheet());
    }

    public function test_an_invented_month_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->get(route('attendance.month-sheet', ['month' => 13, 'year' => 2026]))
            ->assertSessionHasErrors('month');
    }

    public function test_the_sheet_stands_on_its_own_and_leads_back(): void
    {
        /*
         * The register used to carry a Week/Month tab through to here. It was
         * taken out — the month is read on the Check In grid, which draws the
         * same days — so this page is reached by its address and by nothing
         * else at present.
         *
         * What it must still do is lead somewhere: a printable sheet with no
         * way off it is a dead end for whoever lands on it.
         */
        $sheet = $this->sheet();

        $this->assertStringContainsString(route('attendance.index'), $sheet);

        // And the register no longer offers a way here, which is the thing
        // that was removed rather than something that broke.
        $register = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('attendance.month-sheet'), $register);
    }
    public function test_the_sheet_wears_the_apps_own_palette(): void
    {
        /*
         * One app, not two designs of the same thing.
         *
         * The sheet was built to match a paper form and ended up with a
         * palette of its own — its own card, its own chips, its own button
         * sizes. It reads the same records as the register, so it uses the
         * register's own vocabulary: glass cards, pill room chips, and the
         * quietened time box of a day gone by.
         */
        $this->attendance('2026-09-09', '08:05', '17:30', 0, 0);

        $html = $this->sheet();

        // The app's card, not a white slab of its own.
        $this->assertStringContainsString('month-sheet glass-card', $html);

        // A time is printed as a time. A border round every hour would turn
        // thirty days into a wall of outlines.
        $this->assertStringNotContainsString('att-cell att-time', $html);

        // Pills, the shape every other room filter in the app uses.
        $this->assertStringContainsString('rounded-full px-3 py-1 text-xs font-semibold', $html);

        // The legend carries no colour of its own: the chips inside it already
        // say amber, and the panel was saying it twice.
        $this->assertStringNotContainsString('bg-amber-50/60', $html);
    }

    public function test_the_sheet_is_ruled_both_ways(): void
    {
        /*
         * Four lines a child across thirty columns is a great many numbers
         * with nothing between them. The paper form is ruled both ways and the
         * screen needs it for the same reason: an eye running down a column
         * has to be able to tell which row it is on, and an eye running along
         * a row has to be able to tell which day it is under.
         */
        $this->attendance('2026-09-09', '08:05', '17:30', 0, 0);

        $html = $this->sheet();

        // Ruled on every side, not only under the last line of a block.
        $this->assertStringContainsString('border border-slate-200 dark:border-white/10', $html);
        $this->assertStringNotContainsString('border-b border-slate-200 dark:border-white/10', $html);
    }

    private function sheet(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('attendance.month-sheet', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->getContent();
    }

    private function attendance(string $date, string $in, string $out, ?int $inCode, ?int $outCode): Attendance
    {
        return Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => $date,
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse($date.' '.$in),
            'signed_out_at' => Carbon::parse($date.' '.$out),
            'health_in_code' => $inCode,
            'health_out_code' => $outCode,
        ]);
    }
}
