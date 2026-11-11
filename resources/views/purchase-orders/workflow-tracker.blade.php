@extends('layouts.app')

@section('title', 'Workflow Tracker')

@section('content')
<div class="dashboard-page workflow-tracker-page">
    <header class="dashboard-page-header">
        <div class="dashboard-heading-block">
            <nav class="dashboard-breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('dashboard') }}">Workspace</a><span aria-hidden="true">/</span><span aria-current="page">Workflow Tracker</span>
            </nav>
            <h1>Workflow Tracker</h1>
            <p class="dashboard-greeting">Follow each Purchase Order from checking through inventory. This view is for monitoring only.</p>
        </div>
        <div class="dashboard-header-actions page-actions d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary" href="{{ route('dashboard', ['workflow' => 'purchase-order']) }}">Back to dashboard</a>
            <button class="btn live-remarks-button" type="button" data-bs-toggle="modal" data-bs-target="#live-remarks-dialog" data-live-remarks-button title="Latest remarks across all Purchase Orders">
                <span class="live-remarks-button-icon" aria-hidden="true"><x-ui.icon name="message-square" size="16" /></span>
                <span>Live remarks</span>
                <span class="live-remarks-badge" data-live-remarks-badge hidden></span>
            </button>
            @include('purchase-orders.partials.live-remarks-dialog')
        </div>
    </header>
    @include('purchase-orders.partials.preview-modal')

    @if($returnedRequests->total() > 0)
    <section class="card mb-3" aria-labelledby="tracker-returns-heading">
        <div class="card-header"><div><h2 class="h5 mb-1" id="tracker-returns-heading">Requests returned before PO creation</h2><p class="small text-muted mb-0">Live status updates after requestor correction and resubmission. Once a PO is created, it appears in Purchase Order progress.</p></div></div>
        <div class="table-responsive"><table class="table align-middle mb-0" data-mobile-cards><thead><tr><th>Request</th><th>Correction reason</th><th>Current workflow status</th><th>Action</th></tr></thead><tbody>
        @foreach($returnedRequests as $entry)
            <tr><td><strong>{{ $entry->request_number }}</strong><div class="small">{{ $entry->title }}</div></td><td>{{ $entry->latestReturnApproval?->remarks }}<div class="small text-muted">Returned by {{ $entry->latestReturnApproval?->user?->name }}</div></td><td><x-status-badge :status="$entry->status" /><div class="small text-muted">{{ $entry->responsibleRole?->name }}</div></td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $entry) }}" data-workflow-request-preview>View request</a></td></tr>
        @endforeach
        </tbody></table></div>
        @if($returnedRequests->hasPages())<div class="card-footer">{{ $returnedRequests->links() }}</div>@endif
    </section>
    @endif

    <section class="card dashboard-panel" aria-labelledby="tracker-list-heading">
        <div class="card-header workflow-tracker-header">
            <div>
                <h2 id="tracker-list-heading">Purchase Order progress</h2>
                <p>Select a Purchase Order to view its full timeline from checking through inventory.</p>
            </div>
            <span class="workflow-tracker-count">{{ number_format($orders->total()) }} PO{{ $orders->total() === 1 ? '' : 's' }}</span>
        </div>
        <nav class="workflow-tracker-tabs" aria-label="Filter by current workflow stage">
            @foreach($stageLabels as $key => $label)
                <a class="workflow-tracker-tab {{ $stage === $key ? 'is-active' : '' }}"
                   href="{{ route('workflow-tracker.index', [...request()->only('search'), ...($key === 'all' ? [] : ['stage' => $key])]) }}"
                   @if($stage === $key) aria-current="page" @endif>
                    <span>{{ $label }}</span><span class="workflow-tracker-tab-count">{{ number_format($stageCounts[$key]) }}</span>
                </a>
            @endforeach
        </nav>
        <form class="workflow-tracker-search" method="GET" action="{{ route('workflow-tracker.index') }}" role="search" aria-label="Search Purchase Order workflows">
            @if($stage !== 'all')<input type="hidden" name="stage" value="{{ $stage }}">@endif
            <label class="visually-hidden" for="tracker-search">Search PO, request number, or title</label>
            <span class="workflow-tracker-search-icon" aria-hidden="true"><x-ui.icon name="search" size="15" /></span>
            <input class="form-control" id="tracker-search" name="search" type="search" value="{{ $search }}" placeholder="Search PO, request number, or title" maxlength="200">
            <button class="btn btn-primary" type="submit">Search</button>
            @if($search !== '')<a class="btn btn-outline-secondary" href="{{ route('workflow-tracker.index', $stage === 'all' ? [] : ['stage' => $stage]) }}">Clear</a>@endif
        </form>

        <div class="table-responsive">
            <table data-mobile-cards class="table table-hover align-middle mb-0 dashboard-table workflow-tracker-table">
                <thead>
                    <tr><th class="table-row-number">#</th><th>Purchase Order</th><th>Request</th><th>Supplier</th><th>Current stage &amp; progress</th><th>PO status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                    @forelse($orders as $order)
                        @php
                            $tracker = $trackers[$order->id];
                            $stepCount = count($tracker['steps']);
                            $doneCount = collect($tracker['steps'])->where('state', 'complete')->count();
                        @endphp
                        <tr>
                            <td class="table-row-number">{{ $orders->firstItem() + $loop->index }}</td>
                            <td class="text-nowrap">
                                <button class="tracker-code-link" type="button" data-po-preview-url="{{ route('purchase-orders.preview', $order) }}" title="Preview Purchase Order {{ $order->po_number }}">{{ $order->po_number }}<x-ui.icon name="eye" size="13" /></button>
                                <div class="small text-muted">{{ $order->created_at?->format('M j, Y') }}</div>
                            </td>
                            <td>
                                @if($order->purchaseRequest)
                                    <a class="tracker-code-link" href="{{ route('purchase-requests.show', $order->purchaseRequest) }}" data-workflow-request-preview data-workflow-request-title="Request {{ $order->purchaseRequest->request_number }}" title="Preview request {{ $order->purchaseRequest->request_number }}">{{ $order->purchaseRequest->request_number }}<x-ui.icon name="eye" size="13" /></a>
                                    <div class="small text-muted workflow-tracker-title">{{ $order->purchaseRequest->title ?? 'Untitled request' }}</div>
                                @else
                                    <span class="br-empty">Request unavailable</span>
                                @endif
                            </td>
                            <td>@if($order->supplier){{ $order->supplier->name }}@else<span class="br-empty">Not recorded</span>@endif</td>
                            <td>
                                <span class="workflow-tracker-current tracker-stage--{{ $tracker['current_key'] }}">{{ $tracker['current'] }}</span>
                                <div class="tracker-row-progress" title="{{ $doneCount }} of {{ $stepCount }} steps done"><span class="tracker-row-progress-bar"><span style="width: {{ round($doneCount / max($stepCount, 1) * 100) }}%"></span></span><span class="tracker-row-progress-text">{{ $doneCount }}/{{ $stepCount }}</span></div>
                            </td>
                            <td><span class="tracker-po-status tracker-po-status--{{ str($order->status)->lower()->slug() }}">{{ str($order->status)->replace('_', ' ')->title() }}</span></td>
                            <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" href="{{ route('workflow-tracker.show', ['purchaseOrder' => $order, ...request()->only(['search', 'stage', 'page'])]) }}">View timeline<x-ui.icon name="chevron-right" size="14" /></a><x-admin-delete-transaction :record="$order" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-ui.empty-state icon="search" title="No Purchase Orders found" description="No Purchase Orders match this stage and search. Try another tab or search term." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($orders->hasPages())<div class="card-footer">{{ $orders->links() }}</div>@endif
    </section>
</div>
@endsection
