<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The hours one room runs.
 *
 * The standing arrangement, as against StaffShift, which is one week's solved
 * roster. A child's record reads this — a child in Infant is in Infant's hours
 * — so it answers "what time does this class run" without needing a staff week
 * to have been generated at all.
 *
 * It does not say who is in the room. That is the roster's answer, it changes
 * from week to week, and a room's hours outlive any one teacher holding it.
 */
class RoomSchedule extends Model
{
    protected $fillable = ['room', 'opens_at', 'closes_at'];

    /** Whether anything has actually been set on this room. */
    public function isSet(): bool
    {
        return filled($this->opens_at) && filled($this->closes_at);
    }

    /** The room's day — "8:00 AM – 5:00 PM" — or null until both ends are set. */
    public function hoursLabel(): ?string
    {
        if (blank($this->opens_at) || blank($this->closes_at)) {
            return null;
        }

        return Child::timeLabel($this->opens_at).' – '.Child::timeLabel($this->closes_at);
    }

    /** Every room's hours, keyed by room name, for reading against children. */
    public static function byRoom(): \Illuminate\Support\Collection
    {
        return static::all()->keyBy('room');
    }
}
