@php($workspaceRoles = auth()->user()->availableWorkspaceRoles())
@if(auth()->user()->isAdministrator())
    <a class="dropdown-item" href="{{ route('admin.data.index') }}">Manage Data</a>
    <div class="dropdown-divider"></div>
@endif
@if($workspaceRoles->count() > 1)
    <div class="dropdown-header">Switch role</div>
    <div class="overflow-auto" style="max-height: 50vh;" role="group" aria-label="Available workspaces">
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
    </div>
    <div class="dropdown-divider"></div>
@endif
