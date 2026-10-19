<?php

namespace App\Models;

use App\Enums\CommentTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A note on a child's day: what the next teacher, or the parent at pick-up,
 * should know. One attendance row, one author, one tag.
 *
 * The day is written on the note itself (20 Oct 2026) so a child's earlier
 * notes can be read back by day — Friday's, on Monday — and the log page can
 * list every note of a day without walking the attendance table.
 */
class AttendanceComment extends Model
{
    use SoftDeletes;

    protected $fillable = ['attendance_id', 'child_id', 'date', 'user_id', 'author_role', 'tag', 'body', 'edited_at'];

    protected $casts = [
        'tag' => CommentTag::class,
        'edited_at' => 'datetime',
    ];

    /**
     * The day, stored as a bare Y-m-d. A date cast writes the column as a
     * full timestamp, which a DATE column on MySQL truncates and SQLite
     * keeps — and then "where date = '2026-10-16'" finds nothing. Read
     * back as a Carbon at midnight.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }

    /** The three kinds the log sorts notes into, and what each is called. */
    public const TYPES = ['health' => 'Health', 'general' => 'General', 'incident' => 'Incident'];

    protected static function booted(): void
    {
        // The day and the author's role ride with the note from the moment
        // it is written, so neither has to be looked up to read it back.
        static::creating(function (self $comment) {
            if ($comment->date === null && $comment->attendance_id) {
                $comment->date = DoorAttendance::whereKey($comment->attendance_id)->value('attendance_date');
            }

            if ($comment->author_role === null && $comment->user_id) {
                $comment->author_role = User::whereKey($comment->user_id)->value('role');
            }
        });

        // Changed words are stamped: the screen says "edited" off this.
        static::updating(function (self $comment) {
            if ($comment->isDirty('body') || $comment->isDirty('tag')) {
                $comment->edited_at = now();
            }
        });
    }

    /** The door's row the note is on — comments are a door feature. */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(DoorAttendance::class, 'attendance_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /** The staff member who wrote it. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Health, incident, or general — the tag read as the log reads it. The
     * five tags of the paper sheet collapse to three: a parent note, a
     * belongings note and an "other" are all general.
     */
    public function type(): string
    {
        return match ($this->tag) {
            CommentTag::Health => 'health',
            CommentTag::Incident => 'incident',
            default => 'general',
        };
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type()];
    }

    /** Whether the note was changed after it was written. */
    public function wasEdited(): bool
    {
        return $this->edited_at !== null || $this->updated_at->gt($this->created_at);
    }

    /** Notes of one type, as the log filters them. */
    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return match ($type) {
            'health' => $query->where('tag', CommentTag::Health->value),
            'incident' => $query->where('tag', CommentTag::Incident->value),
            'general' => $query->whereNotIn('tag', [CommentTag::Health->value, CommentTag::Incident->value]),
            default => $query,
        };
    }

    /** "Teacher", "Director" — the author's role as a word on the screen. */
    public static function roleLabel(?string $role): string
    {
        return match ($role) {
            'admin' => 'Director',
            'teacher' => 'Teacher',
            null, '' => 'Staff',
            default => ucfirst($role),
        };
    }
}
