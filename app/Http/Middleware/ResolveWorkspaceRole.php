<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveWorkspaceRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $user->load(['role', 'roles']);
            $role = $user->assignedRoles()->firstWhere('id', $request->session()->get('workspace_role_id'));
            $user->useWorkspaceRole($role);
            // Revalidate on every request so removed roles lose access immediately.
            if (! $role) {
                $request->session()->forget('workspace_role_id');
            }
        }

        return $next($request);
    }
}
