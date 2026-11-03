@extends('layouts.app')

@section('title', $campaign->name)

@section('content')
    <x-ui.page-header eyebrow="Campaign record" title="{{ $campaign->name }}" description="{{ $campaign->description ?: 'No description provided.' }}">
        <x-slot:actions><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="{{ route('admin.campaigns.index') }}">Campaigns</a><a class="btn btn-primary" href="{{ route('admin.campaigns.edit', $campaign) }}">Edit Campaign</a></div></x-slot:actions>
    </x-ui.page-header>

    @error('is_active')<div class="alert alert-warning">{{ $message }}</div>@enderror

    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card kpi-card h-100"><div class="card-body"><div class="kpi-label">Assigned users</div><div class="kpi-value">{{ $campaign->users_count }}</div></div></div></div>
        <div class="col-md-4"><div class="card kpi-card h-100"><div class="card-body"><div class="kpi-label">Active assigned users</div><div class="kpi-value">{{ $campaign->active_users_count }}</div></div></div></div>
        <div class="col-md-4"><div class="card kpi-card h-100"><div class="card-body"><div class="kpi-label">Created</div><div class="fw-semibold mt-2">{{ $campaign->created_at->format('M d, Y g:i A') }}</div></div></div></div>
    </div>

    <div class="card mb-3"><div class="card-body"><div class="detail-label">Campaign account number</div><div class="fw-semibold">{{ $campaign->account_number ?: 'Not configured' }}</div></div></div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center"><div><div class="section-kicker">Membership</div><h2 class="section-title">Assigned Accounts</h2></div>
            <form method="POST" action="{{ route('admin.campaigns.status', $campaign) }}">@csrf @method('PATCH')<button class="btn btn-sm {{ $campaign->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit" @disabled($campaign->is_active && $campaign->active_users_count > 0) onclick="return confirm('{{ $campaign->is_active ? 'Deactivate' : 'Activate' }} this campaign?')">{{ $campaign->is_active ? 'Deactivate Campaign' : 'Activate Campaign' }}</button></form>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0"><thead class="table-light"><tr><th>Full name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody>
            @forelse($users as $account)
                <tr><td>{{ $account->name }}</td><td>{{ $account->email }}</td><td>{{ $account->role?->name ?? 'Unassigned' }}</td><td><span class="status-badge {{ $account->is_active ? 'status-completed' : 'status-neutral' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.accounts.show', $account) }}">View Account</a></td></tr>
            @empty
                <tr><td colspan="5"><x-ui.empty-state icon="users" title="No assigned accounts" description="No accounts are assigned to this campaign." /></td></tr>
            @endforelse
            </tbody></table>
        </div>
        @if($users->hasPages())<div class="card-footer">{{ $users->links() }}</div>@endif
    </div>

    <div class="card"><div class="card-header"><div class="section-kicker">Traceability</div><h2 class="section-title">Campaign Audit History</h2></div><div class="card-body workflow-timeline">
        @forelse($campaign->auditLogs->sortByDesc('created_at') as $log)
            <div class="workflow-entry"><div class="d-flex flex-wrap justify-content-between gap-2"><strong>{{ str($log->action)->replace('_', ' ')->title() }}</strong><span class="small text-muted">{{ $log->created_at->format('M d, Y g:i A') }}</span></div><div class="small text-muted">Performed by {{ $log->user?->name ?? 'System' }}</div>@if($log->previous_status || $log->new_status)<div class="small">{{ $log->previous_status ?? '—' }} → {{ $log->new_status ?? '—' }}</div>@endif</div>
        @empty<x-ui.empty-state icon="logs" title="No audit history" description="No campaign changes have been recorded." />@endforelse
    </div></div>
@endsection
