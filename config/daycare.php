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

];
