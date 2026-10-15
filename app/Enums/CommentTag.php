<?php

namespace App\Enums;

/**
 * What kind of note a comment is — the five things teachers actually wrote
 * on the back of the paper sheet.
 */
enum CommentTag: string
{
    case ParentNote = 'parent_note';
    case Health = 'health';
    case Belongings = 'belongings';
    case Incident = 'incident';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ParentNote => 'Parent note',
            self::Health => 'Health',
            self::Belongings => 'Belongings',
            self::Incident => 'Incident',
            self::Other => 'Other',
        };
    }

    /** Every tag with its label, for the chips on the form. */
    public static function options(): array
    {
        return array_map(fn (self $tag) => ['value' => $tag->value, 'label' => $tag->label()], self::cases());
    }
}
