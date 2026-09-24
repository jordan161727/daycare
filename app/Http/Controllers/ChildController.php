<?php

namespace App\Http\Controllers;

use App\Exports\ChildrenExport;
use App\Imports\ChildrenImport;
use App\Models\Child;
use App\Models\RoomSchedule;
use App\Models\User;
use App\Services\ClassroomAssignment;
use App\Services\WeekSchedule;
use App\Services\PeopleDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

class ChildController extends Controller
{
    /*
     * Whose record, not which fields.
     *
     * A teacher keeps the whole record of the children in their own rooms —
     * the phone numbers and the pick-up list, and the hours, the dates and the
     * room too. They are the one who is told any of it first, and splitting the
     * record in half only decided which corrections had to wait on the office.
     *
     * The guard that matters is which child, and it is the same rule the roster
     * is filtered by: isVisibleTo, checked on both edit() and update(). That is
     * what keeps the room field safe to hand over. A teacher can only act on a
     * child they already hold, so setting the room can move one out of their
     * own roster — never pull one in. There is no record here that becomes
     * readable by editing.
     *
     * Creating a child is still the director's: a new record decides which room
     * it lands in before anybody holds it, so there is no roster to check it
     * against. See the route group in routes/web.php.
     */
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        [$children, $sort, $direction, $status] = $this->roster();

        $requestUser = request()->user();


        // The whole roll, not the page: "61 active" counted off ten rows would
        // be a different number on every page of the same list.
        $activeCount = Child::visibleTo($requestUser)->where('status', 'Active')->count();

        // Shown only when there are any: a standing "0 pending" is a number
        // that is read once and then stops being looked at.
        $pendingCount = Child::visibleTo($requestUser)->where('status', 'Pending')->count();

        // Every status and how many are in it, for the chips. Counted over the
        // whole roll rather than the filtered view — they are how the filter is
        // chosen, so a count that moved when a chip was pressed would be
        // answering about somewhere else.
        $statusCounts = Child::visibleTo($requestUser)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $rollCount = $statusCounts->sum();

