<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One text in a thread: out from the centre, or in from a parent.
 *
 * An outgoing text to a room or to everyone is one message per thread it
 * landed in, tied together by broadcast_key, so each family's conversation
 * reads whole and the announcement still knows how many it reached.
 */
class Message extends Model
{
    public const OUT = 'out';

    public const IN = 'in';

    protected $fillable = ['thread_id', 'direction', 'body', 'user_id', 'person_id', 'media', 'broadcast_key', 'delivered', 'skipped', 'sent_at'];

    protected $casts = [
        'media' => 'array',
        'skipped' => 'array',
        'sent_at' => 'datetime',
        'delivered' => 'integer',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function isOut(): bool
    {
        return $this->direction === self::OUT;
    }
}
