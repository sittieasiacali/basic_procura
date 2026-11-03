@props(['user'])
<div class="d-flex flex-wrap gap-1">
    @forelse($user->assignedRoles() as $assignedRole)
        <span class="badge text-bg-light border">{{ \App\Support\AccountRoleCatalog::label($assignedRole) }}</span>
    @empty
        <span class="text-muted">Unassigned</span>
    @endforelse
</div>
