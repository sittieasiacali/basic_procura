<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        return $request->user()->must_change_password
            ? view('auth.change-password')
            : redirect()->route('dashboard');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->must_change_password, 403);
        $validated = $request->validate(['password' => ['required', 'string', 'confirmed', Password::min(8)]]);

        $account = DB::transaction(function () use ($request, $validated): User {
            $account = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($account->must_change_password, 409, 'Your password has already been changed.');
            if (Hash::check($validated['password'], $account->password)) {
                throw ValidationException::withMessages(['password' => 'Choose a password different from your temporary password.']);
            }

            $account->forceFill([
                'password' => Hash::make($validated['password']),
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();
            $account->auditLogs()->create([
                'user_id' => $account->id,
                'action' => 'FIRST_SIGN_IN_PASSWORD_CHANGED',
                'remarks' => 'Temporary password replaced by the account owner.',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $account;
        });

        Auth::setUser($account);
        $request->session()->regenerate(true);

        return redirect()->route('dashboard')->with('success', 'Your password has been changed. Welcome to PROCURA.');
    }
}
