<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The signed-in user's own account page. Everyone gets one — a teacher can fix
 * their own name or photo without waiting on the director — but nobody edits
 * anyone else's account here; classroom assignments and roles stay with the
 * admin screens under /teachers.
 */
class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:40'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'dob' => ['nullable', 'date', 'before:today'],
            'transport' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'photo.max' => 'The photo may not be larger than 2 MB.',
            'dob.before' => 'Your date of birth must be in the past.',
        ], [
            'dob' => 'date of birth',
            'emergency_contact' => 'emergency contact',
            'emergency_phone' => 'emergency contact number',
        ]);

        if ($request->hasFile('photo')) {
            $data['avatar_path'] = $this->storePhoto($request, $user);
        }

        unset($data['photo']);

        $user->update($data);

        return redirect()->route('profile.edit')->with('success', 'Your profile has been updated.');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ], [
            'current_password' => 'current password',
        ]);

        $request->user()->update(['password' => Hash::make($request->input('password'))]);

        // A fresh session id, so a stolen one from before the change is dead.
        $request->session()->regenerate();

        return redirect()->route('profile.edit')->with('success', 'Your password has been changed.');
    }

    public function destroyPhoto(Request $request)
    {
        $user = $request->user();

        $this->deletePhoto($user);
        $user->update(['avatar_path' => null]);

        return redirect()->route('profile.edit')->with('success', 'Your photo has been removed.');
    }

    /** Saves the upload under a name of our own and drops the one it replaces. */
    private function storePhoto(Request $request, User $user): string
    {
        $this->deletePhoto($user);

        return $request->file('photo')->store('avatars', 'public');
    }

    private function deletePhoto(User $user): void
    {
        if (filled($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }
    }
}
