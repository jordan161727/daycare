<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Choosing your own password — the first thing a new staff member does, and
 * the only screen they can reach until they have.
 */
class PasswordController extends Controller
{
    public function edit(Request $request)
    {
        return view('auth.change-password', [
            'forced' => $request->user()->mustChangePassword(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers(), 'different:current_password'],
        ], [
            'password.different' => 'Your new password has to be different from the temporary one.',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'That is not your current password.']);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // The password that opened this session is now in somebody's inbox, so
        // the session id it produced does not get to outlive it.
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Your password has been updated.');
    }
}
