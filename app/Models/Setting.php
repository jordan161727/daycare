<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A setting a director has overridden.
 *
 * Read through get(), which falls back to config/daycare.php when no row
 * exists. That fallback is the whole design: the config file stays the
 * documented default with the reasoning beside it, and this table holds only
 * what somebody has deliberately changed. Clearing a row is how a setting goes
 * back to its default, which is why set() deletes rather than storing null.
 *
 * Every read is served from one query per request. Settings are read in the
 * layout, in the kiosk and on nearly every page, and a query apiece for a
 * handful of short strings is a query nobody should pay for.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    /** All overrides, loaded once and kept for the rest of the request. */
    private static ?array $loaded = null;

    /**
     * What a setting is, override first and the config default behind it.
     *
     * Guarded against the table not existing: the app has to boot on a
     * database mid-migration, and the layout reads the centre's name on every
     * page — a missing table there would take down every screen including the
     * one somebody would use to fix it.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$loaded === null) {
            self::$loaded = rescue(
                fn () => self::query()->pluck('value', 'key')->all(),
                [],
                false,
            );
        }

        return self::$loaded[$key] ?? $default;
    }

    /** The same, read as a boolean — a checkbox arrives as "1" or "0". */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Change a setting, or clear it back to its default.
     *
     * A blank value is a clear rather than an empty string: a company name box
     * somebody emptied means "use the default", not "the centre has no name".
     */
    public static function put(string $key, mixed $value): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if ($value === null || $value === '') {
            self::query()->where('key', $key)->delete();
        } else {
            self::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }

        self::$loaded = null;
    }

    /** Drop the request's cache — for tests, which change settings mid-request. */
    public static function forget(): void
    {
        self::$loaded = null;
    }
}
