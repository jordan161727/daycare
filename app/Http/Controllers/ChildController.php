<?php

namespace App\Http\Controllers;

use App\Imports\ChildrenImport;
use App\Models\Child;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChildController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $allowedSorts = ['lan', 'first_name', 'last_name', 'age', 'classroom', 'status'];
        $sort = request('sort', 'last_name');
        $direction = request('direction', 'asc');

        abort_unless(in_array($sort, $allowedSorts, true), 404);
        abort_unless(in_array($direction, ['asc', 'desc'], true), 404);

        $children = Child::query()
            ->orderBy($sort, $direction)
            ->when($sort === 'last_name', fn ($query) => $query->orderBy('first_name'))
            ->paginate(10)
            ->withQueryString();

        return view('children.index', compact('children', 'sort', 'direction'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('children.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        Child::create($this->validatedData($request));

        return redirect()->route('children.index')->with('success', 'Child added successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Child $child)
    {
        //
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

        return redirect()->route('children.index')->with('success', 'Child details updated successfully.');
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
        return $request->validate([
            'lan' => ['required', 'string', 'max:255', Rule::unique('children', 'lan')->ignore($child)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'dob' => ['nullable', 'date'],
            'age' => ['nullable', 'integer', 'min:0', 'max:18'],
            'classroom' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ]);
    }

}
