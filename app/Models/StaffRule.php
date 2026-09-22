<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One constraint on when, where or with whom a staff member can be scheduled.
 *
 * The vocabulary lives here rather than in the generator because three places
 * need it and they must not disagree: the form that offers the rule types, the
 * validator that accepts them, and the solver that acts on them.
 */
class StaffRule extends Model
{
    /**
     * A rule is either inviolable or a preference.
     *
     * The distinction is the whole point of the table. HARD rules bound the
     * search — the generator would rather leave a room short than break one.
     * SOFT rules it tries to honour and reports on when it cannot, so that
     * "Emily worked Friday again" is a line in the warnings rather than a
     * complaint three weeks later.
     */
    public const PRIORITIES = ['HARD', 'SOFT'];

    public const DAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'ALL'];

    /** Employment types, in the order they appear in the picker. */
    public const EMPLOYMENT = ['LEAD', 'FT', 'FT_SALARY', 'PT', 'SUB'];

    /**
     * Every rule type, and the columns it actually uses.
     *
     * A type absent from a column's list means the form hides that input and
     * the validator rejects it — half-filled rules are how a solver ends up
     * silently ignoring a constraint the director thought it had.
     */
    public const FIELDS = [
        'UNAVAILABLE_DAY' => ['day'],
        'AVAILABLE_WINDOW' => ['day', 'time_1', 'time_2'],
        'AVAILABLE_AFTER' => ['day', 'time_1'],
        'FIXED_SHIFT' => ['day', 'time_1', 'time_2'],
        'FIXED_END' => ['day', 'time_1'],
        'REQUIRED_HOURS' => ['number', 'value_text'],
        'HALF_DAYS' => ['number'],
        'MAX_HOURS' => [],
        'PREFERRED_START' => ['time_1'],
        'PREFERRED_DAY_OFF' => ['day'],
        'PREFERRED_FULL_DAYS' => ['number'],
        'ROOM_PREFERENCE' => ['value_text'],
        'ROOM_FORBIDDEN' => ['value_text'],
        'NO_PAIR' => ['value_text'],
        'NEEDS_SUPERVISION' => [],
        'CAN_OPEN' => [],
    ];

    /**
     * What each rule is called on screen, and which heading it sits under.
     *
     * The constants are what the solver reads and they are not going to
     * change; these are what a director reads. "AVAILABLE_WINDOW" and
     * "FIXED_END" are precise and mean nothing to somebody opening this page
     * to say that Grace leaves at three on Fridays, and a picker of seventeen
     * shouted-out names is one people choose from by guessing.
     *
     * Grouped by the question being answered rather than by whether the rule
     * is hard or soft — that distinction matters to the scheduler and not to
     * the person filling the form in, who is thinking about a day, an amount
     * of time, or a room.
     */
    public const GROUPS = [
        'When they can work' => [
            'UNAVAILABLE_DAY' => 'Cannot work on a day',
            'AVAILABLE_WINDOW' => 'Only between certain hours',
            'AVAILABLE_AFTER' => 'Cannot start before a time',
            'FIXED_SHIFT' => 'Always works set hours',
            'FIXED_END' => 'Must stay until a time',
            'CAN_OPEN' => 'May open the centre',
        ],
        'How much they work' => [
            'REQUIRED_HOURS' => 'Owed a set number of hours',
            'MAX_HOURS' => 'Never more than full time',
            'HALF_DAYS' => 'A number of half days',
            'PREFERRED_FULL_DAYS' => 'Prefers a number of full days',
        ],
        'Rooms and people' => [
            'ROOM_PREFERENCE' => 'Prefers a room',
            'ROOM_FORBIDDEN' => 'Never in a room',
            'NO_PAIR' => 'Never scheduled with someone',
            'NEEDS_SUPERVISION' => 'Never left alone',
        ],
        'What they would rather' => [
            'PREFERRED_START' => 'Prefers to start at a time',
            'PREFERRED_DAY_OFF' => 'Prefers a day off',
        ],
    ];

    /** The plain-English name of one rule type, or the constant if it has none. */
    public static function label(string $type): string
    {
        foreach (self::GROUPS as $options) {
            if (isset($options[$type])) {
                return $options[$type];
            }
        }

        return str_replace('_', ' ', $type);
    }

    /** Rule types whose value_text names another staff member. */
    public const STAFF_VALUED = ['NO_PAIR'];

    /** Rule types whose value_text names a room. */
    public const ROOM_VALUED = ['ROOM_PREFERENCE', 'ROOM_FORBIDDEN'];

    /**
     * Rule types the generator records but does not yet act on.
     *
     * Kept in the vocabulary because the director's intent is worth capturing
     * before the solver can honour it, and flagged in the form because the one
     * thing worse than a missing feature is a rule somebody believes is being
     * enforced. Remove a type from here the moment StaffSchedule reads it.
     */
    public const NOT_YET_ENFORCED = ['MAX_HOURS', 'HALF_DAYS', 'PREFERRED_FULL_DAYS'];

    /** Guidance shown under the form for the types people get wrong. */
    public const HINTS = [
        'NO_PAIR' => 'These two are never scheduled together. The rule is mutual — you only need to add it once.',
        'NEEDS_SUPERVISION' => 'This person must always have at least one other staff member present.',
        'REQUIRED_HOURS' => 'Value is WEEKLY or BIWEEKLY. A biweekly 80 counts as 40 hours in this week.',
        'HALF_DAYS' => 'A half day is a shift of six hours or less.',
        'FIXED_END' => 'Use for designated closers — must stay until 18:00.',
        'CAN_OPEN' => 'Only keyholders. Everyone else starts no earlier than the default opening time in config/daycare.php.',
    ];

    protected $fillable = [
        'user_id', 'rule_type', 'priority', 'day',
        'time_1', 'time_2', 'number', 'value_text', 'source_note',
    ];

    protected $casts = [
        'time_1' => 'integer',
        'time_2' => 'integer',
        'number' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function types(): array
    {
        return array_keys(self::FIELDS);
    }

    /** The columns a given type uses, empty for the flag-only types. */
    public static function fieldsFor(?string $type): array
    {
        return self::FIELDS[$type] ?? [];
    }

    public function isHard(): bool
    {
        return $this->priority === 'HARD';
    }

    /** Does this rule bite on the given weekday? */
    public function appliesOn(string $day): bool
    {
        return $this->day === $day || $this->day === 'ALL';
    }

    /**
     * The rule in a sentence a director can check at a glance.
     *
     * Rules are entered once and then trusted for months, so the list has to
     * read as English — nobody proofreads FIXED_SHIFT/ALL/480/960.
     */
    public function describe(): string
    {
        $day = match (true) {
            blank($this->day) => '',
            $this->day === 'ALL' => 'every day',
            default => 'on '.ucfirst(strtolower($this->day)),
        };

        $from = self::formatTime($this->time_1);
        $to = self::formatTime($this->time_2);
        $number = rtrim(rtrim(number_format((float) $this->number, 1), '0'), '.');

        return trim(match ($this->rule_type) {
            'UNAVAILABLE_DAY' => "Cannot work {$day}",
            'AVAILABLE_WINDOW' => "Only works {$from}–{$to} {$day}",
            'AVAILABLE_AFTER' => "Cannot start before {$from} {$day}",
            'FIXED_SHIFT' => "Must work exactly {$from}–{$to} {$day}",
            'FIXED_END' => "Shift must end at {$from} {$day}",
            'REQUIRED_HOURS' => "Must total {$number} hours ".($this->value_text === 'BIWEEKLY' ? 'every 2 weeks' : 'per week'),
            'HALF_DAYS' => "{$number} half day(s) per week",
            'MAX_HOURS' => 'Give as many hours as possible',
            'PREFERRED_START' => "Prefers starting at {$from}",
            'PREFERRED_DAY_OFF' => 'Prefers '.($this->day ?: '?').' off',
            'PREFERRED_FULL_DAYS' => "Prefers {$number} full days per week",
            'ROOM_PREFERENCE' => "Prefers room: {$this->value_text}",
            'ROOM_FORBIDDEN' => "Never assign to room: {$this->value_text}",
            'NO_PAIR' => "Cannot work with {$this->value_text}",
            'NEEDS_SUPERVISION' => 'Must never be the only staff member present',
            'CAN_OPEN' => 'May open the building at opening time',
            default => $this->rule_type,
        });
    }

    /** "07:30" to 450. Null for anything that is not a time. */
    public static function toMinutes(?string $time): ?int
    {
        if (blank($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $parts)) {
            return null;
        }

        $minutes = ((int) $parts[1] * 60) + (int) $parts[2];

        return $minutes >= 0 && $minutes <= 24 * 60 ? $minutes : null;
    }

    /** 450 to "07:30", for time inputs. */
    public static function toClock(?int $minutes): string
    {
        return $minutes === null
            ? ''
            : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * 450 to "7:30a", 780 to "1p" — the short form the roster bars use.
     *
     * A bar is often only a few dozen pixels wide, so the minutes are dropped
     * on the hour and the meridiem is a single letter. "7a → 3p" fits where
     * "7:00 AM – 3:00 PM" would be clipped to nonsense.
     */
    public static function compactTime(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        $hour = intdiv($minutes, 60);
        $suffix = $hour >= 12 ? 'p' : 'a';
        $hour %= 12;

        return ($hour === 0 ? 12 : $hour)
            .($minutes % 60 ? sprintf(':%02d', $minutes % 60) : '')
            .$suffix;
    }

    /** 450 to "7:30 AM", for reading. */
    public static function formatTime(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        $hour = intdiv($minutes, 60);
        $suffix = $hour >= 12 ? 'PM' : 'AM';
        $hour %= 12;

        return ($hour === 0 ? 12 : $hour).sprintf(':%02d ', $minutes % 60).$suffix;
    }
}
