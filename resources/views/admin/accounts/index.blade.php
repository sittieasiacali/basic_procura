@extends('layouts.app')

@section('title', 'Manage Accounts')

@section('content')
    <x-ui.page-header eyebrow="Administration" title="Manage Accounts" description="Create accounts, assign roles and campaigns, and control access.">
        <x-slot:actions><button class="btn btn-primary d-inline-flex align-items-center gap-2" type="button" data-bs-toggle="modal" data-bs-target="#add-new-user-modal"><x-ui.icon name="plus" size="16" />Add New User</button></x-slot:actions>
    </x-ui.page-header>

    <div class="card filter-panel mb-3">
        <div class="card-header"><div class="section-kicker">Directory tools</div><h2 class="section-title">Filter accounts</h2></div>
        <div class="card-body">
            <form class="row g-3 align-items-end" method="GET">
                <div class="col-lg-4">
                    <label class="form-label" for="search">Search</label>
                    <input class="form-control" id="search" name="search" value="{{ request('search') }}" placeholder="Name, email, or employee ID">
                </div>
                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="role_id">Role</label>
                    <select class="form-select" id="role_id" name="role_id">
                        <option value="">All roles</option>
                        @foreach($roles as $role)<option value="{{ $role->id }}" @selected((string) request('role_id') === (string) $role->id)>{{ $role->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="campaign_id">Campaign</label>
                    <select class="form-select" id="campaign_id" name="campaign_id">
                        <option value="">All campaigns</option>
                        @foreach($campaigns as $campaign)<option value="{{ $campaign->id }}" @selected((string) request('campaign_id') === (string) $campaign->id)>{{ $campaign->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="is_active">Status</label>
                    <select class="form-select" id="is_active" name="is_active">
                        <option value="">All statuses</option>
                        <option value="1" @selected(request('is_active') === '1')>Active</option>
                        <option value="0" @selected(request('is_active') === '0')>Inactive</option>
                    </select>
                </div>
                <div class="col-lg-2 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Filter</button>
                    <a class="btn btn-outline-secondary" href="{{ route('admin.accounts.index') }}">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center"><div><div class="section-kicker">User directory</div><h2 class="section-title">System accounts</h2></div><span class="small text-muted">{{ number_format($accounts->total()) }} account(s)</span></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Full name</th><th>Email</th><th>Role</th><th>Assigned campaign</th><th>Status</th><th>Date created</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                @forelse($accounts as $account)
                    <tr>
                        <td class="fw-medium">{{ $account->name }}@if($account->employee_id)<span class="d-block small text-muted">{{ $account->employee_id }}</span>@endif</td>
                        <td>{{ $account->email }}</td>
                        <td><x-role-badges :user="$account" /></td>
                        <td>{{ $account->campaign?->name ?? 'Not assigned' }}</td>
                        <td><span class="status-badge {{ $account->is_active ? 'status-completed' : 'status-neutral' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td class="text-nowrap">{{ $account->created_at->format('M d, Y') }}</td>
                        <td>
                            <div class="d-flex justify-content-end gap-1">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.accounts.show', $account) }}">View</a>
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.accounts.edit', $account) }}">Edit</a>
                                <form method="POST" action="{{ route('admin.accounts.status', $account) }}">
                                    @csrf @method('PATCH')
                                    <button class="btn btn-sm {{ $account->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit" onclick="return confirm('{{ $account->is_active ? 'Deactivate' : 'Activate' }} this account?')">{{ $account->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state icon="users" title="No accounts found" description="No accounts match the selected filters." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($accounts->hasPages())<div class="card-footer">{{ $accounts->links() }}</div>@endif
    </div>
@endsection
