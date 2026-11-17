<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\DoorAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * How heavy the Check in page is for a full centre (QA, 12 Nov 2026).
 *
 * The front desk reported the page as slow: 1.7 MB of HTML, seven thousand
 * elements, a 256 KB inline script. This seeds a centre-sized roll — a
 * hundred and twenty children across every room, three days of arrivals this
 * week and a note on a third of them — renders the page, and holds it to a
 * budget, so the weight cannot creep back unnoticed.
 *
 * Measured on this seed: 2,271,145 bytes before (a 1.64 MB sheet table of
 * x-html cells, a 477 KB rows literal) and 627,838 after. Set
 * CHECKIN_WEIGHT=1 to print the breakdown, CHECKIN_WEIGHT_DUMP=<file> to keep
 * the page.
 */
class CheckInPageWeightTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, mid-morning: Monday and Tuesday are done, today is open.
        $this->travelTo(Carbon::parse('2026-11-11 10:00:00', config('app.timezone')));

        $this->admin = User::factory()->create(['role' => 'admin']);

        $monday = Carbon::parse('2026-11-09');

        for ($i = 0; $i < 120; $i++) {
            $child = Child::create([
                'lan' => (string) (20000 + $i),
                'first_name' => 'Child'.$i,
                'last_name' => 'Family'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'status' => 'Active',
                // Spread across every band, Infant to School Age.
                'dob' => today()->subMonths(3 + ($i * 7) % 110)->toDateString(),
                'gender' => $i % 2 ? 'Female' : 'Male',
                'schedule_days' => [1, 2, 3, 4, 5],
            ]);

            foreach ([0, 1, 2] as $offset) {
                $day = $monday->copy()->addDays($offset);
                $row = DoorAttendance::create([
                    'child_id' => $child->id,
                    'attendance_date' => $day->toDateString(),
                    'session' => $child->sessions()[0] ?? 'FULL',
                    'signed_in_at' => $day->copy()->setTime(8, $i % 50),
                    'signed_out_at' => $offset < 2 ? $day->copy()->setTime(16, $i % 50) : null,
                    'health_in_code' => 0,
                    'health_out_code' => $offset < 2 ? 0 : null,
                ]);

                if ($i % 3 === 0) {
                    $row->comments()->create([
                        'child_id' => $child->id,
                        'user_id' => $this->admin->id,
                        'tag' => 'other',
                        'body' => 'Had a good day, ate all lunch and napped well. Spare clothes in the bag.',
                    ]);
                }
            }
        }
    }

    public function test_the_page_stays_within_its_weight_budget(): void
    {
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $script = preg_match('/function checkInGrid\(\) \{ return \{.*?<\/script>/s', $html, $m) ? strlen($m[0]) : 0;
        $sheet = preg_match('/<table class="att-grid-table.*?<\/table>/s', $html, $m) ? strlen($m[0]) : 0;

        if ($dump = getenv('CHECKIN_WEIGHT_DUMP')) {
            file_put_contents($dump, $html);
        }

        if (getenv('CHECKIN_WEIGHT')) {
            fwrite(STDERR, sprintf(
                "\nCheck in, 120 children: html %d B, checkInGrid script %d B, sheet table %d B, tags %d\n",
                strlen($html), $script, $sheet, substr_count($html, '<'),
            ));
        }

        // Every child is still on the page, cards and sheet alike.
        $this->assertSame(120, substr_count($html, '>IN</td>'));

        // Under 900 KB for the whole page, from 2.27 MB; the sheet under
        // 300 KB, from 1.64 MB; the component's script under 450 KB.
        $this->assertLessThan(900_000, strlen($html));
        $this->assertLessThan(300_000, $sheet);
        $this->assertLessThan(450_000, $script);
    }
}
