<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Services\ClassroomAssignment;

/**
 * The kitchen list (HANDOFF.md, Health; docs/work.md Phase 2.3): every child
 * on today's roll with an allergy or a medical alert, by room, the severe
 * ones first, with what happens and what to do.
 *
 * The record's Health card has promised "shows on the roll, check-in and the
 * kitchen list" since Phase 2.3; this is the list (QA E3). It reads the same
 * alerts (Child::alertList) every other screen does, so an allergy added on
 * the record is on it at the next reload. Staff only, and a teacher sees the
 * children in their own rooms, by the rule the roster is filtered by.
 */
class KitchenListController extends Controller
{
    public function index()
    {
        $user = request()->user();
        $today = today()->toDateString();
        $rank = ['severe' => 0, 'moderate' => 1, 'mild' => 2];

        $rows = Child::visibleTo($user)
            ->where('status', 'Active')
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->filter(fn (Child $child) => $child->isEnrolledOn($today))
            ->map(fn (Child $child) => [
                'child' => $child,
                'alerts' => collect($child->alertList())
                    ->filter(fn (array $a) => in_array($a['type'], Child::HEALTH_ALERT_TYPES, true))
                    ->sortBy(fn (array $a) => [$rank[$a['severity'] ?? ''] ?? 3, $a['type'] === 'allergy' ? 0 : 1])
                    ->values(),
            ])
            ->filter(fn (array $row) => $row['alerts']->isNotEmpty());

        $rooms = $rows->groupBy(fn (array $row) => $row['child']->classroom ?: 'Unassigned');

        return view('kitchen.index', [
            'rooms' => ClassroomAssignment::inOrder($rooms->keys())->mapWithKeys(fn ($room) => [$room => $rooms[$room]]),
            'severe' => $rows->filter(fn (array $row) => $row['alerts']->contains('severity', 'severe'))->count(),
            'total' => $rows->count(),
        ]);
    }
}
