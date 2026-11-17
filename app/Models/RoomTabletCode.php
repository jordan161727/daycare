<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The code behind a room tablet's gear (HANDOFF §5.3): one per room, and
 * each code one room's, by the table's unique indexes (QA K4, 16 Nov 2026).
 * Set on Settings → Rooms & hours; read through App\Services\CentreSettings.
 */
class RoomTabletCode extends Model
{
    protected $fillable = ['room', 'code'];

    /** Give a room its code, or take it away with a blank one. */
    public static function put(string $room, ?string $code): void
    {
        if (blank($code)) {
            static::where('room', $room)->delete();

            return;
        }

        static::updateOrCreate(['room' => $room], ['code' => (string) $code]);
    }

    /** room => code for every room that has one. */
    public static function codes(): array
    {
        // Guarded like Setting::get: the Rooms & hours page has to render on
        // a database the migration has not reached yet.
        return rescue(fn () => Schema::hasTable('room_tablet_codes') ? static::pluck('code', 'room')->all() : [], [], false);
    }
}
