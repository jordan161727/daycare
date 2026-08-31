<?php

namespace App\Http\Controllers;

use App\Imports\ChildrenImport;
use App\Models\Child;
use App\Models\RoomSchedule;
use App\Models\User;
use App\Services\ClassroomAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class ChildController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $allowedSorts = ['lan', 'first_name', 'last_name', 'age', 'classroom', 'status'];
        $sort = trim((string) request('sort')) ?: 'last_name';
        $direction = trim((string) request('direction')) ?: 'asc';

        abort_unless(in_array($sort, $allowedSorts, true), 404);
        abort_unless(in_array($direction, ['asc', 'desc'], true), 404);

        $children = Child::query()
            ->visibleTo($requestUser = request()->user())
            // Age is shown, not stored, so it sorts by the date it is worked out
            // from — the other way round, since the youngest child is the one
            // with the latest date of birth.
            ->when($sort === 'age', fn ($query) => $query->orderByRaw(
                'COALESCE(birth_date, dob) '.($direction === 'asc' ? 'desc' : 'asc')
            ), fn ($query) => $query->orderBy($sort, $direction))
            ->when($sort === 'last_name', fn ($query) => $query->orderBy('first_name'))
            ->paginate(10)
            ->withQueryString();

        // What time each room runs, read against the child's classroom. One
        // query for the whole page rather than a lookup per row.
        //
        // The room's own hours and not the generated roster: a staff week is
        // shift patterns, breaks and handovers, and reading a class time out of
        // it gives a row like "7:00 AM – 1:45 PM, 2:00 PM – 6:00 PM" — true
        // about the rota and useless as an answer to what time the class runs.
        // That question has one answer, it is set on the room, and it holds
        // whether or not a week has been generated.
        $roomSchedules = RoomSchedule::byRoom();

        // The whole roll, not the page: "61 active" counted off ten rows would
        // be a different number on every page of the same list.
        $activeCount = Child::visibleTo($requestUser)->where('status', 'Active')->count();

        return view('children.index', compact('children', 'sort', 'direction', 'roomSchedules', 'activeCount'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('children.create', ['nextLan' => Child::nextLan()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $child = Child::create($this->validatedData($request));

        $this->syncPhoto($request, $child);

        // The child is on file, so the imported document no longer needs keeping.
        ChildDocumentController::discard($request->input('import_token'));

        return redirect()->route('children.index')->with('success', 'Child added successfully.');
    }

    /**
     * The child's record as a page rather than a form.
     *
     * Most of what is on file about a child is read far more often than it is
     * changed — a phone number at pick-up time, who is allowed to collect them,
     * the note about the allergy. The edit form holds all of it behind inputs
     * and is director-only; this is the same record readable by the teacher who
     * actually has the child in front of them.
     */
    public function show(Child $child)
    {
        // The same rule the roster list is filtered by, applied to the one
        // record: a teacher may read the children in their own rooms and
        // nobody else's.
        abort_unless($this->isVisibleTo($child, request()->user()), 403);

        return view('children.show', [
            'child' => $child,
            'roomSchedule' => RoomSchedule::byRoom()[$child->classroom] ?? null,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Child $child)
    {
        return view('children.edit', compact('child'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Child $child)
    {
        $child->update($this->validatedData($request, $child));

        $this->syncPhoto($request, $child);

        return redirect()->route('children.index')->with('success', 'Child details updated successfully.');
    }

    /**
     * The child's photograph, streamed to whoever may already see the child.
     *
     * The file sits on the private disk, so this route is the only way to it —
     * which is the point. A staff member's avatar is theirs to publish and goes
     * on the public disk; a photograph of somebody else's four-year-old is not
     * a thing to leave on a guessable URL.
     */
    public function photo(Child $child)
    {
        abort_unless($this->isVisibleTo($child, request()->user()), 403);
        abort_if(blank($child->photo_path) || ! Storage::disk('local')->exists($child->photo_path), 404);

        return Storage::disk('local')->response($child->photo_path, null, [
            // Private, so a shared cache never holds it: it is one person's
            // photograph, served on the strength of who asked for it.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Saves an uploaded photograph, or drops the one on file when the form asks.
     *
     * Both cases delete what was there first — a replaced photo left on disk is
     * a picture of a child nothing in the system points at any more.
     */
    private function syncPhoto(Request $request, Child $child): void
    {
        if ($request->hasFile('photo')) {
            $this->deletePhoto($child);

            $child->update(['photo_path' => $request->file('photo')->store('children', 'local')]);

            return;
        }

        if ($request->boolean('remove_photo')) {
            $this->deletePhoto($child);

            $child->update(['photo_path' => null]);
        }
    }

    private function deletePhoto(Child $child): void
    {
        if (filled($child->photo_path)) {
            Storage::disk('local')->delete($child->photo_path);
        }
    }

    /** The rule the roster list is filtered by, asked about one child. */
    private function isVisibleTo(Child $child, ?User $user): bool
    {
        return $user !== null
            && Child::whereKey($child->getKey())->visibleTo($user)->exists();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Child $child)
    {
        //
    }

    private function validatedData(Request $request, ?Child $child = null): array
    {
        if ($request->filled('birth_date')) {
            $request->merge(['birth_date' => $this->normalizeDate($request->input('birth_date'))]);
        }

        // <input type="time"> posts H:i, but a value read back out of MySQL is
        // H:i:s and a browser with the seconds step set posts that too. Both are
        // cut down to H:i here so the rules below only ever see one shape.
        foreach (['drop_off_time', 'pick_up_time'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => $this->normalizeTime($request->input($field))]);
            }
        }

        $rules = [
            'lan' => ['required', 'string', 'max:255', Rule::unique('children', 'lan')->ignore($child)],
            'child_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'dob' => ['nullable', 'date'],
            // The room is worked out from the date of birth. What the director
            // sets here is the departure from it, not the room itself.
            'classroom_override' => ['nullable', 'string', Rule::in(ClassroomAssignment::rooms())],
            // Blank means today, the same as it does on the schedule page. It is
            // not a second thing to fill in before a room can be picked.
            'classroom_override_from' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'enrolled_on' => ['nullable', 'date'],
            'withdrawn_on' => ['nullable', 'date', 'after_or_equal:enrolled_on'],
            // What the parent contracted for. Blank means nobody has said, and
            // the projection then reports the days without claiming a target;
            // zero is the deliberate "not coming" and suppresses it.
            'expected_hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:168'],
            // The hours of the day the child is here, inside the hours the
            // centre is open. Either end may stand alone while the other is
            // still being agreed, so neither requires the other.
            'drop_off_time' => ['nullable', 'date_format:H:i', 'after_or_equal:'.Child::DAY_OPENS_AT, 'before_or_equal:'.Child::DAY_CLOSES_AT],
            'pick_up_time' => ['nullable', 'date_format:H:i', 'after_or_equal:'.Child::DAY_OPENS_AT, 'before_or_equal:'.Child::DAY_CLOSES_AT, 'after:drop_off_time'],
            'birth_date' => ['nullable', 'date'],
            // Blank stays a real answer: only the drawn stand-in face reads
            // this, and a record that does not say is drawn as one that does
            // not say rather than being guessed at from the name.
            'gender' => ['nullable', Rule::in(Child::GENDERS)],
            'other_notes' => ['nullable', 'string'],
            'important_notes' => ['nullable', 'string'],
            // Validated here so a bad upload is reported with the rest of the
            // form; the file itself is stored by syncPhoto once the record
            // exists, since a new child has no id to hang a file on yet.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];

        foreach ($this->enrollmentFields() as $field) {
            $rules[$field] ??= ['nullable', 'string', 'max:255'];
        }

        $data = $request->validate($rules, [
            'photo.max' => 'The photo may not be larger than 4 MB.',
            'photo.image' => 'The photo must be an image file.',
        ]);

        // The upload is not a column. syncPhoto puts the stored path in.
        unset($data['photo']);

        // One date, two columns behind it: `dob` from the original roster and
        // `birth_date` from the enrolment form. The form edits one field, so
        // both are written from it — otherwise the reader that happens to look
        // at the other column keeps showing the date that was corrected.
        //
        // Nobody types an age any more either; every screen reads the date off
        // the date of birth. The column is still written so the spreadsheet
        // import and the records that came in through it agree with what is
        // displayed.
        if (array_key_exists('birth_date', $data)) {
            $data['dob'] = $data['birth_date'];
            $data['age'] = Child::ageLabelFor(
                filled($data['birth_date']) ? Carbon::parse($data['birth_date']) : null
            );
        }

        // Clearing the room hands the child back to the age rule, so the date it
        // started from goes with it.
        $data['classroom_override'] = $data['classroom_override'] ?? null;
        $data['classroom_override_from'] = blank($data['classroom_override'])
            ? null
            : ($data['classroom_override_from'] ?? today()->toDateString());

        return $data;
    }

    private function normalizeDate(?string $value): ?string
    {
        if (blank($value)) return $value;
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'F j Y', 'F j, Y', 'M j Y', 'M j, Y', 'j F Y', 'j M Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, trim($value))->format('Y-m-d');
            } catch (\Throwable) {
                // Try the next common document date format.
            }
        }
        try {
            return Carbon::parse(trim($value))->format('Y-m-d');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * A posted time as H:i. Anything unparseable is handed back untouched so
     * the date_format rule rejects it and the field reports its own error,
     * rather than being quietly turned into a time nobody typed.
     */
    private function normalizeTime(?string $value): ?string
    {
        if (blank($value)) return $value;
        try {
            return Carbon::parse(trim($value))->format('H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function enrollmentFields(): array
    {
        $fields = ['nickname','address','city','zip','telephone','mother_name','mother_address','mother_home_phone','mother_employer','mother_work_phone','mother_fax','mother_cell','mother_email','mother_title','mother_ssn','father_name','father_address','father_home_phone','father_employer','father_work_phone','father_fax','father_cell','father_email','father_title','father_ssn','email_address','parents_status','responsible_for_payment','emergency_contact','secondary_emergency_contact','emergency_telephone','emergency_relationship','emergency_license_number'];
        for ($number = 1; $number <= 3; $number++) foreach (['name','address','telephone','alternate','relationship','license_number'] as $field) $fields[] = "pickup_{$number}_{$field}";
        return $fields;
    }

}
