<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A comment as the door screen draws it: the tag and its label, who wrote
 * it, and the time in the centre's own clock.
 *
 * @mixin \App\Models\AttendanceComment
 */
class AttendanceCommentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tag' => $this->tag->value,
            'tag_label' => $this->tag->label(),
            'body' => $this->body,
            'author' => $this->author?->name ?? 'Staff',
            'time' => $this->created_at->timezone(config('app.timezone'))->format('g:i A'),
        ];
    }
}
