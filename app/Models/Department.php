<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A part of the centre: Kitchen, Front Office, Infant Programme.
 *
 * Deliberately thin. A department is a name somebody can group by, and every
 * attempt to make it more than that — hours rules, ratios, a manager — belongs
 * on the thing that actually holds it, because a department that quietly
 * changes how pay is worked out is one nobody dares rename.
 */
class Department extends Model
{
    protected $fillable = ['name', 'notes'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** The staff in it, in the order every staff list shows them. */
    public function staff(): HasMany
    {
        return $this->users()->whereIn('role', ['admin', 'teacher'])->orderBy('name');
    }
}
