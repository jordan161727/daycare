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

        // A day gone by is drawn exactly like today — a past arrival keeps its
        // box. It used to shed it (att-history) so the week sloped down into
        // today; the centre asked for the same marks in every column. The only
        // thing that still quietens a column is being untouchable.
        $this->assertStringNotContainsString("classes.push('att-history')", $html);
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

        // Both boxes sit in one slot, and so does a whole-day box — the slot is
        // what makes every row the same height. Stacking used to be a class the
        // half-day rows had and the others did not, which is exactly why the
        // two came out different heights and the columns stepped in and out.
        $this->assertStringContainsString('class="att-slot"', $html);
        $this->assertStringNotContainsString("'att-stack'", $html);

        // And the taller slot only applies while a half-day room is on the
        // sheet, which it is here.
        $this->assertStringContainsString('att-split', $html);

        // And its time is the compact form, because it shares the cell —
        // which is now the only form, so a whole day and a half day are the
        // same shape down one column.
        $this->assertStringContainsString('displayTime(childId, date, session) {'."
".'        return this.sessionTime(childId, date, session);', $html);
    }

    public function test_a_sheet_with_no_half_day_room_keeps_its_rows_compact(): void
    {
        // The other half of the bargain. Every row matching a stacked AM/PM
        // cell is right when there is one on the sheet; a centre with no School
        // Age children would just be reading an inch and a half of white space
        // on every line to match a stack that is never drawn.
        $this->makeChild(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'classroom' => 'Toddler']);
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="att-slot"', $html);
        $this->assertStringNotContainsString('att-split', $html);
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
        // It names the two jobs separately now. On a day gone or today a tap
        // walks the full cycle and the time it lands on is an arrival; on a day
        // still to come a tap is only the yes/no, and the hour lives on the
        // pencil because it is the plan rather than a record.
        $this->assertStringContainsString('Tap a cell to cycle not attending → expected → time.', $html);
        $this->assertStringContainsString('the pencil sets the hour they are due — which is the plan, not an arrival', $html);
        $this->assertStringContainsString("'Only ' + this.todayLabel + ' can be changed.'", $html);

        // Read on the first morning and never again, so the sheet does not open
        // carrying it: away by default, a press of the "?" away, and remembered
        // once asked for. In a try/catch because a private window throws on the
        // accessor itself, and a page that will not render is the worse outcome.
        $this->assertStringContainsString('@attendance-key.window="toggleKey()"', $html);
        $this->assertStringContainsString("localStorage.getItem('attendance.key') === 'shown'", $html);
        $this->assertStringContainsString('catch { return false; }', $html);
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

        // The box states what it is through a handful of bindings rather than
        // twenty — four hundred boxes, and what each costs to build is most of
        // what the sheet costs to open. The static identity goes through the
        // object; the contents through x-html.
        $this->assertContains('cellAttrs', $called);
        $this->assertContains('cellInner', $called);

        // And the class is bound LIVE, on its own, never through the object.
        // Alpine applies an x-bind object once at mount and freezes each key,
        // so a class in there stuck at whatever the cell was first drawn as —
        // a tapped-off day kept its dashed box around the dot. The locked-
        // column check stays one level in, because it is stable for the life
        // of the element (a mode switch reloads the sheet).
        $this->assertContains('cellClass', $called, 'the class must be a live :class binding in the partial');

        preg_match('/cellAttrs\s*\([^)]*\)\s*\{.*?\n    \},/s', $component, $attrs);
        $this->assertNotEmpty($attrs, 'cellAttrs() should be defined on the component');
        $this->assertStringContainsString('canTap(date)', $attrs[0], 'the locked-column check is what went missing before');
        $this->assertStringNotContainsString('cellClass(', $attrs[0], 'the class must not go back into the frozen x-bind object');

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
