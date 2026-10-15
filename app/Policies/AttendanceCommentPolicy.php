<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

/**
 * Who may read and write the notes on a child's day.
 *
 * Anybody who can clock a child in — the director and the teachers — can
 * read the comments and add one. Nobody edits or deletes: a note, once
 * written, is part of the day's record, the same as the clock times.
 */
class AttendanceCommentPolicy
{
    public function viewAny(User $user, Attendance $attendance): bool
    {
        return $this->worksTheDoor($user);
    }

    public function create(User $user, Attendance $attendance): bool
    {
        return $this->worksTheDoor($user);
    }

    private function worksTheDoor(User $user): bool
    {
        return $user->isAdmin() || $user->role === 'teacher';
    }
}
