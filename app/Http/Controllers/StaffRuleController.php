<?php

namespace App\Http\Controllers;

use App\Models\StaffRule;
use App\Models\User;
use App\Services\ClassroomAssignment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Scheduling rules, always in the context of the staff member they constrain.
 */
class StaffRuleController extends Controller
{
    public function store(Request $request, User $teacher)
    {
        abort_unless($teacher->role === 'teacher', 404);

        $teacher->staffRules()->create($this->validated($request, $teacher));

        return back()->with('success', 'Rule added.');
    }

    public function update(Request $request, User $teacher, StaffRule $rule)
    {
        abort_unless($teacher->role === 'teacher' && $rule->user_id === $teacher->id, 404);

        $rule->update($this->validated($request, $teacher));

        return back()->with('success', 'Rule updated.');
    }

    public function destroy(User $teacher, StaffRule $rule)
    {
        abort_unless($teacher->role === 'teacher' && $rule->user_id === $teacher->id, 404);

        $rule->delete();

        return back()->with('success', 'Rule removed.');
    }

    /**
     * Validate against the fields the chosen rule type actually uses.
     *
     * Columns the type does not use are nulled rather than kept. A stale end
     * time left behind by switching FIXED_SHIFT to AVAILABLE_AFTER is invisible
     * on the screen but still in the row, and the day it becomes a FIXED_SHIFT
     * again it silently applies.
     */
    private function validated(Request $request, User $teacher): array
    {
        $data = $request->validate([
            'rule_type' => ['required', Rule::in(StaffRule::types())],
            'priority' => ['required', Rule::in(StaffRule::PRIORITIES)],
            'day' => ['nullable', Rule::in(StaffRule::DAYS)],
            'time_1' => ['nullable', 'date_format:H:i'],
            'time_2' => ['nullable', 'date_format:H:i'],
            'number' => ['nullable', 'numeric', 'min:0', 'max:168'],
            'value_text' => ['nullable', 'string', 'max:255'],
            'source_note' => ['nullable', 'string', 'max:255'],
        ]);

        $type = $data['rule_type'];
        $uses = StaffRule::fieldsFor($type);

        $rule = [
            'rule_type' => $type,
            'priority' => $data['priority'],
            'source_note' => $data['source_note'] ?? null,
            'day' => null,
            'time_1' => null,
            'time_2' => null,
            'number' => null,
            'value_text' => null,
        ];

        foreach ($uses as $field) {
            $rule[$field] = match ($field) {
                'time_1', 'time_2' => StaffRule::toMinutes($data[$field] ?? null),
                default => $data[$field] ?? null,
            };
        }

        $this->assertUsable($rule, $uses, $teacher);

        return $rule;
    }

    /**
     * Reject the rules that would be accepted and then quietly do nothing.
     *
     * A rule with a missing time or a window that ends before it starts is not
     * a constraint — it is a line in the list that a director believes is being
     * enforced. Catching it here is the difference between a form error and a
     * roster that is wrong for a month.
     */
    private function assertUsable(array $rule, array $uses, User $teacher): void
    {
        $errors = [];

        foreach ($uses as $field) {
            if ($rule[$field] === null || $rule[$field] === '') {
                $errors[$field] = ['This is required for a '.$rule['rule_type'].' rule.'];
            }
        }

        if (in_array('time_2', $uses, true) && $rule['time_1'] !== null && $rule['time_2'] !== null
            && $rule['time_2'] <= $rule['time_1']) {
            $errors['time_2'] = ['The end time has to be after the start time.'];
        }

        if (in_array($rule['rule_type'], StaffRule::ROOM_VALUED, true)
            && filled($rule['value_text'])
            && ! in_array($rule['value_text'], ClassroomAssignment::rooms(), true)) {
            $errors['value_text'] = ['Pick one of the centre’s rooms.'];
        }

        if (in_array($rule['rule_type'], StaffRule::STAFF_VALUED, true) && filled($rule['value_text'])) {
            if ($rule['value_text'] === $teacher->name) {
                $errors['value_text'] = ['Somebody cannot be paired against themselves.'];
            } elseif (! User::teachers()->where('name', $rule['value_text'])->exists()) {
                $errors['value_text'] = ['No teacher by that name — the rule would never be applied.'];
            }
        }

        if ($rule['rule_type'] === 'REQUIRED_HOURS' && ! in_array($rule['value_text'], ['WEEKLY', 'BIWEEKLY'], true)) {
            $errors['value_text'] = ['Choose WEEKLY or BIWEEKLY.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
