<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A comment as the door screen draws it: the tag and its label, who wrote
 * it, the time in the centre's own clock, whether it has been changed since,
 * and whether the reader may change it.
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
            // Changed after it was written: the screen says so next to the time.
            'edited' => $this->updated_at->gt($this->created_at),
            // Whether this reader may change it — the author today, the director always.
            'can_edit' => $request->user() !== null && Gate::forUser($request->user())->allows('update', $this->resource),
        ];
    }
}
