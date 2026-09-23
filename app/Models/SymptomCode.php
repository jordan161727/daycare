<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry on the centre's health-check list.
 *
 * In a table rather than an enum because the list is a licensing matter: it
 * changes without a deploy, and a code that is retired has to keep reading
 * correctly on the months of records that already used it. That is what
 * `active` is for — retiring a code takes it out of the picker and leaves it
 * in the history.
 */
class SymptomCode extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'int';

    public $incrementing = false;

    protected $fillable = ['code', 'label', 'requires_note', 'active'];

    protected $casts = [
        'requires_note' => 'boolean',
        'active' => 'boolean',
    ];

    /** Nothing wrong. The only code that does not mark a child as unwell. */
    public const NORMAL = 0;

    /** The list a picker offers, in the order it is read. */
    public static function active()
    {
        return static::where('active', true)->orderBy('code')->get();
    }

    /**
     * Whether a code means the child is unwell.
     *
     * Anything but Normal. Said here rather than in each caller, because the
     * header count, the chip colour and the sheet all have to agree about what
     * "sick" means, and three copies of `!== 0` is three places to disagree.
     */
    public static function isSick(?int $code): bool
    {
        return $code !== null && $code !== self::NORMAL;
    }
}
