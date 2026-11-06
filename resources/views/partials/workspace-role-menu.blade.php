@php($workspaceRoles = auth()->user()->assignedRoles())
@if($workspaceRoles->count() > 1)
    <div class="dropdown-header">Switch role</div>
    @foreach($workspaceRoles as $workspaceRole)
        @php($isActiveWorkspaceRole = auth()->user()->activeRole()?->id === $workspaceRole->id)
        <form method="POST" action="{{ route('workspace-role.update') }}" class="m-0">
            @csrf
            <input type="hidden" name="role_id" value="{{ $workspaceRole->id }}">
            <button class="dropdown-item workspace-role-option d-flex align-items-center justify-content-between gap-3" type="submit" @disabled($isActiveWorkspaceRole) @if($isActiveWorkspaceRole) aria-current="true" @endif>
                <span>{{ \App\Support\AccountRoleCatalog::label($workspaceRole) }}</span>
                @if($isActiveWorkspaceRole)<span class="workspace-role-active">Active</span>@endif
            </button>
        </form>
    @endforeach
    <div class="dropdown-divider"></div>
@endif
