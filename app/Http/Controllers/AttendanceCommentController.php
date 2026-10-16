<?php

namespace App\Http\Controllers;

use App\Enums\CommentTag;
use App\Http\Requests\StoreAttendanceCommentRequest;
use App\Http\Resources\AttendanceCommentResource;
use App\Models\DoorAttendance;
use App\Models\AttendanceComment;
use Illuminate\Support\Facades\Gate;

/**
 * The note on a child's day — what used to go on the back of the paper
 * sign-in sheet. Read and written from the door screen's dialog.
 *
 * One note a row (17 Oct 2026): a child's day has a comment or it has not,
 * and pressing it changes it. Not a thread, and not sorted into kinds — the
 * tag column stays on the table, written as "other", for the rows that
 * were typed before this.
 */
class AttendanceCommentController extends Controller
{
    /** The row's comments, newest first — one, in practice. */
    public function index(DoorAttendance $attendance)
    {
        Gate::authorize('viewAny', [AttendanceComment::class, $attendance]);

        // A bare list, not {"data": [...]}: the dialog reads it straight in.
        return response()->json(
            AttendanceCommentResource::collection($attendance->comments()->with('author')->get())->resolve(),
        );
    }

    /**
     * The day's note. The author is whoever is signed in and the child is
     * the row's — neither is taken from the request, so a note cannot be
     * filed under somebody else's name or on somebody else's child.
     *
     * A row that already has a note gets it changed rather than a second
     * one: the same words the dialog would send by pressing the note.
     */
    public function store(StoreAttendanceCommentRequest $request, DoorAttendance $attendance)
    {
        if ($existing = $attendance->comments()->first()) {
            return $this->update($request, $attendance, $existing);
        }

        Gate::authorize('create', [AttendanceComment::class, $attendance]);

        $comment = $attendance->comments()->create([
            'child_id' => $attendance->child_id,
            'user_id' => $request->user()->id,
            'tag' => $request->validated('tag') ?? CommentTag::Other->value,
            'body' => $request->validated('body'),
        ]);

        return (new AttendanceCommentResource($comment->load('author')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * A note changed after it was written, by its author on the day or by
     * the director at any time (the policy). The author and the time it was
     * first written stay as they were; the row remembers it was changed,
     * and the screen says so.
     */
    public function update(StoreAttendanceCommentRequest $request, DoorAttendance $attendance, AttendanceComment $comment)
    {
        Gate::authorize('update', $comment);

        $comment->fill([
            'tag' => $request->validated('tag') ?? $comment->tag->value,
            'body' => $request->validated('body'),
        ]);

        // Saving identical words would stamp it "edited" for nothing.
        if ($comment->isDirty()) {
            $comment->save();
        }

        return new AttendanceCommentResource($comment->load('author'));
    }
}
