<?php

namespace Database\Seeders;

use App\Models\Child;
use App\Models\ChildDocument;
use App\Models\ChildNote;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\User;
use App\Services\Enrollment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The Children list and the child record with something in every card —
 * Phase 2 of docs/work.md, as Children.dc.html and ChildProfile.dc.html
 * show them.
 *
 * Works over the children already on the roll, filling only what is blank
 * on each record: a nickname here and there, the DSS numbers on about half,
 * a doctor and an immunization date, the papers on file, an office note or
 * two, and an adult with the Pays / Texts ticks where a child has nobody.
 * Spread so the list shows every state the mock does — complete records,
 * a photo consent missing, immunizations due, no guardian, hours not
 * agreed — and the Withdrawn, Pending and Archived tabs each have someone
 * in them (those six children are made here if they are not on the roll).
 *
 * Run as often as you like; nothing already filled in is written over.
 *
 *     php artisan db:seed --class=ChildRecordDemoSeeder --force
 */
class ChildRecordDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /** first, last, room, dob, status, how they left / when they start */
    private const EXTRA = [
        ['Iris', 'Tan', 'Toddler', '2024-04-05', 'withdrawn', ['2026-08-28', 'Family choice', 'Grandmother is home full-time now', 'Yes']],
        ['Theo', 'Marsh', 'PreK', '2023-03-18', 'withdrawn', ['2026-06-26', 'Started school / aged out', 'Kindergarten at PS 81 in September', 'Yes']],
        ['Kai', 'Morgan', 'PreK', '2023-02-02', 'withdrawn', ['2026-10-09', 'Moved out of the area', 'Moved to Cheektowaga school district — mom gave 2 weeks\' notice', 'Yes']],
        ['Nia', 'Brown', 'Infant', '2026-06-14', 'pending', ['+7 days']],
        ['Sam', 'Okoye', 'School Age', '2013-02-11', 'archived', ['2025-06-27', 'Started school / aged out', 'Turned 12 — too old for School Age', 'No']],
        ['Mila', 'Novak', 'Toddler', '2021-03-03', 'archived', ['2024-08-30', 'Moved out of the area', 'Family moved to Rochester', 'Yes']],
        // Never started: a pending enrollment the family cancelled.
        ['Leo', 'Park', 'Infant', '2026-05-20', 'cancelled', ['Chose another provider', 'Found a centre nearer to work']],
        // Left and came back: the Re-enroll button, as the history shows it.
        ['Zoe', 'Hale', 'PreK', '2022-11-08', 'reenrolled', ['2026-05-29', 'Family choice', 'Summer with grandparents in Florida', 'Yes']],
    ];

    private const ROOMS = ['Infant', 'Toddler', 'Transition', 'PreK', 'UPK-4', 'School Age'];

    /** text, type, severity, reaction, action — the alert as its own entry. */
    private const ALERTS = [
        ['Peanuts — all tree nuts', 'allergy', 'severe', 'Swelling, hives within minutes', 'EpiPen in the office cabinet; call 911 then mom'],
        ['Asthma', 'medical', 'mild', 'Wheezing after running', 'Inhaler in the room first-aid bag, two puffs'],
        ['No pickup by Jordan Key', 'court', 'severe', null, 'Call the office at once if he arrives; order on file'],
        ['Leaves early on Tuesdays', 'note', null, null, 'Grandma collects at 2:30'],
    ];

    private const COMMENTS = [
        ['parent_note', 'Mom says he was up at 4 — may need an early nap.'],
        ['belongings', 'Came in without a change of clothes today.'],
        ['health', 'Runny nose at drop-off, no fever. Watching.'],
        ['other', 'Big day — first time on the climbing frame without help!'],
    ];

    private const NICKNAMES = ['Ella' => 'Ellie', 'Ava' => 'Avi', 'Ruby' => 'Rubes', 'Elizabeth' => 'Liz', 'Alexander' => 'Alex', 'Isabella' => 'Bella'];

    private const DOCTORS = ['Dr. Patel · Elmwood Pediatrics · (716) 555-0190', 'Dr. Okafor · Buffalo Kids Clinic · (716) 555-0171', 'Dr. Lin · Delaware Pediatrics · (716) 555-0144'];

    private const OFFICE_NOTES = [
        'Mom asked us to watch for a rash — new detergent at home.',
        'Oat milk running low — asked parents for 2 more cartons.',
        'Dad will collect early on Fridays through November.',
        'Tuition for this month paid in cash at the desk.',
    ];

    public function run(Enrollment $enrollment): void
    {
        $director = User::where('role', 'admin')->orderBy('id')->first();

        $this->makeExtras($director);

        $children = Child::where('status', 'Active')->orderBy('id')->get();

        foreach ($children as $i => $child) {
            $this->fillRecord($child, $i, $director);
        }

        $this->command?->info($children->count().' active records looked at; '.count(self::EXTRA).' demo children on the Withdrawn / Pending / Archived tabs.');
    }

    /** The six children the other tabs show, made once. */
    private function makeExtras(?User $director): void
    {
        foreach (self::EXTRA as [$first, $last, $room, $dob, $state, $detail]) {
            if (Child::where('first_name', $first)->where('last_name', $last)->exists()) {
                continue;
            }

            $child = Child::create([
                'lan' => Child::nextLan(),
                'first_name' => $first,
                'last_name' => $last,
                'status' => $state === 'pending' ? 'Pending' : 'Active',
                'classroom' => $room,
                'classroom_override' => $room,
                'birth_date' => $dob,
                'dob' => $dob,
                'enrolled_on' => $state === 'pending' ? Carbon::today()->modify($detail[0])->toDateString() : Carbon::parse($dob)->addMonths(8)->toDateString(),
                'schedule_days' => [1, 2, 3, 4, 5],
                'drop_off_time' => '08:00',
                'pick_up_time' => '17:00',
            ]);

            $enrolledOn = $child->enrolled_on ?? Carbon::today();
            $this->event($child, 'enrolled', 'Enrolled · '.$room, 'Enrolment packet received', $enrolledOn->copy()->subDays(3));

            if ($state === 'pending') {
                // Waiting to start: the packet is in, the rest is not.
                $child->forceFill([
                    'enrollment_note' => 'Starts '.$enrolledOn->format('M j').' — subsidy application sent to DSS, waiting on the CIN',
                    'enrollment_note_at' => Carbon::today()->subDays(2),
                ])->save();
                $this->document($child, 'enrollment_packet', Carbon::today()->subDays(5)->toDateString(), null, $director);
                $this->event($child, 'note', 'Note · subsidy application sent to DSS', null, Carbon::today()->subDays(2));

                continue;
            }

            if ($state === 'cancelled') {
                [$reason, $note] = $detail;
                $child->forceFill([
                    'status' => 'Inactive',
                    'withdrawn_on' => Carbon::today()->subDays(12)->toDateString(),
                    'withdrawal_reason' => $reason,
                    'withdrawal_note' => $note,
                    'could_return' => 'Not sure',
                ])->save();
                $this->event($child, 'cancelled', 'Cancelled · '.$reason, $note, Carbon::today()->subDays(12));

                continue;
            }

            [$lastDay, $reason, $note, $back] = $detail;
            $child->forceFill([
                'status' => 'Inactive',
                'withdrawn_on' => $lastDay,
                'withdrawal_reason' => $reason,
                'withdrawal_note' => $note,
                'could_return' => $back,
            ])->save();
            $this->event($child, 'withdrawn', 'Withdrawn · '.$reason, 'Last day '.Carbon::parse($lastDay)->format('M j, Y').' · '.$note.' · Could return: '.$back, Carbon::parse($lastDay)->subDays(10));

            if ($state === 'archived') {
                $child->archive($director);
                $this->event($child, 'archived', 'Archived', null, Carbon::parse($lastDay)->addMonths(2));
            }

            if ($state === 'reenrolled') {
                $child->forceFill(['status' => 'Active', 'withdrawn_on' => null, 'withdrawal_reason' => null, 'withdrawal_note' => null, 'could_return' => null])->save();
                $this->event($child, 'reenrolled', 'Re-enrolled · '.$room, 'Back from the summer away; same LAN, same family', Carbon::parse($lastDay)->addMonths(2)->addDays(3));
            }
        }
    }

    /** A line on the Enrollment history, dated when it happened. */
    private function event(Child $child, string $event, string $summary, ?string $note, Carbon $at): void
    {
        $row = $child->statusChanges()->make([
            'event' => $event,
            'from_status' => $summary,
            'to_status' => $child->status,
            'note' => $note,
        ]);
        $row->created_at = $at;
        $row->updated_at = $at;
        $row->save();
    }

    /** A paper on file, unless that kind is there already. */
    private function document(Child $child, string $kind, ?string $signed, ?string $expires, ?User $director): void
    {
        if ($child->documents()->where('kind', $kind)->exists()) {
            return;
        }

        ChildDocument::create([
            'child_id' => $child->id,
            'kind' => $kind,
            'name' => Child::DOCUMENT_KINDS[$kind],
            'signed_on' => $signed,
            'expires_on' => $expires,
            'uploaded_by' => $director?->id,
        ]);
    }

    /** One record, filled where it is blank, varied by its place on the roll. */
    private function fillRecord(Child $child, int $i, ?User $director): void
    {
        $today = Carbon::today();

        // A nickname for the few first names the mock gives one.
        if (blank($child->nickname) && isset(self::NICKNAMES[$child->first_name])) {
            $child->nickname = self::NICKNAMES[$child->first_name];
        }

        // About half on the subsidy; one in eight of those without a CIN yet.
        if (blank($child->dss_case_no) && blank($child->dss_cin) && $i % 2 === 0) {
            $child->dss_case_no = 'S11'.str_pad((string) (1000 + $i), 5, '0', STR_PAD_LEFT).chr(65 + $i % 26);
            if ($i % 8 !== 4) {
                $child->dss_cin = chr(65 + $i % 6).chr(65 + ($i * 7) % 26).str_pad((string) (11223 + $i * 11), 5, '0', STR_PAD_LEFT).chr(66 + $i % 20);
            }
        }

        // Hours and days, so the schedule column has something to show; one
        // in nine is left without hours, which the list flags.
        if (! $child->scheduleDays() && $i % 9 !== 7) {
            $child->schedule_days = $i % 5 === 3 ? [1, 3, 5] : [1, 2, 3, 4, 5];
        }
        if (blank($child->drop_off_time) && $i % 9 !== 7) {
            $child->drop_off_time = ['07:30', '08:00', '08:30', '09:00'][$i % 4];
            $child->pick_up_time = ['17:00', '17:30', '16:30', '15:00'][$i % 4];
        }

        // The health facts beside the alerts. Immunizations: most up to date,
        // one in seven due soon, one in eleven never entered.
        $health = $child->health ?? [];
        if (blank($health['doctor'] ?? null)) {
            $health['doctor'] = self::DOCTORS[$i % 3];
        }
        if (blank($health['immunizations_due'] ?? null) && $i % 11 !== 5) {
            $health['immunizations_due'] = ($i % 7 === 2 ? $today->copy()->addDays(10) : $today->copy()->addMonths(3 + $i % 9))->toDateString();
        }
        if (blank($health['medication'] ?? null) && $i % 13 === 6) {
            $health['medication'] = 'Inhaler, two puffs before outdoor play';
        }
        $child->health = $health;

        // A nickname on a few more, the way a room knows a child.
        if (blank($child->nickname) && $i % 23 === 7) {
            $child->nickname = ['Bear', 'JJ', 'Sunny', 'Peanut', 'Bubba'][intdiv($i, 23) % 5];
        }

        // Every alert as its own entry: how bad, what happens, what to do.
        // Alerts already on file keep their words and gain the rest; a few
        // children with none get one of each kind.
        $alerts = $child->alerts ?? [];
        foreach ($alerts as &$alert) {
            if (! isset($alert['severity']) && ! isset($alert['action'])) {
                $kind = $alert['type'] ?? 'note';
                $alert['severity'] = $kind === 'allergy' ? ($i % 2 ? 'severe' : 'mild') : ($kind === 'medical' ? 'mild' : null);
                $alert['reaction'] = $kind === 'allergy' ? 'Hives, upset stomach' : null;
                $alert['action'] = ['allergy' => 'Avoid; antihistamine in the office if exposed', 'medical' => 'See the care plan in the office', 'court' => 'Call the office at once; order on file'][$kind] ?? null;
                $alert['plan_on'] = in_array($kind, ['allergy', 'medical'], true) ? $today->copy()->subMonths(4)->toDateString() : null;
            }
        }
        unset($alert);
        if ($alerts === [] && in_array($i % 19, [4, 8, 12, 15], true)) {
            [$text, $type, $severity, $reaction, $action] = self::ALERTS[array_search($i % 19, [4, 8, 12, 15], true)];
            $alerts[] = ['text' => $text, 'type' => $type, 'severity' => $severity, 'reaction' => $reaction, 'action' => $action, 'plan_on' => $severity ? $today->copy()->subMonths(2)->toDateString() : null];
        }
        $child->alerts = $alerts;

        if (blank($child->custody_note) && $i % 10 === 9) {
            $child->custody_note = 'Shared — both parents may collect';
        }

        if (blank($child->enrollment_note) && $i % 6 === 1) {
            $child->enrollment_note = ['Subsidy authorization renews Jan 31 — paperwork sent to DSS', 'CIN requested from DSS on Oct 6 — waiting', 'Days changed to Mon / Wed / Fri from Oct 1 (parent\'s new job)'][$i % 3];
            $child->enrollment_note_at = $today->copy()->subDays($i % 20);
        }

        $child->save();

        // The papers on file: the packet and the immunization record on
        // nearly everyone, the medical statement renewing through the year,
        // the photo consent missing on one in five, a consent expiring soon.
        $has = $child->documents()->pluck('kind')->all();
        $put = function (string $kind, ?string $signed, ?string $expires = null) use ($child, $has, $director) {
            if (in_array($kind, $has, true)) {
                return;
            }
            ChildDocument::create([
                'child_id' => $child->id,
                'kind' => $kind,
                'name' => Child::DOCUMENT_KINDS[$kind],
                'signed_on' => $signed,
                'expires_on' => $expires,
                'uploaded_by' => $director?->id,
            ]);
        };
        $enrolled = $child->enrolled_on ?? $today->copy()->subMonths(14);
        $put('enrollment_packet', $enrolled->toDateString());
        if ($i % 6 !== 3) {
            $put('immunization_record', $enrolled->toDateString());
        }
        $signed = $today->copy()->subMonths(($i * 5) % 12);
        $put('medical_statement', $signed->toDateString(), $signed->copy()->addYear()->toDateString());
        if ($i % 5 !== 1) {
            $put('photo_consent', $enrolled->toDateString());
        }
        if ($i % 4 === 0) {
            // One of these ran out last month, so Documents shows "Expired".
            $expires = $i === 16 ? $today->copy()->subDays(21) : $today->copy()->addDays(20 + $i);
            $put('sunscreen_consent', $today->copy()->subMonths(5)->toDateString(), $expires->toDateString());
            if ($i === 16) {
                // Even when an earlier run put it there with a date ahead.
                $child->documents()->where('kind', 'sunscreen_consent')->whereNull('path')->where('expires_on', '>', $today)->update(['expires_on' => $expires->toDateString()]);
            }
        }
        if ($child->alertList() !== []) {
            $put('allergy_care_plan', $enrolled->toDateString());
        }

        // Somebody on the record where there is nobody: a mother who pays
        // and gets the texts, a father who may collect, and — on one in
        // eight — an uncle under a court order. One in twelve is left with no
        // guardian at all, which the list flags.
        if ($child->personLinks()->doesntExist() && $i % 12 !== 10) {
            $surname = $child->last_name;
            $mother = Person::firstOrCreate(['name' => 'Maria '.$surname], ['cell' => '(716) 555-0'.str_pad((string) (100 + $i), 3, '0', STR_PAD_LEFT)]);
            ChildPerson::create(['child_id' => $child->id, 'person_id' => $mother->id, 'relationship' => 'Mother', 'is_guardian' => true, 'can_pickup' => true, 'is_emergency' => $i % 7 !== 6, 'priority' => $i % 7 !== 6 ? 1 : null, 'pays_fees' => true, 'gets_texts' => true]);

            if ($i % 3 !== 2) {
                $father = Person::firstOrCreate(['name' => 'David '.$surname], ['cell' => '(716) 555-0'.str_pad((string) (400 + $i), 3, '0', STR_PAD_LEFT)]);
                ChildPerson::create(['child_id' => $child->id, 'person_id' => $father->id, 'relationship' => 'Father', 'is_guardian' => true, 'can_pickup' => true, 'is_emergency' => true, 'priority' => 2, 'pays_fees' => false, 'gets_texts' => true]);
            }

            if ($i % 8 === 5) {
                $uncle = Person::firstOrCreate(['name' => 'Rob '.$surname], ['cell' => '(716) 555-0'.str_pad((string) (700 + $i), 3, '0', STR_PAD_LEFT)]);
                ChildPerson::create(['child_id' => $child->id, 'person_id' => $uncle->id, 'relationship' => 'Aunt/Uncle', 'can_pickup' => false, 'restriction' => 'Restricted — never release to him (court order)']);
            }
        } else {
            // The two new ticks on adults already on file: the first guardian
            // pays and gets texts, every guardian gets texts.
            $child->personLinks()->where('is_guardian', true)->orderBy('id')->get()->each(function (ChildPerson $link, int $n) {
                if (! $link->pays_fees && ! $link->gets_texts) {
                    $link->forceFill(['pays_fees' => $n === 0, 'gets_texts' => true])->save();
                }
            });
        }

        // Somebody who must not collect, on one record on the roll, so the
        // pick-up summary and the court pill have a case to show.
        if ($i === 21 && ChildPerson::whereNotNull('restriction')->doesntExist()) {
            $uncle = Person::firstOrCreate(['name' => 'Rob '.$child->last_name], ['cell' => '(716) 555-0721']);
            ChildPerson::create(['child_id' => $child->id, 'person_id' => $uncle->id, 'relationship' => 'Aunt/Uncle', 'can_pickup' => false, 'restriction' => 'Restricted — never release to him (court order)']);
        }

        // An office note or two, dated back a little.
        if ($child->notes()->doesntExist() && $i % 3 === 0) {
            ChildNote::create(['child_id' => $child->id, 'user_id' => $director?->id, 'kind' => 'office', 'body' => self::OFFICE_NOTES[$i % 4], 'created_at' => $today->copy()->subDays(1 + $i % 9), 'updated_at' => $today->copy()->subDays(1 + $i % 9)]);
            if ($i % 9 === 0) {
                ChildNote::create(['child_id' => $child->id, 'user_id' => $director?->id, 'kind' => 'health', 'body' => 'Hives on left arm at 10:20, settled with no treatment. Mom called 10:25.', 'created_at' => $today->copy()->subDays(3), 'updated_at' => $today->copy()->subDays(3)]);
            }
        }

        // The first child on the roll carries a term's worth of notes, so
        // "Show older" has something to hide.
        if ($i === 0 && $child->notes()->count() < 9) {
            foreach (range(1, 10) as $n) {
                ChildNote::create(['child_id' => $child->id, 'user_id' => $director?->id, 'kind' => $n % 4 === 0 ? 'health' : 'office', 'body' => self::OFFICE_NOTES[$n % 4].' (week '.$n.')', 'created_at' => $today->copy()->subWeeks($n), 'updated_at' => $today->copy()->subWeeks($n)]);
            }
        }

        // A teacher's word at check-in, on a day this month the child was
        // here, for the records that have none.
        if ($i % 15 === 5 && ! \App\Models\AttendanceComment::where('child_id', $child->id)->exists()) {
            $row = \App\Models\Attendance::where('child_id', $child->id)->whereNotNull('signed_in_at')->where('attendance_date', '>=', $today->copy()->startOfMonth()->toDateString())->orderByDesc('attendance_date')->first();
            $teacher = User::where('role', 'teacher')->where('classroom', $child->classroom)->orderBy('id')->first();
            if ($row && $teacher) {
                [$tag, $body] = self::COMMENTS[intdiv($i, 15) % 4];
                \App\Models\AttendanceComment::create(['attendance_id' => $row->id, 'child_id' => $child->id, 'date' => $row->attendance_date, 'user_id' => $teacher->id, 'author_role' => 'teacher', 'tag' => $tag, 'body' => $body]);
            }
        }

        $this->fillHistory($child, $i, $today);

        // Leaving: one child has given notice with the last day still ahead,
        // so the record shows the amber banner and the roster keeps them.
        if ($i === 11 && ! $child->withdrawn_on && Child::where('status', 'Active')->whereNotNull('withdrawn_on')->doesntExist()) {
            $lastDay = $today->copy()->next(Carbon::FRIDAY)->addWeek();
            $child->forceFill(['withdrawn_on' => $lastDay->toDateString(), 'withdrawal_reason' => 'Changed provider', 'withdrawal_note' => 'Moving to the centre by dad\'s new office', 'could_return' => 'Not sure'])->save();
            $this->event($child, 'withdrawn', 'Withdrawn · Changed provider', 'Last day '.$lastDay->format('M j, Y').' · Moving to the centre by dad\'s new office · Could return: Not sure', $today->copy()->subDays(2));
        }
    }

    /**
     * Enrollment history for a record that has only its status row: the day
     * they were enrolled, and — spread across the roll — a room move, a
     * change of days, a subsidy that came through.
     */
    private function fillHistory(Child $child, int $i, Carbon $today): void
    {
        $has = $child->statusChanges()->where('event', '!=', 'status')->pluck('event')->all();
        $missing = fn (string $event) => ! in_array($event, $has, true);

        $enrolled = $child->enrolled_on ?? $today->copy()->subMonths(14);
        $room = $child->classroom ?: 'Infant';
        if ($missing('enrolled')) {
            $this->event($child, 'enrolled', 'Enrolled · '.$room, 'Enrolment packet received', $enrolled->copy()->subDays(3));
        }

        $at = (int) array_search($room, self::ROOMS, true);
        $before = $at > 0 ? self::ROOMS[$at - 1] : self::ROOMS[1];

        if ($missing('room') && $child->classroom_override_from) {
            $this->event($child, 'room', $before.' → '.$room, 'Moved up early — ready for the bigger room', $child->classroom_override_from->copy());
        } elseif ($missing('room') && $i % 10 === 2) {
            $this->event($child, 'room', $before.' → '.$room, null, $today->copy()->subMonths(2)->subDays($i % 20));
        }

        if ($missing('schedule') && $i % 10 === 6) {
            $this->event($child, 'schedule', 'Schedule · '.$child->scheduleSummary(), 'Parent\'s new job', $today->copy()->subWeeks(3)->subDays($i % 6));
        }

        // DSS numbers land on the even places, so this picks an even one.
        if ($missing('subsidy') && $child->dss_case_no && $i % 14 === 4) {
            $this->event($child, 'subsidy', 'Subsidy · DSS case '.$child->dss_case_no, 'Authorization received', $today->copy()->subMonths(1)->subDays($i % 25));
        }
    }
}
