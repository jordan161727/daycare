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
    | Company
    |--------------------------------------------------------------------------
    |
    | The centre's name, as it reads on the sign-in page, the kiosk and every
    | printed sheet. This is the default; a director can override it under
    | Settings, and clearing that box comes back here.
    |
    | Not config('app.name'), which is the Laravel application's name and is
    | used for mail envelopes and the like. A centre renaming itself should not
    | have to reason about what else APP_NAME is wired into.
    |
    */

    'company' => [
        'name' => env('DAYCARE_COMPANY_NAME', 'Little Angels Day Care Center'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Province
    |--------------------------------------------------------------------------
    |
    | Two-letter code, used by the Canadian holiday seeder to decide which
    | statutory days the centre closes for. Blank seeds the federal list only,
    | which every jurisdiction observes; a code adds that province's own — the
    | February family day, the August civic holiday, Quebec's Fête nationale.
    |
    | AB BC MB NB NL NS NT NU ON PE QC SK YT
    |
    */

    'province' => env('DAYCARE_PROVINCE', ''),

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
     * How far apart the times offered when a rule asks for one.
     *
     * Half hours: eleven hours of opening at a quarter of an hour came to
     * forty-five options, which is a scrolling list to hunt through for the
     * one o'clock nearly everybody wants. Twenty-three fit on a screen.
     *
     * Drop it to 15 for a centre that genuinely schedules on the quarter.
     */
    'time_step' => 30,

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

    /**
     * Whether the attendance sheet offers the Schedule view.
     *
     * Off for this version. The view is built and tested — it is how a week's
     * expected days are ticked — but the centre plans its weeks by opening
     * them and letting the previous week copy forward, so the button was a
     * second way in that nobody was asked to use. It waits here rather than
     * being taken out, and bringing it back is this one line.
     *
     * While it is off, a week is still built by "Open this week"; nothing
     * already ticked is lost or hidden.
     */
    'schedule_view' => false,

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
             *
             * Off for this version. The clock is built and tested — it is the
             * centre that is not ready to punch one — so it waits here rather
             * than being taken out, and the next version is this one line.
             * While it is off, a teacher signing in is not clocked in and
             * lands on the dashboard like everybody else.
             */
            'enabled' => false,

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

    /*
    |--------------------------------------------------------------------------
    | Leave
    |--------------------------------------------------------------------------
    |
    | Sick and vacation time: what it is earned at, what it is capped at, and
    | how a day of it is counted. A balance is kept as a ledger rather than as a
    | number, so every hour on somebody's card can be traced to the period that
    | earned it or the request that spent it.
    |
    */

    'leave' => [

        /** The kinds of leave the centre keeps a balance for. */
        'types' => [
            'VACATION' => 'Vacation',
            'SICK' => 'Sick leave',
        ],

        /**
         * How each type reaches payroll.
         *
         * Leave is requested and approved in this vocabulary and paid in the
         * timesheet's one — see daycare.timesheet.leave_codes. Keeping the map
         * here is what stops an approved vacation day arriving at payroll as a
         * code nobody recognises.
         */
        'timesheet_codes' => [
            'VACATION' => 'PTO',
            'SICK' => 'SICK',
        ],

        'accrual' => [

            /**
             * One hour of leave earned per this many hours actually worked.
             *
             * Worked, not paid: leave does not earn leave, and neither does a
             * centre holiday. The sick figure follows the common statutory
             * "one hour in thirty" — check it against your own state's paid
             * sick leave law before going live, because that one is not a
             * house rule.
             *
             * A type left out here is not accrued by the hour at all, and can
             * only arrive by the flat rate below or a director's adjustment.
             */
            'per_hours_worked' => [
                'VACATION' => 40,
                'SICK' => 30,
            ],

            /**
             * Flat hours per pay period, by employment type.
             *
             * For staff whose hours do not vary, where accruing off the clock
             * would hand a salaried lead a different balance every fortnight
             * for no reason anybody could explain to them. Takes precedence
             * over the hourly rate above for the types listed.
             */
            'per_period' => [
                'FT_SALARY' => ['VACATION' => 3.33, 'SICK' => 1.34],
            ],
        ],

        /**
         * The most of each type anybody may hold at once, in hours.
         *
         * Accrual stops at the cap rather than quietly overshooting it, and the
         * run reports whose balance was capped — somebody sitting on three
         * weeks of unused vacation is a fact the director should see, not one
         * the ledger absorbs.
         */
        'cap' => [
            'VACATION' => 120,
            'SICK' => 56,
        ],

        /** A day of leave, in hours, when the request does not say otherwise. */
        'day_hours' => 8,

        /**
         * How far back a request may reach, in days.
         *
         * Vacation is asked for before it is taken, so it may not start in the
         * past. Sickness is not planned, so a sick day is routinely filed the
         * morning after — but not six months after, which is a payroll
         * correction rather than a leave request.
         */
        'backdate_days' => [
            'VACATION' => 0,
            'SICK' => 30,
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | Door kiosk
    |--------------------------------------------------------------------------
    |
    | The check-in screen at the front door. It is the one route in this app
    | that answers a signed-out request, so it is off unless a centre asks for
    | it: with `enabled` false the URL is a 404, not a locked door.
    |
    | `device` is written onto every punch, so a centre with a screen at each
    | entrance can tell which one a child came through.
    |
    */

    'kiosk' => [
        'enabled' => env('DAYCARE_KIOSK', false),
        'device' => env('DAYCARE_KIOSK_DEVICE', 'Front door'),
    ],
];
