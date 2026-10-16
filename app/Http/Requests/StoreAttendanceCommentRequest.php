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

    // A note can be added at any point in the day — before the child is
    // clocked out, and after: a parent who rings at six to say the child
    // was sick on the way home is a note on today, and the row is still
    // today's. The centre asked for this on 16 Oct 2026; until then a
    // clocked-out row refused new notes.
}
