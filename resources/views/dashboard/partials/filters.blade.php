@php
    $idPrefix = $idPrefix ?? 'dashboard';
    $showBudgetSearch = $showBudgetSearch ?? true;
    $hasRequestFilters = request()->hasAny(['priority', 'date_from', 'date_to']);
    $hasActiveFilters = $hasRequestFilters || ($showBudgetSearch && filled(request('search')));
@endphp

<form class="dashboard-workflow-filters card mb-3" method="GET" action="{{ route('dashboard') }}" aria-labelledby="{{ $idPrefix }}-filters-heading">
    @foreach(request()->only($showBudgetSearch ? ['transaction_type', 'workflow', 'budget_view'] : ['search', 'transaction_type', 'workflow', 'budget_view']) as $name => $value)
        @if(filled($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
    @endforeach
    <div class="dashboard-filter-heading">
        <span class="dashboard-filter-heading-icon" aria-hidden="true"><x-ui.icon name="search" size="16" /></span>
        <div>
            <h2 id="{{ $idPrefix }}-filters-heading">Filter requests</h2>
            <p>{{ $hasRequestFilters ? 'Filters applied to the figures and lists below' : ($hasActiveFilters ? 'Search applied to the lists below' : 'Narrow the figures and lists below') }}</p>
        </div>
    </div>
    <div class="dashboard-filter-fields">
        @if($showBudgetSearch)
            <div class="dashboard-filter-field dashboard-filter-search">
                <label for="{{ $idPrefix }}-search">Search</label>
                <input class="form-control form-control-sm" id="{{ $idPrefix }}-search" type="search" name="search" value="{{ request('search') }}" placeholder="Search PO, request, requestor…">
            </div>
        @endif
        @if(auth()->user()->canViewPriority())
            <div class="dashboard-filter-field">
                <label for="{{ $idPrefix }}-priority">Priority</label>
                <select class="form-select form-select-sm" id="{{ $idPrefix }}-priority" name="priority">
                    <option value="">All priority levels</option>
                    @foreach(\App\Models\PurchaseRequest::PRIORITY_LEVELS as $value => $label)
                        <option value="{{ $value }}" @selected(request('priority') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="dashboard-filter-field">
            <label for="{{ $idPrefix }}-date-from">Updated from</label>
            <input class="form-control form-control-sm" id="{{ $idPrefix }}-date-from" type="date" name="date_from" value="{{ request('date_from') }}">
        </div>
        <div class="dashboard-filter-field">
            <label for="{{ $idPrefix }}-date-to">Updated to</label>
            <input class="form-control form-control-sm" id="{{ $idPrefix }}-date-to" type="date" name="date_to" value="{{ request('date_to') }}">
        </div>
        <div class="dashboard-filter-actions">
            <button class="btn btn-sm btn-primary" type="submit">Apply filters</button>
            @if($hasActiveFilters)
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('dashboard', request()->only(['workflow', 'budget_view'])) }}">Clear</a>
            @endif
        </div>
    </div>
</form>
