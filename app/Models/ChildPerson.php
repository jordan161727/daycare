<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One adult, one child, and what the first is for the second.
 *
 * A pivot with a model of its own because the flags on it are read on their
 * own terms — the pick-up list and the emergency list are queries against this
 * table, not a walk through everybody's links.
 */
class ChildPerson extends Model
{
    use AsPivot;

    protected $table = 'child_people';

    protected $fillable = [
        'child_id', 'person_id', 'relationship',
        'is_guardian', 'can_pickup', 'is_emergency',
        'pays_fees', 'gets_texts',
        'priority', 'restriction', 'source',
    ];

    protected $casts = [
        'is_guardian' => 'boolean',
        'can_pickup' => 'boolean',
        'is_emergency' => 'boolean',
        'pays_fees' => 'boolean',
        'gets_texts' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * A new guardian with a mobile gets the texts unless somebody said
     * otherwise (QA #45, 12 Nov 2026). Saved off by default, every family
     * added since the column arrived was out of reach and Messages went to
     * nobody. A link written with gets_texts said either way keeps it; STOP,
     * a restriction and the untick on the record still win, as before.
     */
    protected static function booted(): void
    {
        static::creating(function (self $link) {
            if (array_key_exists('gets_texts', $link->getAttributes())) {
                return;
            }
            $cell = $link->person_id ? Person::whereKey($link->person_id)->value('cell') : null;
            $link->gets_texts = (bool) $link->is_guardian && Person::e164($cell) !== null;
        });
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Whether this person may actually collect this child today.
     *
     * The tick alone is not the answer. A restriction — a court order, most
     * often — overrides it, so that a tick left on by mistake cannot readmit
     * somebody who has been excluded. Anywhere that asks "may they collect"
     * asks here rather than reading can_pickup.
     */
    public function allowsPickup(): bool
    {
        return $this->can_pickup && blank($this->restriction);
    }

    /** The pick-up list, as a scope so the rule is written once. */
    public function scopeAllowedToCollect($query)
    {
        return $query->where('can_pickup', true)->whereNull('restriction');
    }

    /** The emergency list, in the order the centre rings them. */
    public function scopeEmergencyOrder($query)
    {
        return $query->where('is_emergency', true)->orderBy('priority');
    }
}
