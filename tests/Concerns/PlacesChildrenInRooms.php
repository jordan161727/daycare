<?php

namespace Tests\Concerns;

use Illuminate\Support\Carbon;

/**
 * A room follows the child's age, so a fixture that asks for School Age has to
 * be a child old enough for School Age. One hardcoded date of birth would put
 * the whole roster in the same room whatever the test asked for.
 */
trait PlacesChildrenInRooms
{
    /** Ages that sit well inside each band, clear of both boundaries. */
    private const AGE_IN_ROOM_MONTHS = [
        'Infant' => 6,
        'Transition' => 21,
        'Toddler' => 30,
        'PreK' => 42,
        'UPK-4' => 54,
        'School Age' => 96,
    ];

    /** A date of birth that lands a child squarely in the given room. */
    protected function dobForRoom(string $room): string
    {
        return Carbon::today()
            ->subMonths(self::AGE_IN_ROOM_MONTHS[$room] ?? self::AGE_IN_ROOM_MONTHS['Toddler'])
            ->toDateString();
    }
}
