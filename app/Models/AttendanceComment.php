<?php

namespace App\Models;

use App\Enums\CommentTag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note on a child's day: what the next teacher, or the parent at pick-up,
 * should know. One attendance row, one author, one tag.
 */
class AttendanceComment extends Model
{
    protected $fillable = ['attendance_id', 'child_id', 'user_id', 'tag', 'body'];

    protected $casts = [
        'tag' => CommentTag::class,
    ];

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
}
