<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which pushes a parent wants. No row means everything on — the defaults a
 * parent had before this table existed.
 */
class ParentNotificationPref extends Model
{
    public const KEYS = ['messages', 'announcements', 'daily', 'photos'];

    protected $primaryKey = 'person_id';

    public $incrementing = false;

    protected $fillable = ['person_id', ...self::KEYS];

    protected $casts = [
        'messages' => 'boolean',
        'announcements' => 'boolean',
        'daily' => 'boolean',
        'photos' => 'boolean',
    ];

    /** @return array<string, bool> every key, with the defaults filled in */
    public static function for(int $personId): array
    {
        $row = static::find($personId);

        return collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => $row ? (bool) $row->{$k} : true])->all();
    }

    /**
     * The people in the list who have not switched this kind of push off.
     *
     * @param  list<int>  $personIds
     * @return list<int>
     */
    public static function wanting(array $personIds, string $key): array
    {
        $off = static::whereIn('person_id', $personIds)->where($key, false)->pluck('person_id')->all();

        return array_values(array_diff($personIds, $off));
    }
}
