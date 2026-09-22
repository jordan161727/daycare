<?php

namespace App\Http\Controllers;

use App\Mail\TeacherWelcomeMail;
use App\Models\Child;
use App\Models\Classroom;
use App\Models\StaffRule;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\ClassroomAssignment;
use App\Services\TemporaryPassword;
use App\Services\TimeClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function index(Request $request, TimeClock $clock)
    {
        $role = trim((string) $request->input('role'));
        $classroom = trim((string) $request->input('classroom'));
        $status = trim((string) $request->input('status'));
        $perPage = (int) $request->input('per_page', 10);

        abort_unless(in_array($perPage, [10, 25, 50, 100], true), 404);
        abort_unless(in_array($status, ['', 'in', 'out'], true), 404);

        $teachers = User::where('role', 'teacher')
            ->when($role !== '', fn ($query) => $query->jobRoleIs($role))
            ->when($classroom !== '', fn ($query) => $query->where('classroom', $classroom))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $today = today()->toDateString();

        $teachers->getCollection()->transform(function (User $teacher) use ($clock, $today) {
            $teacher->students_count = Child::whereIn('classroom', $teacher->assignedClassrooms())->count();

            /*
             * Where they stand right now, and what they last did.
             *
             * Three states, not two: somebody who has not punched at all today
             * is a different fact from somebody who has gone home, and at nine
             * in the morning it is the one a director acts on. Collapsing them
             * into "Out" would hide the person who never arrived.
             */
            $day = $clock->day($teacher->id, $today);

            $teacher->clock_state = $day['first_in'] === null
                ? 'not_in'
                : ($day['open'] ? 'in' : 'out');

            $last = TimePunch::live()
                ->where('user_id', $teacher->id)
                ->orderByDesc('punched_at')
                ->first();

            $teacher->last_activity = $last === null ? null : [
                'type' => $last->type,
                'at' => $last->punched_at,
            ];

            return $teacher;
        });

        // Filtered after the page is built, because the state is worked out
        // from punches rather than held on the row — there is nothing for SQL
        // to filter on. A centre has tens of staff, not thousands.
        if ($status !== '') {
            $teachers->setCollection(
                $teachers->getCollection()->where('clock_state', $status)->values()
            );
        }

        return view('teachers.index', [
            'teachers' => $teachers,
            'role' => $role,
            'classroom' => $classroom,
            'status' => $status,
            'perPage' => $perPage,
            'roles' => User::jobRolesAmong(User::where('role', 'teacher')->get()),
            'classrooms' => ClassroomAssignment::rooms(),
        ]);
    }

    /** The staff list as a spreadsheet, carrying the filters with it. */
    /**
     * The staff record: employment details and every scheduling rule on it.
     *
     * Rules live here rather than on their own screen because they are only
     * ever read in the context of one person — "why is Grace never on Friday
     * afternoons" is a question about Grace, not about the rule table.
     */
    public function show(User $teacher)
    {
        abort_unless($teacher->role === 'teacher', 404);

        $teacher->load('staffRules');

        return view('teachers.show', [
            'teacher' => $teacher,
            'rules' => $teacher->staffRules->sortByDesc(fn ($rule) => $rule->isHard())->values(),
            'colleagues' => User::teachers()->whereKeyNot($teacher->getKey())->pluck('name'),
            'rooms' => ClassroomAssignment::rooms(),
            // Whether the card just issued is still printable. The code behind
            // it is hashed on the way in and cannot be read back, so the image
            // exists only for the few minutes after issuing — see
            // StaffCardController.
            'cardReady' => StaffCardController::pendingFor($teacher),
        ]);
    }

    public function create()
    {
        return view('teachers.create', ['teacher' => new User(), 'classrooms' => $this->classrooms()]);
    }

    /**
     * Open a staff account.
     *
     * The usual path is that nobody types a password: one is generated, mailed
     * to the teacher, and marked as needing replacement at first sign-in — so
     * the password guarding the account is never one the director knows. A
     * director who would rather hand the password over in person can still
     * type one, and then no email is sent.
     */
    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $temporary = null;

        if (! isset($data['password'])) {
            $temporary = TemporaryPassword::generate();
            $data['password'] = Hash::make($temporary);
            $data['must_change_password'] = true;
        }

        $teacher = User::create($data);

        if ($temporary === null) {
            return redirect()->route('teachers.index')->with('success', 'Teacher account created successfully.');
        }

        try {
            Mail::to($teacher->email)->send(new TeacherWelcomeMail($teacher, $temporary));
        } catch (\Throwable $e) {
            // The account exists either way, so the director needs the password
            // in front of them rather than a dead end — they can read it out and
            // the teacher still has to replace it on the way in.
            report($e);

            return redirect()->route('teachers.index')
                ->with('error', "Account created, but the email to {$teacher->email} could not be sent. Temporary password: {$temporary}");
        }

        return redirect()->route('teachers.index')
            ->with('success', "Teacher account created. A temporary password has been emailed to {$teacher->email}.");
    }

    public function edit(User $teacher)
    {
        abort_unless($teacher->role === 'teacher', 404);

        return view('teachers.edit', ['teacher' => $teacher, 'classrooms' => $this->classrooms()]);
    }

    public function update(Request $request, User $teacher)
    {
        abort_unless($teacher->role === 'teacher', 404);
        $teacher->update($this->validatedData($request, $teacher));

        return redirect()->route('teachers.index')->with('success', 'Teacher account updated successfully.');
    }

    public function destroy(User $teacher)
    {
        abort_unless($teacher->role === 'teacher', 404);
        $teacher->delete();

        return redirect()->route('teachers.index')->with('success', 'Teacher account deleted successfully.');
    }

    private function classrooms()
    {
        return Classroom::query()->pluck('name')
            ->merge(Child::query()->whereNotNull('classroom')->where('classroom', '!=', '')
                ->distinct()->pluck('classroom'))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    private function validatedData(Request $request, ?User $teacher = null): array
    {
        // Blank is fine everywhere except a new account the director has chosen
        // to set the password on themselves — there, an empty box is a slip,
        // not a request for a generated one.
        $settingByHand = ! $teacher && ! $request->boolean('send_invite');

        $passwordRules = $settingByHand
            ? ['required', 'string', 'min:8', 'confirmed']
            : ['nullable', 'string', 'min:8', 'confirmed'];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($teacher)],
            'classrooms' => ['nullable', 'array'],
            'classrooms.*' => ['string', 'max:255'],
            'password' => $passwordRules,

            // Employment side. All optional — a teacher account is useful the
            // moment it can log in, and the scheduler falls back to sensible
            // defaults for anything left blank.
            'employment' => ['nullable', Rule::in(StaffRule::EMPLOYMENT)],
            'job_role' => ['nullable', Rule::in(User::JOB_ROLES)],
            'title' => ['nullable', Rule::in(ClassroomAssignment::rooms())],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'start_date' => ['nullable', 'date'],
            'dob' => ['nullable', 'date', 'before:today'],
            'transport' => ['nullable', 'string', 'max:255'],
            'aspire_id' => ['nullable', 'string', 'max:40'],
            'direct_deposit' => ['nullable', 'boolean'],
            'pay_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'evaluation_score' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'staff_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['direct_deposit'] = $request->boolean('direct_deposit');

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        $data['role'] = 'teacher';
        $data['classrooms'] = $this->normalizeAssignedClassrooms($data['classrooms'] ?? []);
        $data['classroom'] = $data['classrooms'][0] ?? null;

        return $data;
    }

    private function normalizeAssignedClassrooms(array $classrooms): array
    {
        return array_values(array_filter(array_unique(array_map(static fn (string $value): string => trim($value), $classrooms))));
    }
}
