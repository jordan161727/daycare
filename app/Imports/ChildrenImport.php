<?php

namespace App\Imports;

use App\Models\Child;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ChildrenImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var array<int, string> */
    public array $errors = [];

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // The first row contains headings.
            $data = [
                'lan' => trim((string) ($row['lan'] ?? '')),
                'status' => trim((string) ($row['status'] ?? '')),
                'first_name' => trim((string) ($row['first_name'] ?? '')),
                'last_name' => trim((string) ($row['last_name'] ?? '')),
                'dob' => $row['dob'] ?? null,
                'age' => $row['age'] ?? null,
                'classroom' => trim((string) ($row['classroom'] ?? '')),
            ];

            if (collect($data)->except('dob')->filter()->isEmpty() && blank($data['dob'])) {
                continue;
            }

            if (strtolower($data['status']) !== 'active') {
                $this->skipped++;

                continue;
            }

            try {
                $data['dob'] = $this->formatDate($data['dob']);
            } catch (\Throwable) {
                $this->errors[] = "Row {$rowNumber}: the date of birth is invalid.";

                continue;
            }

            $validator = Validator::make($data, [
                'lan' => ['required', 'string', 'max:255'],
                'status' => ['required', 'string', 'max:255'],
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'dob' => ['nullable', 'date'],
                'age' => ['nullable', 'string', 'min:0', 'max:99'],
                'classroom' => ['required', 'string', 'max:255'],
            ]);

            if ($validator->fails()) {
                $this->errors[] = "Row {$rowNumber}: ".implode(' ', $validator->errors()->all());

                continue;
            }

            $exists = Child::where('lan', $data['lan'])->exists();

            Child::updateOrCreate(

                [
                    'lan' => $data['lan'],
                ],

                [
                    'status' => $data['status'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'dob' => $data['dob'],
                    'age' => $data['age'],
                    'classroom' => $data['classroom'],
                ]
            );

            $exists ? $this->updated++ : $this->created++;
        }
    }

    private function formatDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::instance(Date::excelToDateTimeObject((float) $value))->toDateString();
        }

        return Carbon::parse($value)->toDateString();
    }
}