        return view('children.index', compact('children', 'sort', 'direction', 'activeCount', 'pendingCount', 'status', 'statusCounts', 'rollCount'));
    }

    /**
     * The roll as the page is asking for it: filtered, sorted, and narrowed to
     * what this reader may see.
     *
     * Shared with the export rather than written twice. A spreadsheet that
     * quietly held a different set of children from the screen it was
     * downloaded from is the kind of difference nobody notices until it has
     * been sent somewhere.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Collection<int, Child>, 1: string, 2: string, 3: string}
     */
    private function roster(): array
    {
        $allowedSorts = ['lan', 'first_name', 'last_name', 'age', 'classroom', 'status'];
        // The roll opens in LAN order: it is the number on the cabinet, the
        // parent letter and every sheet the centre keeps, so it is what
        // somebody arrives already holding.
        $sort = trim((string) request('sort')) ?: 'lan';
        $direction = trim((string) request('direction')) ?: 'asc';

        abort_unless(in_array($sort, $allowedSorts, true), 404);
        abort_unless(in_array($direction, ['asc', 'desc'], true), 404);

        // Which status the roll is filtered to, or all of them. An invented one
        // is a 404 rather than an empty list: a page that says "no children"
        // when the truth is "no such status" sends somebody looking for a bug
        // in their data.
        $status = trim((string) request('status'));
        abort_unless($status === '' || in_array($status, Child::STATUSES, true), 404);

        $children = Child::query()
            ->visibleTo(request()->user())
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            // Age is shown, not stored, so it sorts by the date it is worked out
            // from — the other way round, since the youngest child is the one
            // with the latest date of birth.
            ->when($sort === 'age', fn ($query) => $query->orderByRaw(
                'COALESCE(birth_date, dob) '.($direction === 'asc' ? 'desc' : 'asc')
            ))
            /*
             * A LAN is stored as a string, because a centre's numbering can
             * carry a prefix and storing it as an integer would destroy one
             * that does. Sorted as a string it reads 1, 10, 100, 1001, 2 —
             * so it is cast for the sort alone. Anything non-numeric casts
             * to nought and falls to the top, which is where a record with a
             * malformed number should be.
             */
            ->when($sort === 'lan', fn ($query) => $query
                ->orderByRaw('CAST(lan AS UNSIGNED) '.$direction)
                ->orderBy('lan', $direction))
            ->when(! in_array($sort, ['age', 'lan'], true), fn ($query) => $query->orderBy($sort, $direction))
            ->when($sort === 'last_name', fn ($query) => $query->orderBy('first_name'))
            // The whole roll on one page. It used to page at ten, which put a
            // sixty-child centre six clicks from the child they were looking
            // for and broke the search box — it could only find children on the
            // page it was on. A roster is read by scrolling, like the sheet.
            ->get();

        return [$children, $sort, $direction, $status];
    }

    /**
     * The roll as a spreadsheet.
     *
     * Named for what it holds and when it was taken, because these end up in a
     * downloads folder beside last month's and the one before that.
     */
    public function export()
    {
        [$children, , , $status] = $this->roster();

        // Everybody attached to the roll, in two queries rather than two per
        // child. Both sheets read these same links.
        $children->load(['personLinks.person']);

        $name = 'children-'.($status !== '' ? strtolower($status).'-' : '').today()->format('Y-m-d').'.xlsx';

        return Excel::download(new ChildrenExport($children, request()->user()?->nameFormat()), $name);
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
    public function store(Request $request, PeopleDirectory $people)
    {
        $child = Child::create($this->validatedData($request));

        $this->syncPhoto($request, $child);

        /*
         * The adults on the form become people, linked to this child.
         *
         * Without this a scanned enrollment filled the mother and father
         * columns and stopped there: the child's page showed two parents while
         * the People step showed nobody, and a mother already on file for an
         * older sibling was not recognised as the same woman.
         *
         * The same reading the migration does, so a form saved today and a
         * form read out of the old columns produce the same people. It matches
         * against everybody on file first, so a second child reuses the
         * parent's record rather than making a second one.
         */
        $fromDocument = filled($request->input('import_token'));

        $people->absorbContactBlocks($child, $fromDocument ? 'pdf_import' : 'manual');

        // A record a model read off handwriting is worth somebody's eye before
        // it is trusted. One typed in by hand has already had it.
        if ($fromDocument) {
            $child->forceFill(['import_status' => 'needs_review'])->save();
        }

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
    public function show(Child $child, PeopleDirectory $people)
    {
        // The same rule the roster list is filtered by, applied to the one
        // record: a teacher may read the children in their own rooms and
        // nobody else's.
        abort_unless($this->isVisibleTo($child, request()->user()), 403);

        return view('children.show', [
            'child' => $child,
            'roomSchedule' => RoomSchedule::byRoom()[$child->classroom] ?? null,
            'back' => $this->backFrom($child),
            // What happened to their place on the roll, newest first. Eager the
            // user, or the panel asks for each name one query at a time.
            'statusChanges' => $child->statusChanges()->with('changedBy')->get(),

            /*
             * Everybody on this child's record, and the two lists drawn from
             * them.
             *
             * All three come from child_people, so the page can no longer
             * disagree with itself or with the People step. It used to read the
             * old mother_* and father_* columns, which nothing writes to any
             * more — a parent unlinked on the People step went on being shown
             * here, and a parent added there never appeared.
             *
             * The lists are queries rather than stored: the pick-up list is the
             * tick minus anyone under a restriction, worked out on every read,
             * so a court order typed a minute ago is on the screen the person
             * at the door is looking at.
             */
            'people' => $people->forChild($child),
            'pickups' => $people->pickupList($child),
            'emergencies' => $people->emergencyList($child),
        ]);
    }

    /**
     * Where "Back" on a child's record goes.
     *
     * Wherever the reader came from, which is the only answer that is right
     * for all of them: the roster, the attendance sheet with a week on it, a
     * search. It used to be the roster by name, so anyone arriving from the
     * sheet pressed a button labelled Roster and lost the week they had open.
     *
     * Three things are refused, each of which would make Back do nothing
     * useful or something surprising:
     *
     *  - another site, because a Back button is not a way off this one;
     *  - this record itself, which is where a saved edit sends the reader, so
     *    Back would reload the page it is on;
     *  - this record's own edit form, for the same reason one step further —
     *    Back would return to the form that has just been left.
     */
    private function backFrom(Child $child): string
    {
        $previous = url()->previous();
        $roster = route('children.index');

        if (! $previous || ! str_starts_with($previous, url('/'))) {
            return $roster;
        }

        $mine = [route('children.show', $child), route('children.edit', $child)];

        foreach ($mine as $own) {
            if (str_starts_with(strtok($previous, '?'), $own)) {
                return $roster;
            }
        }

        return $previous;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Child $child)
    {
        // The same rule the roster is filtered by, asked about one record: a
        // teacher edits the children in their own rooms and nobody else's.
        abort_unless($this->isVisibleTo($child, request()->user()), 403);

        return view('children.edit', compact('child'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Child $child)
    {
        abort_unless($this->isVisibleTo($child, $request->user()), 403);

        $before = $child->scheduleDays();

        $child->update($this->validatedData($request, $child));

        $this->syncPhoto($request, $child);

        /*
         * The days they come, brought through to the weeks that have not
         * happened yet.
         *
         * A week takes its ticks when it is opened, so without this a child
         * registered for every day after the week was opened reads on the
         * sheet as attending none of them — a dot against a day they are
         * booked for. Finished weeks are left alone: those are a record of
         * what happened, and editing a profile in October must not rewrite
         * September.
         */
        $message = 'Child details updated successfully.';

        if ($child->scheduleDays() !== $before) {
            $moved = app(WeekSchedule::class)->resyncRegisteredDays($child);

            if ($moved > 0) {
                $message .= ' '.$moved.' '.\Illuminate\Support\Str::plural('day', $moved)
                    .' on the attendance sheet updated to match.';
            }
        }

        return redirect()->route('children.index')->with('success', $message);
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

        // The hidden empty input in front of the day boxes keeps "cleared" and
        // "untouched" apart, but it also means the array arrives with a stray
        // "" in it whenever anything is ticked. Strip it here so the rules and
        // the column only ever see weekday numbers.
        if (is_array($request->input('schedule_days'))) {
            $request->merge(['schedule_days' => array_values(array_filter(
                $request->input('schedule_days'),
                fn ($day) => $day !== '' && $day !== null
            ))]);
        }
        $rules = [
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
            // Active, Pending or Inactive — the list lives on the model, so the
            // form's options and what the form will accept cannot drift apart.
            'status' => ['required', Rule::in(Child::STATUSES)],

            // What a room has to know before the day starts. A kind and a line
            // apiece; the kind is what colours the chip on the roll.
            'alerts' => ['nullable', 'array', 'max:12'],
            'alerts.*.type' => ['required', Rule::in(array_keys(Child::ALERT_TYPES))],
            'alerts.*.text' => ['nullable', 'string', 'max:120'],
            'enrolled_on' => ['nullable', 'date'],
            'withdrawn_on' => ['nullable', 'date', 'after_or_equal:enrolled_on'],
            // What the parent contracted for. Blank means nobody has said, and
            // the projection then reports the days without claiming a target;
            // zero is the deliberate "not coming" and suppresses it.
            // The days the parent signed up for. The form posts a hidden empty
            // value in front of the boxes so that clearing every day arrives as
            // "no days" rather than as nothing at all — otherwise unticking the
            // last box would be indistinguishable from never touching the field.
            'schedule_days' => ['nullable', 'array'],
            'schedule_days.*' => [Rule::in(array_keys(Child::WEEKDAYS))],
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

        // The alerts arrive as the form drew them, which includes any row
        // somebody opened and then left empty. A chip reading "Allergy:" with
        // nothing after it says a question was answered when it was not, so
        // the blank ones are dropped here rather than filtered on every read.
        //
        // The key is only absent when the form did not carry the field at all;
        // an empty list is somebody clearing the last one, and has to be kept
        // apart from that.
        if (array_key_exists('alerts', $data)) {
            $data['alerts'] = array_values(array_filter(
                array_map(fn ($alert) => [
                    'type' => $alert['type'],
                    'text' => trim((string) ($alert['text'] ?? '')),
                ], $data['alerts'] ?? []),
                fn ($alert) => $alert['text'] !== ''
            ));
        }

        // The LAN is issued here, not accepted from the form. On an existing
        // record it is the one it already has: a LAN is how the paper file
        // names this child, so changing it silently renames them everywhere
        // off-screen. The field is read-only, and this is what makes that true
        // rather than merely apparent — a read-only input is a hint to a
        // browser, not a rule.
        $data['lan'] = $child?->lan ?? Child::nextLan();

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
        $fields = ['dss_case_no','dss_cin','nickname','address','city','zip','telephone','mother_name','mother_address','mother_home_phone','mother_employer','mother_work_phone','mother_fax','mother_cell','mother_email','mother_title','mother_ssn','father_name','father_address','father_home_phone','father_employer','father_work_phone','father_fax','father_cell','father_email','father_title','father_ssn','email_address','parents_status','responsible_for_payment','emergency_contact','secondary_emergency_contact','emergency_telephone','emergency_relationship','emergency_license_number'];
        for ($number = 1; $number <= 3; $number++) foreach (['name','address','telephone','alternate','relationship','license_number'] as $field) $fields[] = "pickup_{$number}_{$field}";
        return $fields;
    }

}
