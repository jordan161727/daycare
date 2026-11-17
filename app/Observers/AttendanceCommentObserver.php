<?php

namespace App\Observers;

use App\Models\AttendanceComment;
use App\Services\ParentAlerts;

/**
 * A comment on the register reaches the child's parents (17 Nov 2026), from
 * whichever screen wrote it: the comment dialog or a clock-in / clock-out.
 * Waits for the transaction, so a note that is rolled back never pushes.
 */
class AttendanceCommentObserver
{
    public bool $afterCommit = true;

    public function __construct(private ParentAlerts $alerts) {}

    public function created(AttendanceComment $comment): void
    {
        $this->alerts->comment($comment);
    }

    /** Only when the words changed: retagging a note is not news to a parent. */
    public function updated(AttendanceComment $comment): void
    {
        if ($comment->wasChanged('body')) {
            $this->alerts->comment($comment, edited: true);
        }
    }
}
