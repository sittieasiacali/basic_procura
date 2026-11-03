@extends('layouts.app')

@section('title', 'Campaigns')

@section('content')
    <x-ui.page-header eyebrow="Administration" title="Campaigns" description="Maintain reusable campaign assignments for ASI PROCURA accounts.">
        <x-slot:actions><a class="btn btn-primary d-inline-flex align-items-center gap-2" href="{{ route('admin.campaigns.create') }}"><x-ui.icon name="plus" size="16" />Create Campaign</a></x-slot:actions>
    </x-ui.page-header>

    @error('is_active')<div class="alert alert-warning">{{ $message }}</div>@enderror

    <div class="card filter-panel mb-3">
        <div class="card-header"><div class="section-kicker">Directory tools</div><h2 class="section-title">Filter campaigns</h2></div>
        <div class="card-body">
            <form class="row g-3 align-items-end" method="GET">
                <div class="col-md-7">
                    <label class="form-label" for="search">Search campaigns</label>
                    <input class="form-control" id="search" name="search" value="{{ request('search') }}" placeholder="Campaign name or description">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="is_active_filter">Status</label>
                    <select class="form-select" id="is_active_filter" name="is_active">
                        <option value="">All statuses</option>
                        <option value="1" @selected(request('is_active') === '1')>Active</option>
                        <option value="0" @selected(request('is_active') === '0')>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Filter</button>
                    <a class="btn btn-outline-secondary" href="{{ route('admin.campaigns.index') }}">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center"><div><div class="section-kicker">Assignment directory</div><h2 class="section-title">Campaign records</h2></div><span class="small text-muted">{{ number_format($campaigns->total()) }} campaign(s)</span></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Campaign name</th><th>Account number</th><th>Description or remarks</th><th>Assigned users</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                @forelse($campaigns as $campaign)
                    <tr>
                        <td class="fw-medium">{{ $campaign->name }}</td>
                        <td>{{ $campaign->account_number ?: 'Not configured' }}</td>
                        <td>{{ $campaign->description ?: 'No description provided.' }}</td>
                        <td><a href="{{ route('admin.campaigns.show', $campaign) }}">{{ $campaign->users_count }} total / {{ $campaign->active_users_count }} active</a></td>
                        <td><span class="status-badge {{ $campaign->is_active ? 'status-completed' : 'status-neutral' }}">{{ $campaign->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td>
                            <div class="d-flex justify-content-end gap-1">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.campaigns.show', $campaign) }}">View</a>
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.campaigns.edit', $campaign) }}">Edit</a>
                                <form method="POST" action="{{ route('admin.campaigns.status', $campaign) }}">
                                    @csrf @method('PATCH')
                                    <button class="btn btn-sm {{ $campaign->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit" @disabled($campaign->is_active && $campaign->active_users_count > 0) onclick="return confirm('{{ $campaign->is_active ? 'Deactivate' : 'Activate' }} this campaign?')" title="{{ $campaign->is_active && $campaign->active_users_count > 0 ? 'Reassign or deactivate active users first.' : '' }}">{{ $campaign->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="campaign" title="No campaigns found" description="No campaigns match the selected filters." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($campaigns->hasPages())<div class="card-footer">{{ $campaigns->links() }}</div>@endif
    </div>
@endsection
