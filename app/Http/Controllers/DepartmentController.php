<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The parts of the centre, and who is in them.
 *
 * Director only, alongside the rest of the staff screens: a department is a
 * line on the org chart and a grouping every hours report totals by, so who
 * may create one is the same question as who may set a pay rate.
 */
class DepartmentController extends Controller
{
    public function index()
    {
        return view('departments.index', [
            // The count is the only thing the list is ever read for beyond the
            // names, and it is also what makes deleting one a decision rather
            // than a click.
            'departments' => Department::withCount('staff')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('departments.create', ['department' => new Department]);
    }

    public function store(Request $request)
    {
        Department::create($this->validated($request));

        return redirect()->route('departments.index')->with('status', 'Department added.');
    }

    public function edit(Department $department)
    {
        return view('departments.edit', ['department' => $department]);
    }

    public function update(Request $request, Department $department)
    {
        $department->update($this->validated($request, $department));

        return redirect()->route('departments.index')->with('status', 'Department updated.');
    }

    /**
     * Close a department.
     *
     * The people in it are kept and their department is cleared — see the
     * migration's nullOnDelete. Closing the kitchen is an org change, not a
     * reason to lose the cook's record and the hours attached to it.
     */
    public function destroy(Department $department)
    {
        $department->delete();

        return redirect()->route('departments.index')->with('status', 'Department removed. Anyone in it is now unassigned.');
    }

    private function validated(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            // Unique because the whole point is grouping: two departments both
            // called Kitchen split every report down the middle, and nothing
            // on screen would say why.
            'name' => ['required', 'string', 'max:120', Rule::unique('departments')->ignore($department)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
