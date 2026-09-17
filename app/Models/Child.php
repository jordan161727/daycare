<?php

namespace App\Models;

use App\Services\ClassroomAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

class Child extends Model
{
    /** Rooms signed in by half day rather than one full-day stamp. */
    public const SESSION_ROOMS = ['School Age'];

    /**
     * What the record may say about a child, and nothing else.
     *
     * Blank is the third state and the default one: plenty of records predate
     * the question being asked. Only the drawn avatar reads this.
     */
    public const GENDERS = ['Girl', 'Boy'];

    /** The earliest drop-off and the latest pick-up the centre will take. */
    public const DAY_OPENS_AT = '07:00';

    public const DAY_CLOSES_AT = '20:00';

    protected $casts = [
        'dob' => 'date',
        'birth_date' => 'date',
        'enrolled_on' => 'date:Y-m-d',
        'withdrawn_on' => 'date:Y-m-d',
        'classroom_override_from' => 'date:Y-m-d',
        // Float rather than decimal: the projection does arithmetic with this on
        // every read, and a decimal cast hands back a string.
        'expected_hours_per_week' => 'float',
        'schedule_days' => 'array',
        'alerts' => 'array',
        'mother_ssn' => 'encrypted',
        'father_ssn' => 'encrypted',
    ];

     protected $fillable = [
        'lan',
        // The two numbers the state knows a subsidised child by: the family's
        // case and this child's own CIN. See the migration that adds them.
        'dss_case_no', 'dss_cin',
        'child_name', 'nickname', 'address', 'city', 'zip', 'telephone', 'birth_date',
        'status',
        'enrolled_on',
        'withdrawn_on',
        'expected_hours_per_week',
        'drop_off_time',
        'pick_up_time',
        'schedule_days',
        'first_name',
        'last_name',
        'photo_path',
        'gender',
        'dob',
        'age',
        'classroom',
        'classroom_override',
        'classroom_override_from',
        'mother_name', 'mother_address', 'mother_home_phone', 'mother_employer', 'mother_work_phone', 'mother_fax', 'mother_cell', 'mother_email', 'mother_title', 'mother_ssn',
        'father_name', 'father_address', 'father_home_phone', 'father_employer', 'father_work_phone', 'father_fax', 'father_cell', 'father_email', 'father_title', 'father_ssn',
        'email_address', 'parents_status', 'responsible_for_payment', 'emergency_contact', 'secondary_emergency_contact', 'emergency_telephone', 'emergency_relationship', 'emergency_license_number',
        'pickup_1_name', 'pickup_1_address', 'pickup_1_telephone', 'pickup_1_alternate', 'pickup_1_relationship', 'pickup_1_license_number',
        'pickup_2_name', 'pickup_2_address', 'pickup_2_telephone', 'pickup_2_alternate', 'pickup_2_relationship', 'pickup_2_license_number',
        'pickup_3_name', 'pickup_3_address', 'pickup_3_telephone', 'pickup_3_alternate', 'pickup_3_relationship', 'pickup_3_license_number', 'other_notes', 'important_notes', 'alerts',
    ];

    /**
     * `classroom` is the worked-out answer, never something typed in — it is what
     * reports group by, what teacher visibility filters on and what the room
     * counts add up, all of them straight from SQL. Recomputing it on every save
     * is what keeps those reads honest.
     */
    protected static function booted(): void
    {
        static::saving(function (self $child) {
            // A room written straight onto a record the age rule cannot place —
            // no date of birth, or one outside every band — is somebody making
            // the call by hand. That is an override, so record it as one rather
            // than discarding it and leaving the child in no room at all.
            if ($child->isDirty('classroom')
                && filled($child->classroom)
                && blank($child->classroom_override)
                && $child->automaticClassroom() === null) {
                $child->classroom_override = $child->classroom;
            }

            $child->classroom = $child->classroomOn();
        });
    }

    public function getFullNameAttribute()
    {
        return "{$this->last_name}, {$this->first_name}";
    }

