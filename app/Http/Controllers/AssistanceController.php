<?php

namespace App\Http\Controllers;

/**
 * The two public pages about help paying for day care.
 *
 * The only part of this app a parent sees without a PIN or a password. There is
 * nothing here to sign in to and nothing read out of the database — it is the
 * centre's answer to a question asked at the front desk every week, written
 * down once so the answer is the same every time.
 *
 * The income limits live here rather than in the Blade file because they expire:
 * New York State reissues them every June 1, and a table hard-coded in markup is
 * a table nobody remembers to update. Keeping them beside a dated constant at
 * least puts the expiry in front of whoever opens this file next.
 */
class AssistanceController extends Controller
{
    /**
     * Yearly household income limits from the NYS OCFS CCAP handout.
     *
     * Update both halves together when the state publishes the next set — the
     * page prints the date, so a stale table is a visibly stale table.
     */
    private const INCOME_LIMITS = [
        'effective' => 'June 1, 2026–May 31, 2027',
        'rows' => [
            1 => 60576.10,
            2 => 79214.90,
            3 => 97853.70,
            4 => 116492.50,
            5 => 135131.30,
            6 => 153770.10,
        ],
    ];

    /** The chooser: applying for the first time, or renewing. */
    public function index()
    {
        return view('assistance.index');
    }

    /** The four steps, for a parent who has never applied. */
    public function apply()
    {
        return view('assistance.apply', ['limits' => self::INCOME_LIMITS]);
    }
}
