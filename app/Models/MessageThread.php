<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One conversation: a family about one child (Room or Office), or one
 * announcement to everyone — Phase 9 of docs/work.md.
 *
 * Who may read it is the whole difference between the kinds. Room is the
 * child's teachers and the office; Office is the office alone, because
 * tuition and DSS are nobody else's business; an announcement is the
 * office's, one-way, and any reply to it lands as an Office conversation.
 */
class MessageThread extends Model
{
    public const ROOM = 'room';

    public const OFFICE = 'office';

    public const ANNOUNCEMENT = 'ann';

    public const KINDS = [
        self::ROOM => 'Room',
        self::OFFICE => 'Office',
        self::ANNOUNCEMENT => 'Announcement',
    ];

    protected $fillable = ['kind', 'child_id', 'classroom', 'title', 'last_message_at', 'created_by'];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id')->orderBy('id');
    }

    public function latest(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id')->latest('id');
    }

    /** Who has opened it, when they last did, and how far they had read. */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_reads', 'thread_id', 'user_id')->withPivot(['read_at', 'last_message_id']);
    }

    public function isAnnouncement(): bool
    {
        return $this->kind === self::ANNOUNCEMENT;
    }

    /**
     * The threads this person may read: everything for the office; a
     * teacher's own rooms' conversations and the announcements for a teacher.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('kind', self::ANNOUNCEMENT)
                ->orWhere(fn (Builder $room) => $room->where('kind', self::ROOM)->whereIn('classroom', $user->assignedClassrooms() ?: ['']));
        });
    }

    /** Mark it read by this person, now. */
    public function readBy(User $user): void
    {
        $this->readers()->syncWithoutDetaching([$user->id => ['read_at' => now(), 'last_message_id' => (int) $this->messages()->max('id')]]);
    }

    /**
     * Threads with a parent's reply this person has not seen.
     *
     * Only a reply counts: a conversation the centre started and nobody has
     * answered is not something waiting on anyone.
     */
    public static function unreadFor(User $user): int
    {
        return static::visibleTo($user)->whereHas('messages', fn (Builder $m) => self::unseenBy($m, $user))->count();
    }

    /** Narrow a messages query to a parent's texts this person has not opened. */
    public static function unseenBy(Builder $messages, User $user): Builder
    {
        return $messages
            ->where('direction', Message::IN)
            ->where('id', '>', function ($q) use ($user) {
                $q->selectRaw('coalesce(max(last_message_id), 0)')
                    ->from('message_reads')
                    ->whereColumn('message_reads.thread_id', 'messages.thread_id')
                    ->where('message_reads.user_id', $user->id);
            });
    }
}
