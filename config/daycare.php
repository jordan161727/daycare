<?php

/**
 * Centre-wide operating facts the staff scheduler works from.
 *
 * These are licensing and business rules rather than data, so they live here
 * where a director's change is one edit and a code review — not a migration.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Opening hours
    |--------------------------------------------------------------------------
    |
    | Minutes past midnight. The scheduler never places a shift outside these,
    | and coverage is only checked between them.
    |
    */

    'open' => 7 * 60,      // 07:00
    'close' => 18 * 60,    // 18:00

    /**
     * The earliest a staff member without the CAN_OPEN rule may start.
     *
     * Someone has to unlock the building, so a non-keyholder starting at
     * opening time would be standing outside it.
     */
    'default_earliest' => 7 * 60,

    /**
     * Where an AM session ends and a PM one begins.
     *
     * School Age children book half days, so the room's child count — and with
     * it the number of staff the ratio demands — steps at this minute.
     */
    'midday' => 12 * 60,

    /** Granularity the coverage check walks the day in, in minutes. */
    'coverage_step' => 30,

    /** Length of a cover shift dropped in to plug a ratio gap, in minutes. */
    'patch_length' => 180,

    /** No shift shorter than this is worth scheduling, in minutes. */
    'minimum_shift' => 4 * 60,

    /** Weekdays the centre operates. Keyed the way rules store a day. */
    'days' => ['MON', 'TUE', 'WED', 'THU', 'FRI'],

    /*
    |--------------------------------------------------------------------------
    | Staff-to-child ratios
    |--------------------------------------------------------------------------
    |
    | One staff member per this many children. Rooms come from
    | App\Services\ClassroomAssignment::rooms(), and every one of them needs an
    | entry — a room missing here is treated as needing no cover at all, which
    | would hide a real shortfall rather than report it.
    |
    | Check these against your state licensing table before going live.
    |
    */

    'ratios' => [
        'Infant' => 4,
        'Transition' => 5,
        'Toddler' => 6,
        'PreK' => 10,
        'UPK-4' => 10,
        'School Age' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default weekly hours by employment type
    |--------------------------------------------------------------------------
    |
    | Used only when a staff member has no REQUIRED_HOURS rule of their own.
    |
    */

    'weekly_hours' => [
        'LEAD' => 40,
        'FT' => 40,
        'FT_SALARY' => 40,
        'PT' => 24,
        'SUB' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payroll
    |--------------------------------------------------------------------------
    */

    'payroll' => [
        /** Where uploaded payroll PDFs are kept. Private disk — never public. */
        'disk' => 'local',
        'directory' => 'payroll',

        /** Batches older than this are pruned by payroll:prune. */
        'retain_days' => 90,

        'default_subject' => 'Your payslip{period_dash}',

        'default_body' => <<<'TXT'
            Hi {first_name},

            Attached is your payslip{period_for}.

            This document is confidential and intended only for you.
            TXT,
    ],

    /*
    |--------------------------------------------------------------------------
    | Timesheets
    |--------------------------------------------------------------------------
    |
    | What the centre hands to payroll: hours per person per pay period, built
    | from the schedule and then corrected to what actually happened.
    |
    */

    'timesheet' => [
        /**
         * Semi-monthly: the 1st to the 15th, and the 16th to the end of the
         * month. Twenty-four periods a year, and none of them line up with a
         * week — which is why overtime is worked out per week and the period
         * only ever collects the days that fall inside it.
         */
        'period' => 'semi-monthly',

        /**
         * Hours in a workweek before overtime starts, per the FLSA. The
         * workweek is Monday to Sunday here, matching the centre's schedule.
         *
         * Paid leave is not hours worked and never counts towards this — an
         * employee on PTO Monday who then works 40 hours is not owed overtime.
         */
        'overtime_after' => 40,

        /**
         * Why a day was paid but not worked. UNPAID is here so an absence can
         * be recorded as a decision rather than left as a blank nobody can
         * tell apart from a day nobody has filled in yet.
         */
        'leave_codes' => [
            'PTO' => 'Paid time off',
            'SICK' => 'Sick leave',
            'HOLIDAY' => 'Centre holiday',
            'UNPAID' => 'Unpaid absence',
        ],

        /** Leave codes that are paid. UNPAID is the one that is not. */
        'paid_leave_codes' => ['PTO', 'SICK', 'HOLIDAY'],

        /** A leave day with no length of its own is worth this many hours. */
        'default_leave_hours' => 8,

        /*
        |----------------------------------------------------------------------
        | The time clock
        |----------------------------------------------------------------------
        |
        | Teachers punch in, out, and either side of lunch and breaks. Each
        | day rolls up into the timesheet entry above, which is where it meets
        | the roster, the corrections and the overtime split.
        |
        */

        'clock' => [
            /**
             * Turn the clock off and the centre is back to correcting the
             * roster by hand, which is how this worked before the clock
             * existed and is still a reasonable way to run a small site. The
             * punches already recorded are kept and still shown.
             */
            'enabled' => true,

            /**
             * Minutes of a rest break that are paid, per break.
             *
             * The FLSA treats short rest breaks as hours worked and a genuine
             * meal period as not, which is the only reason lunch and break are
             * separate punches at all. A break that runs past this is paid to
             * the cap and unpaid beyond it — at forty minutes it has stopped
             * being a rest break.
             *
             * Lunch is never paid, however short it is.
             */
            'paid_break_cap' => 20,

            /**
             * A worked day longer than this is flagged rather than paid
             * quietly. It is not a limit on what somebody may work — it is the
             * length at which a day is more likely to be a missed punch than a
             * shift, and worth a supervisor's eyes either way.
             */
            'max_day_hours' => 14,
        ],
    ],

];
