<?php

namespace Database\Seeders;

use App\Models\StaffRule;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The Staff page with something on every tab — Phase 5 of docs/work.md, as
 * Staff.dc.html shows it.
 *
 * Works over the teachers already on the books, filling only what is
 * blank: an employment type and a job on each, a start date, a mobile, an
 * emergency contact, how they get to work; a rule or two on anyone with
 * none, the way the mock-up gives them (an opener, a fixed Wednesday, a
 * closer, somebody who must never be alone); and the Time off tab's
 * entries — an appointment next week, a vacation, an early leave, an extra
 * day, and a sick day and a holiday already behind them so Past · year has
 * totals to fold away.
 *
 * Run as often as you like; nothing already filled in is written over.
 *
 *     php artisan db:seed --class=StaffDemoSeeder --force
 */
class StaffDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const EMPLOYMENT = ['FT', 'FT', 'FT', 'PT_FLEX', 'PT', 'FT', 'PT_FLEX', 'FT', 'PT'];

    private const TRANSPORT = ['Own car', 'Bus', 'Walks', 'Own car', 'Dropped off'];

    public function run(): void
    {
        $director = User::where('role', 'admin')->orderBy('id')->first();
        $teachers = User::where('role', 'teacher')->orderBy('name')->get();
        $today = Carbon::today();

        foreach ($teachers as $i => $teacher) {
            $this->fillDetails($teacher, $i);
            $this->fillRules($teacher, $i, $teachers);
        }

        $this->fillTimeOff($teachers, $director, $today);

        $this->command?->info($teachers->count().' staff looked at; rules and time off filled where there were none.');
    }

    private function fillDetails(User $teacher, int $i): void
    {
        $fill = [];

        if (blank($teacher->employment)) {
            $fill['employment'] = self::EMPLOYMENT[$i % count(self::EMPLOYMENT)];
        }
        if (blank($teacher->job_role)) {
            $fill['job_role'] = $i % 3 === 0 ? 'Lead Teacher' : ($i % 7 === 5 ? 'Floater' : 'Assistant');
        }
        if (blank($teacher->start_date)) {
            $fill['start_date'] = Carbon::today()->subYears(1 + $i % 9)->subMonths($i % 11)->toDateString();
        }
        if (blank($teacher->phone)) {
            $fill['phone'] = '(716) 555-01'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT);
        }
        if (blank($teacher->emergency_contact)) {
            $fill['emergency_contact'] = ['Partner', 'Mother', 'Sister', 'Husband'][$i % 4];
            $fill['emergency_phone'] = '(716) 555-02'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT);
        }
        if (blank($teacher->transport)) {
            $fill['transport'] = self::TRANSPORT[$i % 5];
        }
        if (blank($teacher->aspire_id) && $i % 2 === 0) {
            $fill['aspire_id'] = 'A'.(100200 + $i * 37);
        }
        if (blank($teacher->evaluation_score) && $i % 3 !== 1) {
            $fill['evaluation_score'] = [4.5, 4.0, 3.5, 5.0][$i % 4];
        }

        if ($fill !== []) {
            $teacher->forceFill($fill)->save();
        }
    }

    /** A rule or two, as the mock-up gives its people, on anyone with none. */
    private function fillRules(User $teacher, int $i, $teachers): void
    {
        if ($teacher->staffRules()->exists()) {
            return;
        }

        $room = $teacher->classroom;
        $rules = match ($i % 6) {
            0 => [
                ['CAN_OPEN', 'HARD', [], 'Only '.$teacher->firstName().' opens at 7:15'],
                ['PREFERRED_START', 'SOFT', ['time_1' => 435], null],
                ['MAX_HOURS', 'SOFT', [], null],
            ],
            1 => [
                ['REQUIRED_HOURS', 'HARD', ['number' => 80, 'value_text' => 'BIWEEKLY'], null],
                ['FIXED_SHIFT', 'HARD', ['day' => 'WED', 'time_1' => 720, 'time_2' => 1080], 'Wednesday half day'],
                ['FIXED_END', 'HARD', ['day' => 'ALL', 'time_1' => 1080], 'Closes every day'],
            ],
            2 => [
                ['NEEDS_SUPERVISION', 'HARD', [], 'Can’t be left alone'],
                ['AVAILABLE_AFTER', 'SOFT', ['day' => 'ALL', 'time_1' => 720], 'Prefers afternoons'],
            ],
            3 => [
                ['UNAVAILABLE_DAY', 'HARD', ['day' => 'MON'], null],
                ['UNAVAILABLE_DAY', 'HARD', ['day' => 'FRI'], null],
                ['AVAILABLE_AFTER', 'HARD', ['day' => 'TUE', 'time_1' => 720], null],
            ],
            4 => [
                ['PREFERRED_DAY_OFF', 'SOFT', ['day' => 'FRI'], 'Can work if needed'],
                ['PREFERRED_FULL_DAYS', 'SOFT', ['number' => 3], null],
            ],
            default => [
                ['AVAILABLE_WINDOW', 'HARD', ['day' => 'ALL', 'time_1' => 435, 'time_2' => 840], 'That’s it'],
            ],
        };

        if ($room) {
            $rules[] = ['ROOM_PREFERENCE', 'SOFT', ['value_text' => $room], null];
        }

        // One pair that must never share a room, written on the first of them.
        if ($i === 0 && $teachers->count() > 1) {
            $rules[] = ['NO_PAIR', 'HARD', ['value_text' => $teachers[1]->name], 'No '.$teachers[1]->firstName()];
        }

        foreach ($rules as [$type, $priority, $fields, $note]) {
            $teacher->staffRules()->create([
                'rule_type' => $type,
                'priority' => $priority,
                'day' => $fields['day'] ?? (in_array('day', StaffRule::FIELDS[$type], true) ? 'ALL' : null),
                'time_1' => $fields['time_1'] ?? null,
                'time_2' => $fields['time_2'] ?? null,
                'number' => $fields['number'] ?? null,
                'value_text' => $fields['value_text'] ?? null,
                'source_note' => $note,
            ]);
        }
    }

    /** The Time off tab's entries, spread over the first few teachers. */
    private function fillTimeOff($teachers, ?User $director, Carbon $today): void
    {
        if (StaffTimeOff::exists()) {
            return;
        }

        $nextTue = $today->copy()->next(Carbon::TUESDAY);
        $nextWed = $nextTue->copy()->addDay();
        $nextThu = $nextTue->copy()->addDays(2);

        $plan = [
            // [teacher index, date, until, kind, from, to, reason, paid, note, created days ago]
            [0, $nextTue, null, 'until', 900, null, 'appointment', false, 'Doctor, bringing a note', 5],
            [0, $today->copy()->subWeeks(4)->next(Carbon::FRIDAY)->subWeeks(1), null, 'all', null, null, 'sick', true, null, 30],
            [0, $today->copy()->subMonths(3)->startOfMonth()->addDays(13), null, 'until', 660, null, 'appointment', false, null, 95],
            [0, $today->copy()->subMonths(4)->startOfMonth()->addDays(28), $today->copy()->subMonths(4)->startOfMonth()->addDays(29), 'all', null, null, 'vacation', true, 'Two days with family', 130],
            [1, $nextTue, null, 'all', null, null, 'personal', false, null, 6],
            [2, $nextWed, null, 'all', null, null, 'vacation', true, null, 24],
            [3, $nextThu, null, 'leave', null, 720, 'family', false, 'School pickup', 5],
            [4, $nextWed, null, 'extra', 540, 900, 'extra', false, null, 5],
            [5, $today->copy()->subMonths(2)->startOfMonth()->addDays(2), $today->copy()->subMonths(2)->startOfMonth()->addDays(3), 'all', null, null, 'vacation', true, null, 70],
        ];

        foreach ($plan as [$idx, $date, $until, $kind, $from, $to, $reason, $paid, $note, $ago]) {
            $teacher = $teachers[$idx] ?? null;
            if (! $teacher) {
                continue;
            }

            $entry = new StaffTimeOff([
                'user_id' => $teacher->id,
                'date' => $date->toDateString(),
                'until' => $until?->toDateString(),
                'kind' => $kind,
                'from_minute' => $from,
                'to_minute' => $to,
                'reason' => $reason,
                'paid' => $paid,
                'note' => $note,
                'created_by' => $director?->id,
            ]);
            $entry->created_at = $today->copy()->subDays($ago);
            $entry->updated_at = $entry->created_at;
            $entry->save();
        }
    }
}
