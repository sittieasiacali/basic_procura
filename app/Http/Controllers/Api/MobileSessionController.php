<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class MobileSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:100'], 'role_id' => ['nullable', 'integer'],
        ]);
        $login = trim($data['login']);
        $user = User::query()->where(str_contains($login, '@') ? 'email' : 'username', $login)->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'Invalid credentials or inactive account.']);
        }
        $role = $user->assignedRoles()->firstWhere('id', $data['role_id'] ?? $user->role_id);
        abort_unless($role, 403, 'Choose an assigned role.');
        $user->useWorkspaceRole($role);
        $token = $user->createToken($data['device_name'], ['procura-chat', 'role:'.$role->id], now()->addDays(config('procura_chat.token_days')));

        return response()->json(['token' => $token->plainTextToken, 'user' => $this->profile($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function role(Request $request): JsonResponse
    {
        $data = $request->validate(['role_id' => ['required', 'integer']]);
        $role = $request->user()->assignedRoles()->firstWhere('id', $data['role_id']);
        abort_unless($role, 403);
        $request->user()->currentAccessToken()->forceFill(['abilities' => ['procura-chat', 'role:'.$role->id]])->save();
        $request->user()->useWorkspaceRole($role);

        return response()->json($this->profile($request->user()));
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'], 'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        DB::transaction(function () use ($request, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($data['current_password'], $user->password) || Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'Enter your current password and choose a different new password.']);
            }
            $user->forceFill(['password' => $data['password'], 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();
            $user->auditLogs()->create(['user_id' => $user->id, 'action' => 'MOBILE_PASSWORD_CHANGED', 'ip_address' => $request->ip()]);
        });

        return response()->json(['message' => 'Password changed.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    private function profile(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'campaign' => $user->campaign?->name,
            'role_id' => $user->activeRole()?->id, 'role' => $user->activeRole()?->slug,
            'roles' => $user->assignedRoles()->map->only(['id', 'name', 'slug'])->values(),
            'must_change_password' => $user->must_change_password];
    }
}
