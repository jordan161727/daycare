<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\ParentAbsenceReport;
use App\Models\ParentNotificationPref;
use App\Models\Person;
use App\Services\ParentDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Version 2 of the parent app's API (docs/parent-app-api.md §4.12–4.17):
 * the day, photos, calendar, absences, pickup and notification settings.
 * Only translates; ParentDay holds the rules.
 *
 * A child who is not this parent's answers 404, as in ParentAppController,
 * so the API never says that a child exists.
 */
class ParentDayController extends Controller
{
    public function __construct(private ParentDay $day) {}

    public function daily(Request $request, int $child): JsonResponse
    {
        $kid = $this->child($request, $child);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->day->daily($kid, $data['date'] ?? $this->day->today()));
    }

    public function photos(Request $request, int $child): JsonResponse
    {
        $kid = $this->child($request, $child);
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->day->photos($request->user(), $kid, (int) ($data['page'] ?? 1)));
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return response()->json($this->day->events($data['month'] ?? substr($this->day->today(), 0, 7)));
    }

    public function absence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'dates' => ['required', 'array', 'min:1', 'max:31'],
            'dates.*' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->day->today(), 'distinct'],
            'reason' => ['required', Rule::in(ParentAbsenceReport::REASONS)],
            'symptoms' => ['nullable', 'array'],
            'symptoms.*' => ['string', Rule::in(ParentAbsenceReport::SYMPTOMS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $kid = $this->child($request, (int) $data['child_id']);
        $absences = $this->day->reportAbsence($request->user(), $kid, $data['dates'], $data['reason'], $data['symptoms'] ?? [], $data['note'] ?? null);

        return response()->json(['absences' => $absences], 201);
    }

    public function pickup(Request $request, int $child): JsonResponse
    {
        return response()->json($this->day->pickup($this->child($request, $child)));
    }

    public function pickupToday(Request $request, int $child): JsonResponse
    {
        $kid = $this->child($request, $child);
        $data = $request->validate(['person_id' => ['required', 'integer']]);

        $pickup = $this->day->chooseToday($request->user(), $kid, (int) $data['person_id']);
        if (! $pickup) {
            throw ValidationException::withMessages(['person_id' => 'That person is not on the pick-up list. Ask the office to add them.']);
        }

        return response()->json($pickup);
    }

    public function pickupOneTime(Request $request, int $child): JsonResponse
    {
        $kid = $this->child($request, $child);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'relation' => ['required', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        return response()->json($this->day->oneTimeToday($request->user(), $kid, trim($data['name']), trim($data['relation']), trim($data['phone'])), 201);
    }

    public function prefs(Request $request): JsonResponse
    {
        return response()->json(ParentNotificationPref::for($request->user()->id));
    }

    public function updatePrefs(Request $request): JsonResponse
    {
        $rules = collect(ParentNotificationPref::KEYS)->mapWithKeys(fn ($k) => [$k => ['sometimes', 'boolean']])->all();
        $changes = array_map(fn ($v) => (bool) $v, $request->validate($rules));

        $personId = $request->user()->id;
        ParentNotificationPref::updateOrCreate(['person_id' => $personId], array_merge(ParentNotificationPref::for($personId), $changes));

        return response()->json(ParentNotificationPref::for($personId));
    }

    private function child(Request $request, int $childId): Child
    {
        /** @var Person $person */
        $person = $request->user();
        $child = $this->day->childOf($person, $childId);
        abort_unless($child, 404, 'Child not found.');

        return $child;
    }
}
