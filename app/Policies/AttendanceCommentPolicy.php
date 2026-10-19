<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\AttendanceComment;
use App\Models\User;

/**
 * Who may read and write the notes on a child's day.
 *
 * Anybody who can clock a child in — the director and the teachers — can
 * read the comments and add one. A note can be changed by whoever wrote it,
 * on the day they wrote it: a typo or a wrong tag fixed while the day is
 * still the day. The director can change any note at any time. Nobody
 * deletes: a note, once written, is part of the day's record, the same as
 * the clock times, and a changed one says it was changed.
 */
class AttendanceCommentPolicy
{
    public function viewAny(User $user, Attendance $attendance): bool
    {
        return $this->worksTheDoor($user);
    }

    /**
     * Reading notes back by day — a child's earlier comments, or the log of
     * a whole day — is the same right as reading today's: whoever works
     * the door. The door shows every room to every teacher, and so do these.
     */
    public function read(User $user): bool
    {
        return $this->worksTheDoor($user);
    }

    public function create(User $user, Attendance $attendance): bool
    {
        return $this->worksTheDoor($user);
    }

    public function update(User $user, AttendanceComment $comment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $comment->user_id === $user->id
            && $comment->created_at->timezone(config('app.timezone'))->isSameDay(now()->timezone(config('app.timezone')));
    }

    private function worksTheDoor(User $user): bool
    {
        return $user->isAdmin() || $user->role === 'teacher';
    }
}
