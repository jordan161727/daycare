<?php

namespace App\Http\Resources;

use App\Models\AttendanceComment;
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
        $timezone = config('app.timezone');

        return [
            'id' => $this->id,
            'tag' => $this->tag->value,
            'tag_label' => $this->tag->label(),
            // Health, incident or general: the chip the log and the panel wear.
            'type' => $this->type(),
            'type_label' => $this->typeLabel(),
            'body' => $this->body,
            'author' => $this->author?->name ?? 'Staff',
            'author_role' => AttendanceComment::roleLabel($this->author_role ?? $this->author?->role),
            'time' => $this->created_at->timezone($timezone)->format('g:i A'),
            // The moment it was written, for sorting a day's notes across children.
            'at' => $this->created_at->toIso8601String(),
            'date' => $this->date?->toDateString(),
            // Changed after it was written: the screen says so next to the time.
            'edited' => $this->wasEdited(),
            // Whether this reader may change it — the author today, the director always.
            'can_edit' => $request->user() !== null && Gate::forUser($request->user())->allows('update', $this->resource),
        ];
    }
}
