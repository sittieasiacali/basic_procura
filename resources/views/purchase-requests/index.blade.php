@extends('layouts.home')

@section('title', 'Purchase Requests')

@section('content')
    <x-ui.page-header
        eyebrow="Request management"
        title="Purchase Requests"
        description="Search, review, and track every purchase request available to your role."
    >
        <x-slot:actions>
            @can('create', \App\Models\PurchaseRequest::class)
                <a class="btn btn-primary d-inline-flex align-items-center gap-2" href="{{ route('purchase-requests.create') }}">
                    <x-ui.icon name="plus" size="17" />Create Purchase Request
                </a>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $summaryCards = [
            'all' => ['label' => 'All', 'value' => null, 'class' => 'primary'],
            'pending' => ['label' => 'Pending', 'value' => 'pending', 'class' => 'warning'],
            'approved' => ['label' => 'Approved', 'value' => 'approved', 'class' => 'success'],
            'disapproved' => ['label' => 'Disapproved', 'value' => 'disapproved', 'class' => 'danger'],
        ];
    @endphp
    <div class="row g-3 mb-4 request-summary-grid">
        @foreach($summaryCards as $key => $card)
            @php
                $query = request()->only(['search', 'priority', 'status', 'campaign_id', 'date_from', 'date_to']);
                if ($card['value']) $query['summary'] = $card['value'];
                $active = request('summary') === $card['value'] || ($key === 'all' && ! request()->filled('summary'));
            @endphp
            <div class="col-6 col-xl-3">
                <a class="card kpi-card h-100 text-decoration-none {{ $active ? 'border-start border-4 border-'.$card['class'] : '' }}" href="{{ route('purchase-requests.index', $query) }}" @if($active) aria-current="page" @endif>
                    <div class="card-body py-3">
                        <div class="small text-muted mb-1">{{ $card['label'] }}</div>
                        <div class="h3 mb-0 text-dark" data-summary-count="{{ $key }}">{{ number_format($summaryCounts[$key]) }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="card filter-panel mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end request-list-filters" method="GET">
                @if(request()->filled('summary'))<input type="hidden" name="summary" value="{{ request('summary') }}">@endif
                <div class="col-md-6 col-xl-3 request-filter-search">
                    <label class="form-label small" for="search">Search</label>
                    <input class="form-control" id="search" type="search" name="search" value="{{ request('search') }}" placeholder="Search PO, request, requestor…">
                </div>
                @if(auth()->user()->canViewPriority())
                    <div class="col-sm-6 col-md-3 col-xl-2 request-filter-advanced">
                        <label class="form-label small" for="priority">Priority</label>
                        <select class="form-select" id="priority" name="priority"><option value="">All priority levels</option>@foreach(\App\Models\PurchaseRequest::PRIORITY_LEVELS as $value => $label)<option value="{{ $value }}" @selected(request('priority') === $value)>{{ $label }}</option>@endforeach</select>
                    </div>
                @endif
                <div class="col-sm-6 col-md-3 col-xl-2 request-filter-advanced">
                    <label class="form-label small" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All statuses</option>
                        @foreach($statuses as $status)
                            @if($status !== \App\Enums\PurchaseRequestStatus::ForOmSmtsApproval)
                                <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ str($status->value)->replace('_', ' ')->title() }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-3 col-xl-2 request-filter-advanced">
                    <label class="form-label small" for="campaign_id">Campaign</label>
                    <select class="form-select" id="campaign_id" name="campaign_id">
                        <option value="">All campaigns</option>
                        @foreach($campaigns as $campaign)
                            <option value="{{ $campaign->id }}" @selected((string) request('campaign_id') === (string) $campaign->id)>{{ $campaign->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-xl-2 request-filter-advanced request-filter-date">
                    <label class="form-label small" for="date_from">Updated from</label>
                    <input class="form-control" id="date_from" type="date" name="date_from" value="{{ request('date_from') }}">
                </div>
                <div class="col-sm-6 col-xl-2 request-filter-advanced request-filter-date">
                    <label class="form-label small" for="date_to">Updated to</label>
                    <input class="form-control" id="date_to" type="date" name="date_to" value="{{ request('date_to') }}">
                </div>
                <div class="col-xl-1 d-flex gap-2 request-filter-actions">
                    <button class="btn btn-primary flex-grow-1" type="submit">Apply filters</button>
                    <a class="btn btn-outline-secondary" href="{{ route('purchase-requests.index') }}" title="Clear filters">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card request-list-panel">
        <div class="flow-request-grid" data-mobile-inbox>
                @forelse($purchaseRequests as $purchaseRequest)
                    <article class="flow-request-card">
                        <div class="flow-request-card-topline">
                            <a class="flow-request-number" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview>{{ $purchaseRequest->request_number }}</a>
                            <x-request-list-status-badge :status="$purchaseRequest->status" />
                        </div>
                        <h2 class="flow-request-title">{{ $purchaseRequest->title }}</h2>
                        @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::Returned)
                            <div class="alert alert-warning small mt-2 mb-1"><strong>Returned for correction:</strong> {{ $purchaseRequest->latestReturnApproval?->remarks }}</div>
                        @endif

                        <div class="flow-request-meta">
                            <span>{{ $purchaseRequest->requestor->name }}</span>
                            <span aria-hidden="true">&middot;</span>
                            <span>{{ $purchaseRequest->requestor->campaign?->name ?? $purchaseRequest->department_campaign }}</span>
                            <span aria-hidden="true">&middot;</span>
                            <span title="{{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}">Updated {{ $purchaseRequest->updated_at->diffForHumans() }}</span>
                        </div>
                        <div class="flow-request-details">
                            <span><strong>Requested:</strong> {{ $purchaseRequest->created_at->format('M j, Y') }}</span>
                            <span><strong>Current stage:</strong> {{ $purchaseRequest->responsibleRole?->name ?? 'Workflow complete' }}</span>
                            <span class="flow-request-review"><strong>OM review:</strong> <x-approval-status-badge :status="$purchaseRequest->approvalStatus(['OM_REVIEW', 'OM_APPROVAL'])" /></span>
                            @if($purchaseRequest->items->isNotEmpty())
                                <span class="flow-request-items" title="{{ $purchaseRequest->items->pluck('description')->join(', ') }}"><strong>Items:</strong>
                                    @foreach($purchaseRequest->items->take(2) as $item)
                                        {{ $item->description }}
                                        @if($item->quantity !== null)
                                            &times; {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }} {{ $item->unit }}
                                        @endif
                                        @if(! $loop->last), @endif
                                    @endforeach
                                    @if($purchaseRequest->items->count() > 2)
                                        +{{ $purchaseRequest->items->count() - 2 }} more
                                    @endif
                                </span>
                            @endif
                            <span><strong>Amount:</strong> {{ $purchaseRequest->total_amount === null ? 'Not estimated' : '₱ '.number_format((float) $purchaseRequest->total_amount, 2) }}</span>
                        </div>
                        
                        <div class="flow-request-card-footer">
                            <x-admin-delete-transaction :purchase-request="$purchaseRequest" />
                            @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::Returned)
                                @can('update', $purchaseRequest)<a class="btn btn-sm btn-primary" href="{{ route('purchase-requests.edit', $purchaseRequest) }}">Edit and resubmit</a>@endcan
                            @endif

                            <a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview>View request <x-ui.icon name="chevron-right" size="15" /></a>
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state
                                class="flow-request-empty"
                                icon="request"
                                :title="$summaryCounts['all'] > 0 ? 'No requests match your filters' : 'No purchase requests found'"
                                :description="$summaryCounts['all'] > 0 ? 'Adjust or clear the current filters to see more results.' : 'Requests available to your role will appear here.'"
                            >
                            @if(request()->hasAny(['search', 'priority', 'status', 'summary', 'campaign_id', 'date_from', 'date_to']))
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase-requests.index') }}">Clear filters</a>
                            @endif
                    </x-ui.empty-state>
                @endforelse
        </div>
        @if($purchaseRequests->hasPages())
            <div class="card-footer bg-white">{{ $purchaseRequests->links() }}</div>
        @endif
    </div>

    @if($processedRequests->isNotEmpty())
        <section class="card mt-4" aria-labelledby="processed-requests-heading">
            <div class="card-header"><div>
                <h2 id="processed-requests-heading" class="h5 mb-1">My processed requests</h2>
                <p class="mb-0 text-muted small">Requests you completed and handed to the next stage. Select one to preview or print it.</p>
            </div></div>
            <div class="table-responsive"><table data-mobile-cards data-mobile-inbox class="table table-hover align-middle mb-0 processed-requests-table">
                <thead><tr><th>Request</th><th>Completed step</th><th>Current status</th><th class="text-end">Processed</th></tr></thead>
                <tbody>
                @foreach($processedRequests as $processed)
                    @php($processedPreviewUrl = route('purchase-requests.preview', $processed['request']))
                    <tr class="po-review-history-row" data-request-preview-url="{{ $processedPreviewUrl }}" tabindex="0" role="button" aria-label="Preview request {{ $processed['request']->request_number }}">
                        <td><button class="btn btn-link p-0 fw-semibold text-decoration-none" type="button" data-request-preview-url="{{ $processedPreviewUrl }}">{{ $processed['request']->request_number }}</button><div class="small text-muted text-truncate request-title">{{ $processed['request']->title }}</div></td>
                        <td>{{ $processed['step'] }}</td><td><x-status-badge :status="$processed['request']->status" /></td>
                        <td class="text-end small text-muted text-nowrap">{{ $processed['processed_at']?->format('M j, Y g:i A') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
        @include('purchase-requests.partials.request-preview-modal')
    @endif
@endsection
