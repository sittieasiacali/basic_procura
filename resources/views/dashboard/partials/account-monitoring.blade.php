@php
    $accountFilterKeys = ['accounts_search', 'accounts_role_id', 'accounts_status', 'accounts_page'];
    $accountCarriedFilters = collect(request()->query())->except($accountFilterKeys)->filter(fn ($value) => is_string($value) && $value !== '');
    $accountRows = $accountMonitoring['accounts'];
    $accountFilters = $accountMonitoring['filters'];
    $hasAccountFilters = collect($accountFilters)->contains(fn ($value) => filled($value));
@endphp

<section class="card dashboard-panel account-monitoring" aria-labelledby="account-monitoring-heading">
    <div class="card-header account-monitoring-header">
        <div>
            <div class="section-kicker">Administration</div>
            <h2 id="account-monitoring-heading">User Accounts Monitoring</h2>
            <p>Review users by role and campaign. Active and inactive statuses reflect whether an account may sign in.</p>
        </div>
        <div class="account-monitoring-header-actions">
            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.accounts.index') }}"><x-ui.icon name="users" size="15" />Manage Accounts</a>
            @can('create', \App\Models\User::class)
                <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#add-new-user-modal"><x-ui.icon name="plus" size="15" />Add New User</button>
            @endcan
        </div>
    </div>

    <dl class="account-monitoring-summary" aria-label="All system accounts">
        <div><dt>Total accounts</dt><dd>{{ number_format($accountMonitoring['total']) }}</dd></div>
        <div class="is-active"><dt><span aria-hidden="true"></span>Active</dt><dd>{{ number_format($accountMonitoring['active']) }}</dd></div>
        <div class="is-inactive"><dt><span aria-hidden="true"></span>Inactive</dt><dd>{{ number_format($accountMonitoring['inactive']) }}</dd></div>
        <div><dt>Roles with accounts</dt><dd>{{ number_format($accountMonitoring['rolesInUse']) }}<span> / {{ $accountMonitoring['roles']->count() }}</span></dd></div>
    </dl>

    <details class="account-monitoring-roles">
        <summary>Accounts by role <span>View active and inactive totals for every role</span></summary>
        <div class="account-monitoring-role-grid">
            @foreach($accountMonitoring['roles'] as $monitoredRole)
                <a class="account-monitoring-role {{ (string) ($accountFilters['accounts_role_id'] ?? '') === (string) $monitoredRole->id ? 'is-selected' : '' }}" href="{{ request()->fullUrlWithQuery(['accounts_role_id' => $monitoredRole->id, 'accounts_page' => null]) }}#account-monitoring-heading" aria-label="View {{ $monitoredRole->name }} accounts" title="{{ $monitoredRole->description }}">
                    <span class="account-monitoring-role-name">{{ $monitoredRole->name }}</span><strong>{{ $monitoredRole->total_accounts }}</strong>
                    <span class="account-monitoring-role-counts"><span>{{ $monitoredRole->active_accounts }} active</span><span>{{ $monitoredRole->total_accounts - $monitoredRole->active_accounts }} inactive</span></span>
                </a>
            @endforeach
        </div>
    </details>

    <form class="account-monitoring-filters" method="GET" action="{{ route('dashboard') }}#account-monitoring-heading" role="search" aria-label="Filter user accounts">
        @foreach($accountCarriedFilters as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
        <div class="account-monitoring-search">
            <label for="accounts-search">Search accounts</label>
            <input class="form-control form-control-sm" type="search" id="accounts-search" name="accounts_search" value="{{ $accountFilters['accounts_search'] ?? '' }}" placeholder="Name, email, or employee ID" maxlength="200">
        </div>
        <div>
            <label for="accounts-role">Role</label>
            <select class="form-select form-select-sm" id="accounts-role" name="accounts_role_id">
                <option value="">All roles</option>
                @foreach($accountMonitoring['roles'] as $monitoredRole)
                    <option value="{{ $monitoredRole->id }}" @selected((string) ($accountFilters['accounts_role_id'] ?? '') === (string) $monitoredRole->id)>{{ $monitoredRole->name }} ({{ $monitoredRole->total_accounts }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="accounts-status">Account status</label>
            <select class="form-select form-select-sm" id="accounts-status" name="accounts_status">
                <option value="">All statuses</option>
                <option value="1" @selected(($accountFilters['accounts_status'] ?? '') === '1')>Active</option>
                <option value="0" @selected(($accountFilters['accounts_status'] ?? '') === '0')>Inactive</option>
            </select>
        </div>
        <div class="account-monitoring-filter-actions">
            <button class="btn btn-sm btn-primary" type="submit">Apply filters</button>
            @if($hasAccountFilters)<a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithoutQuery($accountFilterKeys) }}#account-monitoring-heading">Clear</a>@endif
        </div>
    </form>

    <div class="account-monitoring-results" aria-live="polite">{{ number_format($accountRows->total()) }} {{ $hasAccountFilters ? 'matching' : 'registered' }} {{ $accountRows->total() === 1 ? 'account' : 'accounts' }}<span>Account totals above cover all users.</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 account-monitoring-table">
            <thead><tr><th scope="col">Account</th><th scope="col">Role</th><th scope="col">Campaign</th><th scope="col">Status</th><th scope="col">Created</th><th scope="col" class="text-end">Action</th></tr></thead>
            <tbody>
            @forelse($accountRows as $monitoredAccount)
                <tr>
                    <td><a class="account-monitoring-name" href="{{ route('admin.accounts.show', $monitoredAccount) }}">{{ $monitoredAccount->name }}</a><span class="account-monitoring-email">{{ $monitoredAccount->email }}</span>@if($monitoredAccount->employee_id)<span class="account-monitoring-email">Employee ID: {{ $monitoredAccount->employee_id }}</span>@endif</td>
                    <td><span class="account-monitoring-role-badge">{{ \App\Support\AccountRoleCatalog::label($monitoredAccount->role) }}</span></td>
                    <td>{{ $monitoredAccount->campaign?->name ?? 'Not assigned' }}</td>
                    <td><span class="account-monitoring-status {{ $monitoredAccount->is_active ? 'is-active' : 'is-inactive' }}"><span aria-hidden="true"></span>{{ $monitoredAccount->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td class="text-nowrap"><time datetime="{{ $monitoredAccount->created_at?->toIso8601String() }}">{{ $monitoredAccount->created_at?->format('M j, Y') ?? 'Not recorded' }}</time></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.accounts.show', $monitoredAccount) }}" aria-label="View account for {{ $monitoredAccount->name }}">View account</a></td>
                </tr>
            @empty
                <tr><td colspan="6"><x-ui.empty-state icon="users" title="No accounts found" description="No accounts match these filters. Try another role or clear the search." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($accountRows->hasPages())<div class="card-footer">{{ $accountRows->fragment('account-monitoring-heading')->links() }}</div>@endif
</section>
