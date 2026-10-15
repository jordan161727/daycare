<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceCommentRequest;
use App\Http\Resources\AttendanceCommentResource;
use App\Models\DoorAttendance;
use App\Models\AttendanceComment;
use Illuminate\Support\Facades\Gate;

/**
 * The notes on a child's day — what used to go on the back of the paper
 * sign-in sheet. Read and written from the door screen's dialog.
 */
class AttendanceCommentController extends Controller
{
    /** Today's comments on this row, newest first. */
    public function index(DoorAttendance $attendance)
    {
        Gate::authorize('viewAny', [AttendanceComment::class, $attendance]);

        // A bare list, not {"data": [...]}: the dialog reads it straight in.
        return response()->json(
            AttendanceCommentResource::collection($attendance->comments()->with('author')->get())->resolve(),
        );
    }

    /**
     * A new note. The author is whoever is signed in and the child is the
     * row's — neither is taken from the request, so a note cannot be filed
     * under somebody else's name or on somebody else's child.
     */
    public function store(StoreAttendanceCommentRequest $request, DoorAttendance $attendance)
    {
        Gate::authorize('create', [AttendanceComment::class, $attendance]);

        $comment = $attendance->comments()->create([
            'child_id' => $attendance->child_id,
            'user_id' => $request->user()->id,
            'tag' => $request->validated('tag'),
            'body' => $request->validated('body'),
        ]);

        return (new AttendanceCommentResource($comment->load('author')))
            ->response()
            ->setStatusCode(201);
    }
}
