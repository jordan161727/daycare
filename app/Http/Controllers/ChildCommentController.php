<?php

namespace App\Http\Controllers;

use App\Models\AttendanceComment;
use App\Models\Child;
use App\Services\CommentDays;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A child's earlier comments, read from the dialog's "Past comments" tab.
 *
 * Grouped by day, newest first, only the days that have a comment, each
 * with that day's clock. Twenty days a page; "Load older" asks for the
 * next twenty under the last one shown.
 */
class ChildCommentController extends Controller
{
    public function index(Request $request, Child $child, CommentDays $days)
    {
        Gate::authorize('read', AttendanceComment::class);

        $data = $request->validate([
            'range' => ['nullable', Rule::in(CommentDays::RANGES)],
            'before' => ['nullable', 'date_format:Y-m-d'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        return response()->json($days->forChild(
            $child,
            $data['range'] ?? 'all',
            $data['before'] ?? null,
            $data['date'] ?? null,
            (int) ($data['limit'] ?? 20),
        ));
    }
}