    /**
     * This child's name written the way the reader asked for it.
     *
     * The office works from surnames because that is how the paper file is
     * ordered; the room works from first names because that is what a child
     * answers to. Both read the same roster, so the shape is the reader's
     * preference and every screen asks this rather than concatenating its own
     * two columns — which is how one page ends up disagreeing with the next.
     *
     * The sort is deliberately not affected. Ordering the roll by surname is
     * how a roll is found; that is a different question from how a name reads,
     * and tying the two would reorder the whole sheet on a display setting.
     *
     * @param  string|null  $format  a User::NAME_FORMATS key; the signed-in
     *                               reader's own preference when omitted
     */
    public function displayName(?string $format = null): string
    {
        $format ??= auth()->user()?->nameFormat() ?? User::NAME_FORMAT_DEFAULT;

        return $format === 'last_first'
            ? trim($this->last_name.', '.$this->first_name, ' ,')
            : trim($this->first_name.' '.$this->last_name);
    }

    /**
     * The date of birth, from whichever column holds it. `dob` came first and
     * `birth_date` arrived with the enrolment form; records exist with either.
     */
    public function birthDate(): ?Carbon
    {
        return $this->birth_date ?? $this->dob;
    }

    /**
     * The date of birth written the way every screen reads it: 2022/12/15.
     *
     * Year first, so a column of them sorts by eye the way it sorts by click,
     * and so 3/4 is never one date to one reader and another to the next. Both
     * halves padded to two digits — unpadded, 2023/6/15 and 2023/12/5 are
     * different widths and the slashes stop lining up down a column of sixty.
     * Formatted on every read rather than stored, so it cannot drift out of
     * step with the date it comes from.
     */
    public static function ageLabelFor(?CarbonInterface $birthDate): ?string
    {
        return $birthDate?->format('Y/m/d');
    }

    /** This child's date of birth as the roster shows it, or null when none is on file. */
    public function ageLabel(): ?string
    {
        return static::ageLabelFor($this->birthDate());
    }

    /**
     * How old the child is: "3y 2m".
     *
     * One shape everywhere — the roster's Age column, the attendance sheet's,
     * and anywhere else a child's age is shown. Both halves are always said,
     * so a ten-month-old reads "0y 10m" rather than "10 months": down a column
     * of sixty children the years sit under the years and the months under the
     * months, and two ages are compared by looking rather than by reading. The
     * long form was also three times the width, in the narrowest column on the
     * densest screen in the app.
     *
     * The exception is a child not yet a month old, who would otherwise be
     * "0y 0m" — an infant room takes babies at six weeks, and "0y 0m" for a
     * fortnight-old is not an age at all. Those read "12d", in the same compact
     * shape, until there is a month to report.
     *
     * Worked out on every read and never stored, because it is different next
     * month. Days are dropped once there are months: they matter to a parent
     * and not to a roster, and "3y 2m 14d" is three facts where one was wanted.
     */
    public function ageInWords(?Carbon $asOf = null): ?string
    {
        $birthDate = $this->birthDate();

        if ($birthDate === null) {
            return null;
        }

        $asOf = $asOf ? $asOf->copy()->startOfDay() : Carbon::today();
        $birthDate = $birthDate->copy()->startOfDay();

        // A date of birth in the future is somebody's typo, not a negative age.
        if ($birthDate->greaterThan($asOf)) {
            return null;
        }

        $age = $birthDate->diff($asOf);

        if ($age->y === 0 && $age->m === 0) {
            return $age->d.'d';
        }

        return $age->y.'y '.$age->m.'m';
    }

    /**
     * Where the browser can fetch this child's photograph, or null when there
     * is none to fetch.
     *
     * A route rather than a URL on a public disk. The file is a photograph of a
     * minor, so every request for it goes through the same check the child's
     * record does — see ChildController::photo. The existence check keeps a row
     * pointing at a file somebody has since removed from showing as a broken
     * image on every page the child appears on.
     */
    public function photoUrl(): ?string
    {
        if (blank($this->photo_path) || ! Storage::disk('local')->exists($this->photo_path)) {
            return null;
        }

        return route('children.photo', $this);
    }

    /** The initial the avatar falls back to while there is no photograph. */
    public function initial(): string
    {
        return strtoupper(substr($this->first_name ?? '?', 0, 1));
    }

    /**
     * The five days a week can be registered for.
     *
     * Monday to Friday only: the centre does not open at the weekend, so a
     * sixth key would be a box nobody could ever tick. Keyed by ISO weekday so
     * the number stored is the number Carbon::dayOfWeekIso hands back and
     * nothing has to translate between the two.
     */
    /**
     * What a child can be on the roll.
     *
     * Pending is a child whose paperwork is in and whose place is agreed but
     * who has not started. They are on the roll, so they can be found and
     * their record filled in, and they are not Active, so they are not counted
     * as somebody the rooms are staffed for or looked for at sign-in.
     */
    public const STATUSES = ['Active', 'Pending', 'Inactive'];

