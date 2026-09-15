<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What a cell on the sign-in sheet looks like.
 *
 * The cell is the arrival time, not a word: a filled cell answers "what time
 * did they come in" without a second glance. The two states that matter —
 * arrived as expected, arrived on a day nobody planned for — are told apart by
 * a fill rather than by reading, a cell still waiting reads "expected", and a
 * day nobody booked is a dot that still takes a tap.
 */
class AttendanceCellDesignTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-14';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_a_signed_in_cell_is_the_arrival_time(): void
    {
        $child = $this->makeChild();
        app(WeekSchedule::class)->open(self::MONDAY);

        Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => self::MONDAY,
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse(self::MONDAY.' 08:42:00'),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk();

        // Sheet-sized: the grid is sixty rows by five columns and every filled
        // cell carries one of these, so "8:42 AM" costs four characters three
        // hundred times over.
        $map = $response->viewData('attendanceMap');
        $this->assertSame('8:42a', $map[$child->id][self::MONDAY]['FULL']);

        // And the cell is that time, with no second mark beside it.
        $this->assertStringContainsString('return this.sessionTime(childId, date, session);', $response->getContent());
    }

    public function test_the_four_states_are_the_four_marks_on_the_sheet(): void
    {
        $this->makeChild();
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // Waiting is an empty dashed box — the word "expected" came off it,
        // because the outline already says so sixty times down the sheet. A
        // day nobody booked is the quietest mark there is, because it is the
        // commonest cell.
        // One box, and component classes for what it is — see "The attendance
        // cell" in app.css. The grid and the key are painted from the same
        // names, so a chip above cannot disagree with a cell below.
        $this->assertStringContainsString("classes.push('att-expected')", $html);
        $this->assertStringContainsString("classes.push('att-none')", $html);
        $this->assertStringContainsString("classes.push('att-time')", $html);
        $this->assertStringContainsString("classes.push('att-unplanned')", $html);
        $this->assertStringContainsString('class="att-dot"', $html);

        // A day gone by reads quieter; a column this mode cannot touch, quieter still.
        $this->assertStringContainsString("classes.push('att-history')", $html);
        $this->assertStringContainsString("classes.push('att-locked')", $html);

        // The old wording is gone: a cell says the time or says it is expected.
        $this->assertStringNotContainsString(">Present<", $html);
    }

    /**
     * A morning and an afternoon share one cell, so each takes half the room a
     * whole day does — and both stay wide enough for the time they will hold.
     */
    public function test_a_split_day_gets_two_cells_labelled_am_and_pm(): void
    {
        $this->makeChild(['first_name' => 'Kane', 'last_name' => 'Kruger', 'classroom' => 'School Age', 'birth_date' => '2019-03-02']);
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("classes.push('att-half')", $html);
        $this->assertStringContainsString('class="att-tag" x-text="session"', $html);
        $this->assertStringContainsString("child.sessions.length > 1 ? 'att-stack' : ''", $html);

        // And its time is the compact form, because it shares the cell.
        $this->assertStringContainsString("if (! short || session !== 'FULL') return short;", $html);
    }

    public function test_the_key_is_a_strip_above_the_sheet_that_can_be_put_away(): void
    {
        $this->makeChild();
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // Every mark named, in the sheet's own marks.
        foreach (['signed in', 'unplanned, still billable', 'scheduled, not in yet', 'not scheduled — tap to sign in anyway'] as $phrase) {
            $this->assertStringContainsString($phrase, $html);
        }

        // What a tap does, in the mode you are in, from one place.
        $this->assertStringContainsString('x-text="hint"', $html);
        $this->assertStringContainsString('Tap a cell to cycle not attending → expected → time. The pencil types an exact time.', $html);
        $this->assertStringContainsString("'Only ' + this.todayLabel + ' can be changed.'", $html);

        // Read on the first morning and never again, so it can be put away —
        // and stays away, in a try/catch because a private window throws on the
        // accessor itself and a page that will not render is the worse outcome.
        $this->assertStringContainsString('Hide key', $html);
        $this->assertStringContainsString("localStorage.getItem('attendance.key')", $html);
        $this->assertStringContainsString('catch { return true; }', $html);
    }

    public function test_the_closed_day_chip_says_which_days_and_why(): void
    {
        $this->makeChild();
        \App\Models\ClosureDay::create(['closed_on' => self::MONDAY, 'reason' => 'Labour Day']);
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // "2 days closed" does not say which two or why, and a gray column with
        // no explanation is what people come to the office to ask about.
        $this->assertStringContainsString(':title="closedReasons()"', $html);
        $this->assertStringContainsString('closedReasons()', $html);
    }

    /**
     * A sign-in on the Monday of the week has to reach the sheet.
     *
     * attendance_date is a DATE column, and the week range was bound as Carbon
     * instances — which bind as 'Y-m-d H:i:s'. SQLite compares the two as text,
     * so '2026-09-14' sorts before '2026-09-14 00:00:00' and the first day of
     * every week fell outside its own range. The same trap ClosureDay documents,
     * and Monday is not a rare day to be missing.
     */
    public function test_a_sign_in_on_the_first_day_of_the_week_is_not_dropped(): void
    {
        $child = $this->makeChild();
        app(WeekSchedule::class)->open(self::MONDAY);

        foreach ([self::MONDAY, '2026-09-18'] as $date) {
            Attendance::create([
                'child_id' => $child->id,
                'attendance_date' => $date,
                'session' => 'FULL',
                'signed_in_at' => Carbon::parse($date.' 08:00:00'),
            ]);
        }

        $map = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->viewData('attendanceMap');

        // Both ends of the range, not just the one safely inside it.
        $this->assertArrayHasKey(self::MONDAY, $map[$child->id]);
        $this->assertArrayHasKey('2026-09-18', $map[$child->id]);
    }
    /**
     * A day the centre was shut says so in every cell, not just the header.
     *
     * It used to draw the same dot as "nobody booked this day", so two columns
     * meaning entirely different things — nobody was expected, and nobody could
     * have come — read identically down the sheet.
     */
    public function test_a_closed_day_is_marked_in_its_cells_not_only_its_header(): void
    {
        $this->makeChild();
        \App\Models\ClosureDay::create(['closed_on' => self::MONDAY, 'reason' => 'Labour Day']);
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // A rule of its own, ahead of booked-or-not, and in the same rose the
        // header's closure chip uses.
        $this->assertStringContainsString("if (this.isClosed(date)) return '—';", $html);
        $this->assertStringContainsString("classes.push('att-closed')", $html);
        $this->assertStringContainsString('centre closed', $html);

        // The whole column reads as one shut block — painted from Alpine, since
        // a day is closed and reopened without the page reloading.
        $this->assertStringContainsString("isClosed('".self::MONDAY."') ? 'att-closed-col'", $html);

        // And the reason sits on every cell, because a row read across never
        // passes the header that carries it once.
        $this->assertStringContainsString('this.closureReason(date) +', $html);
    }

    /**
     * A closed day still takes a sign-in. Shutting the centre clears the ticks;
     * it does not make a child who turned up anyway impossible to record.
     */
    public function test_a_closed_cell_is_still_a_button(): void
    {
        $child = $this->makeChild();
        \App\Models\ClosureDay::create(['closed_on' => self::MONDAY, 'reason' => 'Labour Day']);
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => self::MONDAY,
                'session' => 'FULL',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        // And once they are in, the cell is the time — an arrival outranks the
        // closure, because it is a fact about the day rather than a plan for it.
        $map = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->viewData('attendanceMap');

        $this->assertArrayHasKey(self::MONDAY, $map[$child->id]);
    }
    /**
     * A column with nothing in it for this child centres its dash.
     *
     * Left-aligned under rows reading "8:00 AM – 5:30 PM", a dash looks like a
     * very short entry rather than an absent one — and a roster where most
     * children have no hours agreed yet is a whole column of them.
     */
    public function test_a_blank_cell_is_centred_and_lighter_than_a_real_value(): void
    {
        $this->makeChild(['drop_off_time' => null, 'pick_up_time' => null]);
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // One rule, so a blank reads the same in every column it can appear in.
        $this->assertStringContainsString("blankClass(value) { return value ? '' : 'text-center text-slate-300", $html);

        foreach (['child.birth_date', 'child.age', 'child.schedule_hours'] as $field) {
            $this->assertStringContainsString('blankClass('.$field.')', $html);
        }
    }
    /**
     * Every method the cells call is a method the component has.
     *
     * Alpine answers a missing one by logging to the console and producing
     * nothing — so a :class that calls it silently yields no classes, the cell
     * renders unstyled, and the page looks precisely as it did before whatever
     * change dropped the method. Nothing fails. It simply does not happen, and
     * the only signal is a console nobody has open.
     *
     * This reads the cell partial, collects what it calls, and checks the
     * component defines each one.
     */
    public function test_every_method_the_cells_call_is_one_the_component_has(): void
    {
        $markup = file_get_contents(resource_path('views/attendance/partials/day-buttons.blade.php'));

        // Prose is not code: the comment at the top of that file explains the
        // states in English, and "a dot (not attending)" is not a call.
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $markup);

        // Blade echoes are PHP, evaluated on the server long before Alpine
        // reads what is left. Removing them leaves the JavaScript behind.
        $markup = preg_replace('/\{\{.*?\}\}/s', '', $markup);

        // And @php / @foreach lines, which are PHP too. Named rather than
        // "every line starting with @", because @click= lines are exactly the
        // ones carrying the calls this is here to check.
        $markup = preg_replace('/^\s*@(?:php|endphp|foreach|endforeach|if|endif|else|isset|endisset)\b[^
]*$/m', '', $markup);
        $component = file_get_contents(resource_path('views/attendance/index.blade.php'));

        // Bare calls inside Alpine expressions: "cellClass(", "canTap(" and so
        // on. Anything reached through a dot is somebody else's object.
        preg_match_all('/(?<![\w.$])([a-z][A-Za-z0-9]*)\s*\(/', $markup, $found);

        // Blade, JavaScript and DOM words that are never component methods.
        $ignore = [
            'if', 'for', 'return', 'function', 'typeof', 'in', 'of', 'new', 'catch',
            'includes', 'trim', 'split', 'join', 'map', 'filter', 'exec', 'focus', 'select',
            'blur', 'preventDefault', 'route', 'asset', 'config', 'auth', 'trans', 'js',
            // Blade directives, which are compiled away before Alpine sees them.
            'php', 'endphp', 'foreach', 'endforeach', 'class', 'checked', 'disabled',
        ];

        $called = array_values(array_unique(array_diff($found[1], $ignore)));

        // The partial is not empty of calls, or this test proves nothing.
        $this->assertNotEmpty($called);
        $this->assertContains('canTap', $called, 'the locked-column check is what went missing before');
        $this->assertContains('cellClass', $called);

        foreach ($called as $method) {
            $defined = preg_match('/(?:^|\s)(?:get\s+)?'.preg_quote($method, '/').'\s*\([^)]*\)\s*\{/m', $component) === 1
                || preg_match('/(?:^|\s)'.preg_quote($method, '/').':\s*(?:function|\()/m', $component) === 1;

            $this->assertTrue(
                $defined,
                "The cells call {$method}() but attendanceApp does not define it. Alpine will log to the console and render nothing — the cell will silently lose its classes."
            );
        }
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'classroom' => 'PreK',
            'birth_date' => '2022-12-15',
        ]);
    }
}
