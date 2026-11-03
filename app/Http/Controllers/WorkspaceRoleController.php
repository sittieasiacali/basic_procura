<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceRoleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_active, 403);
        $validated = $request->validate(['role_id' => ['required', 'integer']]);
        $role = $request->user()->assignedRoles()->firstWhere('id', (int) $validated['role_id']);

        if (! $role) {
            throw ValidationException::withMessages(['role_id' => 'Choose one of your assigned roles.']);
        }

        $request->session()->put('workspace_role_id', $role->id);

        return redirect()->route('dashboard')->with('success', 'Workspace changed to '.$role->name.'.');
    }
}
