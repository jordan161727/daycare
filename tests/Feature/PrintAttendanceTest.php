<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The week on paper: one landscape Letter page, every room, the schedule
 * printed into the boxes and nothing else. Staff fill a box when a child
 * arrives; the sheet never arrives pre-filled.
 */
class PrintAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-07';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_the_attendance_page_offers_the_print_once_the_week_exists(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        // This week always exists, so the button is there.
        $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSee('Print attendance')
            ->assertSee(route('attendance.print', ['date' => self::MONDAY]), false);

        // Next week has not been opened: nothing to print, so nothing offered.
        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => '2026-09-14']))
            ->assertOk()
            ->assertDontSee('Print attendance');
    }

    public function test_the_sheet_prints_the_schedule_as_shading_and_leaves_every_box_white(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $alan = $this->makeChild('Turing', 'Alan', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        // Ada every day, Alan not at all: one row of white boxes, one of grey.
        ScheduleSlot::where('child_id', $ada->id)->update(['is_scheduled' => true]);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Toddler · 2', $html);
        $this->assertStringContainsString('Lovelace, Ada', $html);
        $this->assertStringContainsString('Turing, Alan', $html);

        // Alan's five boxes are shaded; the interior of every one is still a
        // white box, because a drop-in is marked by filling it. Counted from
        // the grid down, since the key above it draws one of each as a sample.
        $grid = substr($html, strpos($html, '<table class="cols">'));
        $this->assertSame(5, substr_count($grid, '<span class="slot ns"><span class="bx ns"></span></span>'));
        $this->assertSame(5, substr_count($grid, '<span class="slot"><span class="bx"></span></span>'));

        // The only filled boxes on the page are the two in the key. Nothing on
        // the sheet is drawn from a sign-in, so nothing arrives already filled.
        $this->assertSame(2, substr_count($html, 'style="background:var(--accent)'));
        $this->assertSame(0, substr_count($grid, 'style="background:var(--accent)'));

        // One expected each day, and a box to verify each column against the room.
        $this->assertSame(5, substr_count($html, '<td class="ct">1</td>'));
        $this->assertStringContainsString('verify ✓', $html);
        $this->assertSame(5, substr_count($html, '<span class="vbx"></span>'));
        $this->assertMatchesRegularExpression('/Mon <b>1<\/b>\s*·\s*Tue <b>1<\/b>\s*·\s*Wed <b>1<\/b>\s*·\s*Thu <b>1<\/b>\s*·\s*Fri <b>1<\/b>/', $html);
    }

    public function test_a_closed_day_is_a_grey_column_named_in_the_heading(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        ClosureDay::create(['closed_on' => self::MONDAY, 'reason' => 'Labour Day']);
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('child_id', $ada->id)->update(['is_scheduled' => true]);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Mon = Labour Day (closed)', $html);
        $this->assertStringContainsString('<th class="d closed">Mon<span class="dt">closed</span></th>', $html);
        // The child's cell, the count and the verify box all go grey with it —
        // and the closed day drops out of the centre totals in the footer.
        $this->assertSame(2, substr_count($html, '<td class="cl">–</td>'));
        $this->assertStringContainsString('<td class="cl"></td>', $html);
        $this->assertStringNotContainsString('Mon <b>', $html);
        $this->assertStringContainsString('Tue <b>1</b>', $html);
    }

    public function test_school_age_gets_a_morning_box_and_an_afternoon_box(): void
    {
        $kane = $this->makeChild('Kruger', 'Kane', 'School Age');
        app(WeekSchedule::class)->open(self::MONDAY);

        // Mornings only.
        ScheduleSlot::where('child_id', $kane->id)->where('session', 'AM')->update(['is_scheduled' => true]);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('School Age · 1 · AM+PM', $html);
        $this->assertSame(5, substr_count($html, '<span class="ap">AM PM</span>'));
        // White morning, grey afternoon, five times over.
        $this->assertSame(5, substr_count($html, '<span class="pair"><span class="slot am"><span class="bx"></span></span><span class="slot am ns"><span class="bx ns"></span></span></span>'));
        // The count says both halves of the day, and the column is flagged wide
        // so the pair has room.
        $this->assertStringContainsString('sched AM/PM', $html);
        $this->assertSame(5, substr_count($html, '<td class="ct">1<span class="sl">/</span>0</td>'));
        $this->assertStringContainsString('<td class="wide">', $html);
    }

    public function test_a_room_with_nothing_ticked_moves_to_the_footer(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->makeChild('Hopper', 'Grace', 'Infant');
        $this->makeChild('Babbage', 'Charles', 'Infant');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('child_id', $ada->id)->update(['is_scheduled' => true]);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // Two children, no ticks: a block of grey the size of a room, so it
        // is accounted for by name at the bottom rather than drawn.
        $this->assertStringNotContainsString('Infant · 2', $html);
        $this->assertStringNotContainsString('Hopper, Grace', $html);
        $this->assertStringContainsString('Infant (2)', $html);
        $this->assertStringContainsString('not scheduled this week', $html);
    }

    public function test_a_child_not_enrolled_this_week_is_not_a_row(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        // Starts next month, so this week builds no slots for them.
        $this->makeChild('Turing', 'Alan', 'Toddler', ['enrolled_on' => '2026-10-05']);
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::query()->update(['is_scheduled' => true]);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Toddler · 1', $html);
        $this->assertStringNotContainsString('Turing, Alan', $html);
    }

    public function test_a_teacher_prints_only_their_own_room(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->makeChild('Hopper', 'Grace', 'Infant');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::query()->update(['is_scheduled' => true]);

        $html = $this->actingAs(User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']))
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Lovelace, Ada', $html);
        $this->assertStringNotContainsString('Hopper, Grace', $html);
        $this->assertStringNotContainsString('Infant', $html);
    }

    public function test_printing_never_builds_a_week(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        // Next week does not exist. Looking at its printout must not create it.
        $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => '2026-09-14']))
            ->assertOk()
            ->assertSee('Nothing is scheduled for this week yet');

        $this->assertNull(ScheduleWeek::firstWhere('week_start', '2026-09-14'));
    }

    public function test_the_sheet_is_landscape_letter_and_forces_its_greys_to_print(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('@page{size:11in 8.5in;margin:0.3in;}', $html);
        // The grey is the information: a browser that saved ink by dropping
        // backgrounds would print every child as scheduled every day.
        $this->assertStringContainsString('print-color-adjust:exact', $html);
        $this->assertStringContainsString(config('daycare.company.name').' — weekly attendance', $html);

        // Black and white, out of one block of variables rather than a scatter
        // of hexes — which is what let this page go from sky to grey without a
        // line of its markup changing.
        $this->assertStringContainsString('--accent:#1b1b1b;', $html);
        $this->assertStringContainsString('--shade:#d9d9d9;', $html);
        $this->assertStringNotContainsString('#0284c7', $html);

        // The letterhead too: the one coloured thing on a monochrome page would
        // be the logo.
        $this->assertStringContainsString('filter:grayscale(1)', $html);

        // And it is sized on the tag, so it cannot push the header down
        // mid-print — this page opens the dialog the moment it loads.
        $this->assertMatchesRegularExpression('/<img src="[^"]*littleangels-logo\.png"[^>]*width="531" height="228"/', $html);
        $this->assertStringContainsString('Week of Sep 7–11, 2026', $html);
    }

    /**
     * A month is one page, not five weekly ones stapled together.
     *
     * Printing it as five weekly sheets was the obvious thing and the wrong
     * one: a month of one child came out as five pages each carrying one row.
     * Every weekday of the month goes across a single page instead.
     */
    public function test_the_whole_month_is_one_page_of_every_weekday(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::query()->update(['is_scheduled' => true]);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY, 'range' => 'month']))
            ->assertOk();

        // September 2026 has 22 weekdays, and only this month's: a column for a
        // day in another month is one nobody can sign for here.
        $days = $response->viewData('days');

        $this->assertCount(22, $days);
        $this->assertSame('2026-09-01', $days->first()->toDateString());
        $this->assertSame('2026-09-30', $days->last()->toDateString());

        // One sheet, so nothing breaks onto another page.
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<div class="sheet">'));
        $this->assertStringNotContainsString('break-after:page', $html);
        $this->assertStringContainsString(config('daycare.company.name').' — September 2026', $html);
    }

    /**
     * Twenty-two columns is a lot to count along, so each one sits under the
     * week it belongs to.
     */
    public function test_the_days_are_banded_by_the_week_they_belong_to(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::query()->update(['is_scheduled' => true]);

        $weeks = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY, 'range' => 'month']))
            ->assertOk()
            ->viewData('weeks');

        // Five bands. The first is short — September 2026 opens on a Tuesday —
        // and the band says so rather than the page pretending otherwise.
        $this->assertCount(5, $weeks);
        $this->assertSame(4, $weeks->first()->count());
        $this->assertSame('2026-08-31', $weeks->keys()->first());
    }

    /**
     * A room signed in by half day gets two rows a child, not two boxes a cell.
     * Forty-four boxes across a page would be too narrow to write in.
     */
    public function test_a_split_room_takes_two_rows_a_child_on_the_month_sheet(): void
    {
        $kane = $this->makeChild('Kruger', 'Kane', 'School Age');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::query()->update(['is_scheduled' => true]);

        $rooms = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY, 'range' => 'month']))
            ->assertOk()
            ->viewData('rooms');

        $schoolAge = collect($rooms)->firstWhere('name', 'School Age');

        $this->assertTrue($schoolAge['split']);
        $this->assertSame(1, $schoolAge['members']);
        $this->assertCount(2, $schoolAge['rows']);

        // The name is written once; the second row carries the half alone, so a
        // pair reads as one child rather than as two.
        $this->assertSame(['AM', 'PM'], array_column($schoolAge['rows'], 'session'));
        $this->assertFalse($schoolAge['rows'][0]['repeat']);
        $this->assertTrue($schoolAge['rows'][1]['repeat']);
    }

    public function test_a_closed_day_and_a_day_off_the_roll_both_read_as_a_dash(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler', ['enrolled_on' => '2026-09-10']);
        ClosureDay::create(['closed_on' => '2026-09-15', 'reason' => 'Snow']);
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::query()->update(['is_scheduled' => true]);

        $rooms = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY, 'range' => 'month']))
            ->assertOk()
            ->viewData('rooms');

        $cells = collect($rooms)->firstWhere('name', 'Toddler')['rows'][0]['cells'];

        // Nobody could have come either way, and the sheet says so the same way.
        $this->assertSame('out', $cells['2026-09-01']);
        $this->assertSame('closed', $cells['2026-09-15']);
        $this->assertSame('on', $cells['2026-09-10']);
    }
    /**
     * The month sheet arrives filled in, and the weekly one never does.
     *
     * The clipboard by the door must be blank — a box printed already filled is
     * one nobody could fill and one nobody could correct. Nobody fills the
     * month sheet in: it is what the month is reconciled from, and a
     * reconciliation that omits what happened is a form you would have to sit
     * beside the screen to use.
     */
    public function test_the_month_sheet_shows_who_actually_came(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::query()->update(['is_scheduled' => true]);

        // Expected and came on the Monday; expected and did not on the Tuesday.
        Attendance::create([
            'child_id' => $ada->id,
            'attendance_date' => self::MONDAY,
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse(self::MONDAY.' 08:12:00'),
        ]);

        $cells = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY, 'range' => 'month']))
            ->assertOk()
            ->viewData('rooms');

        $cells = collect($cells)->firstWhere('name', 'Toddler')['rows'][0]['cells'];

        $this->assertSame('came', $cells[self::MONDAY]);
        $this->assertSame('on', $cells['2026-09-08']);
    }

    /**
     * A day nobody booked but the child came anyway keeps both marks: the tint
     * says it was not expected, the fill says they were here, and it is still
     * billable.
     */
    public function test_a_drop_in_carries_the_tint_and_the_fill(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        // Nothing ticked at all, so every day is unexpected.

        Attendance::create([
            'child_id' => $ada->id,
            'attendance_date' => '2026-09-09',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-09 08:12:00'),
        ]);

        $room = collect($this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY, 'range' => 'month']))
            ->assertOk()
            ->viewData('rooms'))->firstWhere('name', 'Toddler');

        $this->assertSame('dropin', $room['rows'][0]['cells']['2026-09-09']);

        // A room is on the page because somebody attended it, not only because
        // somebody expected them to.
        $this->assertSame(1, $room['attended']['2026-09-09']);
        $this->assertSame(0, $room['counts']['2026-09-09']);
    }

    public function test_the_month_counts_what_was_expected_against_what_happened(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        foreach ([self::MONDAY, '2026-09-08'] as $date) {
            Attendance::create([
                'child_id' => $ada->id,
                'attendance_date' => $date,
                'session' => 'FULL',
                'signed_in_at' => Carbon::parse($date.' 08:12:00'),
            ]);
        }

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY, 'range' => 'month']))
            ->assertOk();

        // Five days expected that week, two of them attended. The comparison is
        // the whole reason this page exists, so it is on the page rather than
        // left to be counted by hand.
        $this->assertSame(5, $response->viewData('monthTotal'));
        $this->assertSame(2, $response->viewData('monthAttended'));

        $html = $response->getContent();
        $this->assertStringContainsString('>attended</td>', $html);
        $this->assertStringContainsString('2</b> of 5 expected days attended', $html);

        // A day where fewer came than were expected is findable without reading
        // every column.
        $this->assertStringContainsString('class="short"', $html);
    }

    /**
     * The clipboard stays blank. This is the rule the month sheet departs from,
     * so it is worth a test of its own rather than an assumption.
     */
    public function test_the_weekly_sheet_still_arrives_empty(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::query()->update(['is_scheduled' => true]);

        Attendance::create([
            'child_id' => $ada->id,
            'attendance_date' => self::MONDAY,
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse(self::MONDAY.' 08:12:00'),
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // The only filled boxes are the two samples in the key.
        $grid = substr($html, strpos($html, '<table class="cols">'));
        $this->assertSame(0, substr_count($grid, 'style="background:var(--accent)'));
    }

    public function test_a_week_is_still_one_page_and_is_the_default(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk();

        $this->assertCount(1, $response->viewData('sheets'));
        $this->assertSame('week', $response->viewData('range'));

        // Nothing to break onto a second sheet.
        $this->assertStringNotContainsString('class="sheet break"', $response->getContent());
    }

    public function test_an_unknown_range_falls_back_to_the_week(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        // A guessed query string gets the safe answer rather than an error: the
        // week is what the button asks for, and the month is the exception.
        $this->assertCount(1, $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY, 'range' => 'year']))
            ->assertOk()
            ->viewData('sheets'));
    }

    public function test_both_ranges_are_offered_before_the_print_dialog_opens(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // Asked on the sheet rather than on the printed page, because that page
        // opens the print dialog the moment it loads — arriving at the wrong
        // range would mean cancelling a dialog to fix it.
        $this->assertStringContainsString('This week', $html);
        $this->assertStringContainsString('Whole month', $html);
        $this->assertStringContainsString(e(route('attendance.print', ['date' => self::MONDAY, 'range' => 'month'])), $html);
    }

    public function test_the_printed_page_can_switch_range_without_going_back(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertSee('Whole month instead');

        $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY, 'range' => 'month']))
            ->assertOk()
            ->assertSee('Just this week instead');
    }

    /**
     * Both sheets offer the two things anyone does with a page of paper.
     *
     * A browser will not let a page choose the print destination, so Save
     * cannot skip the dialog. What it does instead is the part people get wrong
     * by hand: Chrome and Edge suggest the document title as the filename, and
     * the title is a heading — so a saved month used to land in Downloads as
     * "Attendance — September 2026.pdf", em dash and all.
     */
    public function test_both_sheets_offer_print_and_save_and_name_the_saved_file(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $expected = [
            'little-angels-attendance-week-of-sep-7-2026',
            'little-angels-attendance-september-2026',
        ];

        foreach ([[], ['range' => 'month']] as $i => $range) {
            $html = $this->actingAs($this->admin)
                ->get(route('attendance.print', $range + ['date' => self::MONDAY]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('onclick="sendToPrinter()">Print<', $html);
            $this->assertStringContainsString('onclick="saveAsPdf()">Save as PDF<', $html);

            // The destination is theirs to pick, so the page says which one.
            $this->assertStringContainsString('Save as PDF</b> under Destination', $html);

            // A filename, not a heading — and put back afterwards, because the
            // title is what the window itself is called.
            $this->assertStringContainsString("const FILENAME = '".$expected[$i]."'", $html);
            $this->assertStringContainsString("afterprint', () => { document.title = HEADING; }", $html);
        }
    }

    /** Neither button belongs on the paper. */
    public function test_the_bar_does_not_print(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        foreach ([[], ['range' => 'month']] as $range) {
            $this->actingAs($this->admin)
                ->get(route('attendance.print', $range + ['date' => self::MONDAY]))
                ->assertOk()
                ->assertSee('.bar{display:none;}', false);
        }
    }

    /**
     * The contracted hours ride beside the name on both printed sheets.
     *
     * The paper register is what the room works from, and the question it could
     * not answer was "when is this one due out?" — which meant leaving the
     * clipboard and opening the record. Written the way it is written on paper:
     * no colon, no meridiem. A register has no morning pick-ups.
     */
    public function test_the_printed_name_carries_the_contracted_hours(): void
    {
        $child = $this->makeChild('Morris', 'Oryan', 'Toddler', [
            'drop_off_time' => '07:30',
            'pick_up_time' => '16:30',
        ]);
        app(WeekSchedule::class)->open(self::MONDAY);

        // The sheet prints who is scheduled, so there has to be a day ticked.
        ScheduleSlot::where('child_id', $child->id)->update(['is_scheduled' => true]);

        foreach ([[], ['range' => 'month']] as $range) {
            $this->actingAs($this->admin)
                ->get(route('attendance.print', ['date' => self::MONDAY] + $range))
                ->assertOk()
                ->assertSee('Morris, Oryan')
                ->assertSee('730-430');
        }
    }

    /**
     * Half a range is worse than none.
     *
     * Most of the roll has no hours agreed yet, and "730-" on a printed sheet
     * reads as a time that was cut off rather than as one that was never
     * given.
     */
    public function test_a_child_with_no_agreed_hours_prints_no_range(): void
    {
        $child = $this->makeChild('Turner', 'Myla', 'Toddler', [
            'drop_off_time' => '08:00',
        ]);
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('child_id', $child->id)->update(['is_scheduled' => true]);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Turner, Myla', $html);
        $this->assertStringNotContainsString('800-', $html);
    }
    private function makeChild(string $last, string $first, string $room, array $extra = []): Child
    {
        return Child::create(array_merge([
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => $first,
            'last_name' => $last,
            'classroom' => $room,
        ], $extra));
    }
}
