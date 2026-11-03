@php($workspaceRoles = auth()->user()->assignedRoles())
@if($workspaceRoles->count() > 1)
    <div class="dropdown-header">Switch role</div>
    @foreach($workspaceRoles as $workspaceRole)
        <form method="POST" action="{{ route('workspace-role.update') }}">
            @csrf
            <input type="hidden" name="role_id" value="{{ $workspaceRole->id }}">
            <button class="dropdown-item d-flex align-items-center justify-content-between gap-3" type="submit" @disabled(auth()->user()->activeRole()?->id === $workspaceRole->id)>
                <span>{{ \App\Support\AccountRoleCatalog::label($workspaceRole) }}</span>
                @if(auth()->user()->activeRole()?->id === $workspaceRole->id)<span class="small">Active</span>@endif
            </button>
        </form>
    @endforeach
    <div class="dropdown-divider"></div>
@endif
