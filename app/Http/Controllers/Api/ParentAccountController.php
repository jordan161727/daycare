<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParentAccount;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A parent's own account in the app (19 Nov 2026): after the first texted
 * code they choose a username and password, then a photo and the name to
 * show. docs/parent-app-api.md §4.18. Every answer is the same profile as
 * GET /me, so the app has one shape to keep.
 */
class ParentAccountController extends Controller
{
    /** How long after a code sign-in a new password needs no old one. */
    public const RESET_MINUTES = 15;

    public function __construct(private ParentAppController $parents) {}

    /** Signed in by a texted code within the last few minutes ("Forgot password?"). */
    public static function freshCodeToken(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken
            && $token->can('code')
            && $token->created_at?->gt(now()->subMinutes(self::RESET_MINUTES));
    }

    /** POST /account — choose a username and password, once. */
    public function store(Request $request): JsonResponse
    {
        $person = $this->person($request);

        if ($person->parentAccount()->exists()) {
            return response()->json(['message' => 'Your account is already set up.'], 409);
        }

        $request->merge(['username' => ParentAccount::normalise((string) $request->input('username'))]);
        $data = $request->validate([
            'username' => $this->usernameRules(),
            'password' => ['required', 'confirmed', 'max:200', Password::min(8)->letters()->numbers()],
        ], $this->messages());

        $person->parentAccount()->create(['username' => $data['username'], 'password' => $data['password']]);

        return $this->profile($request, 201);
    }

    /** PATCH /account — the name to show, an email, a new username; "profile_done" ends setup. */
    public function update(Request $request): JsonResponse
    {
        $account = $this->account($request);

        if ($request->has('username')) {
            $request->merge(['username' => ParentAccount::normalise((string) $request->input('username'))]);
        }
        $data = $request->validate([
            'username' => ['sometimes', ...$this->usernameRules($account->id)],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'profile_done' => ['sometimes', 'boolean'],
        ], $this->messages());

        if (array_key_exists('display_name', $data)) {
            $data['display_name'] = trim((string) $data['display_name']) ?: null;
        }
        if (! empty($data['profile_done'])) {
            $data['profile_done_at'] = $account->profile_done_at ?? now();
        }
        unset($data['profile_done']);

        $account->update($data);

        return $this->profile($request);
    }

    /** POST /account/photo — a new profile photo (multipart, "photo"). */
    public function photo(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'photo' => ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/heic'],
        ]);

        // Beside the parent's message photos, so the same signed link serves it.
        $path = $data['photo']->store('messages/media/parent-'.$account->person_id.'/profile', 'local');
        $old = $account->photo_path;
        $account->update(['photo_path' => $path]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $this->profile($request);
    }

    /** DELETE /account/photo */
    public function deletePhoto(Request $request): JsonResponse
    {
        $account = $this->account($request);
        if ($account->photo_path) {
            Storage::disk('local')->delete($account->photo_path);
            $account->update(['photo_path' => null]);
        }

        return $this->profile($request);
    }

    /**
     * PUT /account/password — needs the current password, unless the parent
     * signed in by a texted code in the last few minutes. Signs out every
     * other phone.
     */
    public function password(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $reset = self::freshCodeToken($request);

        $data = $request->validate([
            'current_password' => [$reset ? 'nullable' : 'required', 'string', 'max:200'],
            'password' => ['required', 'confirmed', 'max:200', Password::min(8)->letters()->numbers()],
        ], $this->messages());

        if (! $reset && ! Hash::check($data['current_password'], $account->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is wrong.']);
        }

        $account->update(['password' => $data['password']]);

        $current = $request->user()->currentAccessToken();
        $request->user()->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();

        return $this->profile($request);
    }

    /* ---------------------------------------------------------------- */

    private function person(Request $request): Person
    {
        return $request->user();
    }

    private function account(Request $request): ParentAccount
    {
        $account = $this->person($request)->parentAccount;
        abort_unless($account, 409, 'Set up your account first.');

        return $account;
    }

    private function profile(Request $request, int $status = 200): JsonResponse
    {
        $person = $this->person($request)->fresh();

        return response()->json($this->parents->profile($person, self::freshCodeToken($request)), $status);
    }

    private function usernameRules(?int $ignoreId = null): array
    {
        return ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9][a-z0-9._]*$/', Rule::unique('parent_accounts', 'username')->ignore($ignoreId)];
    }

    private function messages(): array
    {
        return [
            'username.regex' => 'Use letters, numbers, dots or underscores, starting with a letter or number.',
            'username.unique' => 'That username is taken. Try another.',
            'password.confirmed' => 'The two passwords don’t match.',
        ];
    }
}
