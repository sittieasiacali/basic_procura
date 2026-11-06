@extends('layouts.app')

@section('title', $account->name)

@section('content')
    <x-ui.page-header eyebrow="Account record" title="{{ $account->name }}" description="{{ $account->email }}">
        <x-slot:actions><div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('admin.accounts.index') }}">Manage Accounts</a>
            <a class="btn btn-primary" href="{{ route('admin.accounts.edit', $account) }}">Edit Account</a>
            <a class="btn btn-outline-danger" href="{{ route('admin.data.index', ['type' => 'accounts', 'search' => $account->id]) }}">Delete Account</a>
        </div></x-slot:actions>
    </x-ui.page-header>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card h-100"><div class="card-header"><div class="section-kicker">Identity</div><h2 class="section-title">Account Details</h2></div><div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6"><div class="text-muted small">Full name</div><div>{{ $account->name }}</div></div>
                    <div class="col-sm-6"><div class="text-muted small">Employee ID</div><div>{{ $account->employee_id ?: 'Not recorded' }}</div></div>
                    <div class="col-sm-6"><div class="text-muted small">Username</div><div>{{ $account->username ?: 'Not assigned' }}</div></div>
                    <div class="col-sm-6"><div class="text-muted small">Team ID</div><div>{{ $account->team_id ?: 'Not assigned' }}</div></div>
                    <div class="col-sm-6"><div class="text-muted small">Account email (sign-in)</div><div>{{ $account->email }}</div></div>
                    @if($account->hasAnyRole([\App\Enums\RoleSlug::Requestor->value, \App\Enums\RoleSlug::TeamLeader->value]))
                        <div class="col-sm-6"><div class="text-muted small">OM notification email</div><div>{{ $account->om_notification_email ?: 'Not assigned' }}</div></div>
                        <div class="col-sm-6"><div class="text-muted small">SMT notification email</div><div>{{ $account->smt_notification_email ?: 'Not assigned' }}</div></div>
                    @endif
                    <div class="col-sm-6"><div class="text-muted small">Assigned roles</div><x-role-badges :user="$account" /></div>
                    <div class="col-sm-6"><div class="text-muted small">Assigned campaign</div><div>{{ $account->campaign?->name ?? 'Not assigned' }}</div></div>
                    <div class="col-sm-6"><div class="detail-label">Status</div><span class="status-badge {{ $account->is_active ? 'status-completed' : 'status-neutral' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span></div>
                    <div class="col-sm-6"><div class="text-muted small">Date created</div><div>{{ $account->created_at->format('M d, Y g:i A') }}</div></div>
                </div>
            </div></div>
        </div>
        <div class="col-lg-5">
            <div class="card action-panel h-100"><div class="card-header"><div class="section-kicker">Security</div><h2 class="section-title">Account Access</h2></div><div class="card-body">
                <p class="text-muted">{{ $account->is_active ? 'This account can currently sign in.' : 'This account is blocked from signing in.' }}</p>
                @if($account->must_change_password)<p class="small text-muted">The temporary password must be replaced at the next sign-in.</p>@endif
                @if($account->hasAnyRole([\App\Enums\RoleSlug::PoChecker->value, \App\Enums\RoleSlug::PoNoted->value, \App\Enums\RoleSlug::PoApprover->value]))
                    <p class="small">Select this account in <a href="{{ route('admin.po-workflow-assignments.index') }}">PO Workflow Assignments</a> to route new Purchase Orders to it.</p>
                @endif
                <form method="POST" action="{{ route('admin.accounts.status', $account) }}">
                    @csrf @method('PATCH')
                    <button class="btn {{ $account->is_active ? 'btn-outline-danger' : 'btn-success' }}" type="submit" onclick="return confirm('{{ $account->is_active ? 'Deactivate' : 'Activate' }} this account?')">{{ $account->is_active ? 'Deactivate Account' : 'Activate Account' }}</button>
                </form>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="section-kicker">Traceability</div><h2 class="section-title">Account Audit History</h2></div>
        <div class="card-body workflow-timeline">
            @forelse($account->auditLogs->sortByDesc('created_at') as $log)
                <div class="workflow-entry">
                    <div class="d-flex flex-wrap justify-content-between gap-2"><strong>{{ str($log->action)->replace('_', ' ')->title() }}</strong><span class="small text-muted">{{ $log->created_at->format('M d, Y g:i A') }}</span></div>
                    <div class="small text-muted">Performed by {{ $log->user?->name ?? 'System' }}</div>
                    @if($log->previous_status || $log->new_status)<div class="small">{{ $log->previous_status ?? '—' }} → {{ $log->new_status ?? '—' }}</div>@endif
                </div>
            @empty
                <x-ui.empty-state icon="logs" title="No audit history" description="No account changes have been recorded." />
            @endforelse
        </div>
    </div>
@endsection
