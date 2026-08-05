<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function index()
    {
        $teachers = User::where('role', 'teacher')
            ->orderBy('name')
            ->paginate(10);

        $teachers->getCollection()->transform(function (User $teacher) {
            $teacher->students_count = Child::whereIn('classroom', $teacher->assignedClassrooms())->count();
            return $teacher;
        });

        return view('teachers.index', compact('teachers'));
    }

    public function create()
    {
        return view('teachers.create', ['teacher' => new User(), 'classrooms' => $this->classrooms()]);
    }

    public function store(Request $request)
    {
        User::create($this->validatedData($request));

        return redirect()->route('teachers.index')->with('success', 'Teacher account created successfully.');
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
        $passwordRules = $teacher
            ? ['nullable', 'string', 'min:8', 'confirmed']
            : ['required', 'string', 'min:8', 'confirmed'];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($teacher)],
            'classrooms' => ['nullable', 'array'],
            'classrooms.*' => ['string', 'max:255'],
            'password' => $passwordRules,
        ]);

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
