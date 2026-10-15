<?php

namespace App\Http\Requests;

use App\Enums\CommentTag;
use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAttendanceCommentRequest extends FormRequest
{
    /** Who may is the policy's call — see the controller. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body', ''))]);
    }

    public function rules(): array
    {
        return [
            'tag' => ['required', Rule::enum(CommentTag::class)],
            'body' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Write the comment first.',
            'body.max' => 'Keep the comment under 1,000 characters.',
            'tag.required' => 'Pick what kind of note this is.',
        ];
    }

    /**
     * A day that is over takes no more notes. What was written stays
     * readable; nothing new goes on a child who has gone home.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $attendance = $this->route('attendance');

            if ($attendance instanceof Attendance && ! $attendance->isSignedIn()) {
                $validator->errors()->add('body', 'This child has been clocked out.');
            }
        });
    }
}
