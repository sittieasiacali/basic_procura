<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureProcuraMobileAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403, 'This account is inactive.');
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('procura-chat'), 403);
        $roleId = collect($token->abilities)->first(fn ($ability) => str_starts_with($ability, 'role:'));
        $role = $user->assignedRoles()->firstWhere('id', (int) substr((string) $roleId, 5));
        abort_unless($role, 403, 'Your role assignment has changed. Please sign in again.');
        $user->useWorkspaceRole($role);
        if ($user->must_change_password && ! $request->routeIs('mobile.password', 'mobile.logout', 'mobile.me')) {
            return response()->json(['message' => 'Change your temporary password before continuing.', 'must_change_password' => true], 423);
        }

        return $next($request);
    }
}