    /**
     * The kinds of alert a record can carry, and how each one reads.
     *
     * The kind is what gives the chip its colour, so a court order is noticed
     * without being read — which is the whole point of putting these on the
     * roll rather than leaving them in the paragraph below them.
     *
     * The example is what the empty field offers as the shape of an answer,
     * so it has to belong to the kind: a court order prompted with a banana
     * teaches the wrong thing about both.
     *
     * Colours are the app's own and mean what they mean everywhere else here:
     * rose is a rule that must not be broken, amber is a thing to watch, sky
     * is a matter of fact, slate is a remark.
     */
    public const ALERT_TYPES = [
        'court' => ['label' => 'Court Order', 'example' => 'No pickup by Jordan Key', 'classes' => 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200'],
        'allergy' => ['label' => 'Allergy', 'example' => 'Banana — swells, no epi-pen', 'classes' => 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-100'],
        'medical' => ['label' => 'Medical', 'example' => 'Inhaler in office', 'classes' => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-100'],
        'note' => ['label' => 'Note', 'example' => 'Leaves early on Tuesdays', 'classes' => 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300'],
    ];

    /**
     * The alerts on this record, as the page wants to draw them.
     *
     * Rows with no words in them are dropped rather than shown empty: the form
     * posts a blank row whenever somebody opens one and changes their mind,
     * and a chip reading "Allergy:" with nothing after it is worse than no
     * chip, because it says a question was answered when it was not.
     *
     * A kind that is not one of ours — a record hand-edited, or a type retired
     * later — falls back to Note rather than disappearing. The words are the
     * part that matters; the colour is how they are found.
     *
     * @return array<int, array{type: string, label: string, classes: string, text: string}>
     */
    public function alertList(): array
    {
        $out = [];

        foreach ($this->alerts ?? [] as $alert) {
            $text = trim((string) ($alert['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $type = (string) ($alert['type'] ?? 'note');
            $known = self::ALERT_TYPES[$type] ?? self::ALERT_TYPES['note'];

            $out[] = [
                'type' => isset(self::ALERT_TYPES[$type]) ? $type : 'note',
                'label' => $known['label'],
                'classes' => $known['classes'],
                'text' => $text,
            ];
        }

        return $out;
    }

    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];

    /**
     * The days this child is registered to attend, or null when nobody has said.
     *
     * Null and [] are different answers and stay that way: null is every record
     * that predates the question, and a week opened for such a child starts
     * blank exactly as it always did. [] is somebody saying "no days", which is
     * a real statement about a child on the roll who is not currently coming.
     *
     * @return array<int, int>|null ISO weekdays, ascending
     */
    public function scheduleDays(): ?array
    {
        if (! is_array($this->schedule_days)) {
            return null;
        }

        $days = array_values(array_unique(array_filter(
            array_map('intval', $this->schedule_days),
            fn (int $day) => array_key_exists($day, self::WEEKDAYS)
        )));

        sort($days);

        return $days;
    }

    /**
     * Whether the standing arrangement puts this child here on a given date.
     *
     * Only the registered pattern is consulted — not the week's ticks, not the
     * closures. A week is the authority on itself; this answers what a week
     * should start from when there is nothing to copy forward.
     */
    public function attendsOn(string|CarbonInterface $date): bool
    {
        $days = $this->scheduleDays();

        if ($days === null) {
            return false;
        }

        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return in_array($date->dayOfWeekIso, $days, true);
    }

    /**
     * The pattern as a phrase — "Mon, Wed, Thu, Fri" — for the screens that
     * read a child's arrangement rather than set it.
     *
     * The full week gets its own name. "Mon, Tue, Wed, Thu, Fri" is five words
     * for the commonest arrangement there is, and down a column of sixty
     * children it is the one that should take the least reading.
     */
    public function scheduleDaysLabel(): ?string
    {
        $days = $this->scheduleDays();

        if ($days === null) {
            return null;
        }

        if ($days === []) {
            return 'No days';
        }

        if (count($days) === count(self::WEEKDAYS)) {
            return 'Every day';
        }

        return implode(', ', array_map(fn (int $day) => self::WEEKDAYS[$day], $days));
    }

    /**
     * The contracted day as one line — "7:00 AM – 5:30 PM" — or null until both
     * ends have been agreed. This is a different fact from the schedule boxes on
     * the attendance page: those say which days a child comes, these say the
     * hours of the day they are here.
     */
    public function scheduleLabel(): ?string
    {
        if (blank($this->drop_off_time) || blank($this->pick_up_time)) {
            return null;
        }

        return static::timeLabel($this->drop_off_time).' – '.static::timeLabel($this->pick_up_time);
    }

    /**
     * The contracted day as the paper register writes it: "730-430".
     *
     * No colon and no meridiem. A daycare register has no morning pick-ups and
     * no midnight drop-offs, so the meridiem says nothing a reader did not
     * already know, and the name column on the printed sheet is the tightest
     * thing on the page — two characters a row is a column's worth over a
     * roll of sixty.
     *
     * Null until both ends are agreed, like scheduleLabel(): half a range is
     * worse than none, because "730-" reads as a time that was cut off.
     */
    public function hoursCompact(): ?string
    {
        if (blank($this->drop_off_time) || blank($this->pick_up_time)) {
            return null;
        }

        $strip = fn (string $time) => Carbon::parse($time)->format('gi');

        return $strip($this->drop_off_time).'-'.$strip($this->pick_up_time);
    }

    /**
     * A clock time at sheet size: "7:10a", "12:28p".
     *
     * The sign-in grid is sixty rows by five columns and every filled cell
     * carries one of these, so the four characters "7:00 AM" costs over the
     * compact form are four characters times three hundred cells. The meridiem
     * is one lowercase letter because at this size a full "AM" reads as part of
     * the number rather than as a suffix to it.
     *
     * timeLabel() stays the form for everywhere a time is read in prose — a
     * child's record, a room's hours — where the width is free and "7:00 AM" is
     * simply how it is said.
     */
    public static function timeShort(\DateTimeInterface|string|null $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        $time = $time instanceof \DateTimeInterface ? Carbon::instance($time) : Carbon::parse($time);

        return $time->format('g:i').strtolower($time->format('A'))[0];
    }

    /**
     * One stored time as it is read aloud: "7:00 AM".
     *
     * MySQL hands these back as H:i:s and SQLite as whatever was written, so
     * they are parsed rather than printed straight out of the column.
     */
    public static function timeLabel(?string $time): ?string
    {
        return blank($time) ? null : Carbon::parse($time)->format('g:i A');
    }

    /** A stored time as <input type="time"> wants it, which is H:i and nothing else. */
    public static function timeInputValue(?string $time): ?string
    {
        return blank($time) ? null : Carbon::parse($time)->format('H:i');
    }

    /** The room the age bands put this child in, ignoring any override. */
    public function automaticClassroom(?Carbon $asOf = null): ?string
    {
        return ClassroomAssignment::automaticFor($this->birthDate(), $asOf);
    }

    /**
     * Whether the director's choice is in force on a date.
     *
     * A null start date means it always was — that is how rooms predating the
     * age rule were carried over.
     */
    public function hasClassroomOverrideOn(?Carbon $asOf = null): bool
    {
        if (blank($this->classroom_override)) {
            return false;
        }

        if (! $this->classroom_override_from) {
            return true;
        }

        return ($asOf ? $asOf->copy() : Carbon::today())->startOfDay()
            ->gte($this->classroom_override_from->copy()->startOfDay());
    }

    /**
     * The room this child is actually in on a date: the override if one is in
     * force, otherwise the age bands, otherwise nothing.
     *
     * Everything that counts heads or checks access goes through here, so an
     * overridden child is counted against the room they are really in — which is
     * the room whose ratio has to be staffed.
     */
    public function classroomOn(?Carbon $asOf = null): ?string
    {
        return $this->hasClassroomOverrideOn($asOf)
            ? $this->classroom_override
            : $this->automaticClassroom($asOf);
    }

    /**
     * An override that has stopped doing anything.
     *
     * Overrides exist to move a child up early, so they are ahead of the child's
     * age by design. Once the age catches up the override is holding the child
     * in a room they may have grown out of — it is not undone automatically,
     * because a decision the director made is not something to quietly reverse,
     * but the sheet flags it so it can be cleared.
     */
    public function classroomOverrideIsStale(?Carbon $asOf = null): bool
    {
        if (! $this->hasClassroomOverrideOn($asOf)) {
            return false;
        }

        $automatic = ClassroomAssignment::rankOf($this->automaticClassroom($asOf));
        $override = ClassroomAssignment::rankOf($this->classroom_override);

        return $automatic !== null && $override !== null && $automatic >= $override;
    }

    public function scheduleSlots()
    {
        return $this->hasMany(ScheduleSlot::class);
    }

    /** The sessions this child's day splits into. */

    /**
     * The adults on this child's record. Not users — see Guardian.
     *
     * can_collect on the pivot is the one that matters at the door: being told
     * about a child and being allowed to take them home are different
     * permissions, and only the second gets past the kiosk.
     */
    public function guardians()
    {
        return $this->belongsToMany(Guardian::class)->withPivot('can_collect')->withTimestamps();
    }

    public function attendancePunches()
    {
        return $this->hasMany(ChildAttendancePunch::class);
    }

    public function sessions(): array
    {
        return in_array($this->classroom, self::SESSION_ROOMS, true) ? ['AM', 'PM'] : ['FULL'];
    }

    /**
     * Everybody the register should list for one week, leavers included.
     *
     * "Active" alone answers who is here now, which is the wrong question for
     * any week but this one: a child marked Inactive on Friday would disappear
     * from the whole of the year they attended.
     *
     * Three ways onto a week, and a child needs only one of them:
     *
     *  - they are Active, which is the roll as it stands;
     *  - they signed in that week, whatever the record says now;
     *  - they were withdrawn on or after the Monday and had started by the
     *    Friday, which is the span between their first day and their last.
     *
     * Whether each of their five boxes is offered is still isEnrolledOn's
     * business. This decides whose row is on the page; that decides which days
     * in the row are theirs.
     */
    public function scopeOnRollDuring($query, string $weekStart, string $weekEnd)
    {
        return $query->where(function ($outer) use ($weekStart, $weekEnd) {
            $outer->where('status', 'Active')
                ->orWhereHas('attendances', fn ($a) => $a->whereBetween('attendance_date', [$weekStart, $weekEnd]))
                ->orWhere(function ($left) use ($weekStart, $weekEnd) {
                    $left->whereNotNull('withdrawn_on')
                        ->whereDate('withdrawn_on', '>=', $weekStart)
                        ->where(fn ($started) => $started->whereNull('enrolled_on')->orWhereDate('enrolled_on', '<=', $weekEnd));
                });
        });
    }

    /**
     * Whether the child is on the roster on a given day. Null dates mean open-ended,
     * which is how every record behaved before enrolment dates existed.
     */
    public function isEnrolledOn(string $date): bool
    {
        if ($this->enrolled_on && $date < $this->enrolled_on->toDateString()) {
            return false;
        }

        return ! ($this->withdrawn_on && $date > $this->withdrawn_on->toDateString());
    }

    /**
     * Children are addressed by their LAN in a URL, not by their row id.
     *
     * The id means nothing off this screen: it is on no paper the centre keeps,
     * and the same child has a different one in every copy of this database.
     * The LAN is what the cabinet, the parent letter and the staff all use, so
     * /children/10063 is a link that can be read out and checked against a file.
     *
     * The route constraint is whereNumber('child'), which is what keeps
     * /children/create from being swallowed by /children/{child}. A LAN is
     * digits, so that still holds.
     */
    public function getRouteKeyName(): string
    {
        return 'lan';
    }

    /** The first LAN the centre issues. Five digits, as the roll is kept. */
    public const LAN_STARTS_AT = 10001;

    /**
     * The next LAN in sequence: one past the highest numeric LAN on file.
     *
     * Floored at the start of the range rather than counting from whatever is
     * there, so a centre whose oldest records are four digits — or whose roll
     * is empty — still issues five. Non-numeric LANs from before the sequence
     * existed have no place in it and are ignored.
     */
    public static function nextLan(): string
    {
        $highest = static::query()
            ->pluck('lan')
            ->filter(fn ($lan) => ctype_digit((string) $lan))
            ->map(fn ($lan) => (int) $lan)
            ->max();

        return (string) max(self::LAN_STARTS_AT, ($highest ?? 0) + 1);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    /** Limit records to the classroom assigned to a teacher. */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $classrooms = $user->assignedClassrooms();

        if (empty($classrooms)) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('classroom', $classrooms);
    }
}
