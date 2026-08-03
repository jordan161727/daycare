<?php

namespace App\Http\Controllers;

use App\Imports\ChildrenImport;
use App\Models\Child;
use Illuminate\Http\Request;
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
        $sort = request('sort', 'last_name');
        $direction = request('direction', 'asc');

        abort_unless(in_array($sort, $allowedSorts, true), 404);
        abort_unless(in_array($direction, ['asc', 'desc'], true), 404);

        $children = Child::query()
            ->visibleTo($requestUser = request()->user())
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
        if ($request->filled('birth_date')) {
            $request->merge(['birth_date' => $this->normalizeDate($request->input('birth_date'))]);
        }

        $rules = [
            'lan' => ['required', 'string', 'max:255', Rule::unique('children', 'lan')->ignore($child)],
            'child_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'dob' => ['nullable', 'date'],
            'age' => ['nullable', 'string', 'max:50'],
            'classroom' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'birth_date' => ['nullable', 'date'],
            'other_notes' => ['nullable', 'string'],
            'important_notes' => ['nullable', 'string'],
        ];

        foreach ($this->enrollmentFields() as $field) {
            $rules[$field] ??= ['nullable', 'string', 'max:255'];
        }

        $data = $request->validate($rules);

        if (blank($data['age'] ?? null) && filled($data['birth_date'] ?? null)) {
            $data['age'] = $this->ageLabel(Carbon::parse($data['birth_date']));
        }

        return $data;
    }

    private function ageLabel(Carbon $birthDate): string
    {
        $difference = $birthDate->startOfDay()->diff(today()->startOfDay());
        $years = $difference->y;
        $months = $difference->m;

        if ($years === 0) return $months.' '.($months === 1 ? 'month' : 'months');
        if ($months === 0) return $years.' '.($years === 1 ? 'year' : 'years');

        return $years.' '.($years === 1 ? 'year' : 'years').' and '.$months.' '.($months === 1 ? 'month' : 'months');
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

    private function enrollmentFields(): array
    {
        $fields = ['nickname','address','city','zip','telephone','mother_name','mother_address','mother_home_phone','mother_employer','mother_work_phone','mother_fax','mother_cell','mother_email','mother_title','mother_ssn','father_name','father_address','father_home_phone','father_employer','father_work_phone','father_fax','father_cell','father_email','father_title','father_ssn','email_address','parents_status','responsible_for_payment','emergency_contact','secondary_emergency_contact','emergency_telephone','emergency_relationship','emergency_license_number'];
        for ($number = 1; $number <= 3; $number++) foreach (['name','address','telephone','alternate','relationship','license_number'] as $field) $fields[] = "pickup_{$number}_{$field}";
        return $fields;
    }

}
