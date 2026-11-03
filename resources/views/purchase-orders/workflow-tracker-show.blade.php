@extends('layouts.app')

@section('title', $order->po_number.' · Workflow Tracker')

@section('content')
@php
    $steps = collect($tracker['steps']);
    $doneCount = $steps->where('state', 'complete')->count();
    $progress = (int) round($doneCount / max($steps->count(), 1) * 100);
    $statusKey = $tracker['current_key'] === 'completed' ? 'complete' : ($tracker['current_key'] === 'rejected' ? 'rejected' : 'current');
    // Newest first, grouped by day; the same note audited under two stage names shows once.
    $remarkGroups = app(\App\Support\WorkflowRemarks::class)->forRequest($order->purchase_request_id)
        ->unique(fn (array $entry) => $entry['actor'].'|'.$entry['text'])
        ->sortByDesc(fn (array $entry) => $entry['at']?->getTimestamp() ?? 0)
        ->map(fn (array $entry) => $entry + ['order' => $order, 'title' => $order->purchaseRequest?->title, 'new' => false])
        ->groupBy(fn (array $entry) => match (true) {
            ! $entry['at'] => 'Earlier',
            $entry['at']->isToday() => 'Today',
            $entry['at']->isYesterday() => 'Yesterday',
            default => $entry['at']->format('M j, Y'),
        });
    $remarkCount = $remarkGroups->flatten(1)->count();
    $trackerQuery = request()->only(['search', 'stage', 'page']);
@endphp
<div class="dashboard-page workflow-tracker-page">
    @include('purchase-orders.partials.preview-modal')
    <header class="dashboard-page-header">
        <div class="dashboard-heading-block">
            <nav class="dashboard-breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('dashboard') }}">Workspace</a><span aria-hidden="true">/</span>
                <a href="{{ route('workflow-tracker.index', $trackerQuery) }}">Workflow Tracker</a><span aria-hidden="true">/</span>
                <span aria-current="page">{{ $order->po_number }}</span>
            </nav>
            <h1>{{ $order->po_number }}</h1>
            <p class="dashboard-greeting">Read-only progress from PO checking through inventory.</p>
        </div>
        <div class="dashboard-header-actions page-actions d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary d-inline-flex align-items-center gap-2" href="{{ route('workflow-tracker.index', $trackerQuery) }}"><x-ui.icon name="chevron-left" size="15" />Back to tracker</a>
        </div>
    </header>

    <section class="card dashboard-panel tracker-summary tracker-summary--{{ $statusKey }}" aria-labelledby="tracker-detail-heading">
        <div class="tracker-summary-main">
            <span class="tracker-summary-icon" aria-hidden="true"><x-ui.icon name="receipt" size="22" /></span>
            <div class="tracker-summary-text">
                <div class="tracker-summary-kicker">{{ $order->purchaseRequest?->request_number ?? 'Request unavailable' }}</div>
                <h2 id="tracker-detail-heading">{{ $order->purchaseRequest?->title ?? 'Untitled request' }}</h2>
                <dl class="tracker-summary-facts">
                    <div><dt>Supplier</dt><dd>{{ $order->supplier?->name ?? 'Not recorded' }}</dd></div>
                    <div><dt>PO status</dt><dd>{{ str($order->status)->replace('_', ' ')->title() }}</dd></div>
                    <div><dt>Purchase Order</dt><dd><button class="tracker-code-link" type="button" data-po-preview-url="{{ route('purchase-orders.preview', $order) }}">{{ $order->po_number }}<x-ui.icon name="eye" size="13" /></button></dd></div>
                    @if($order->purchaseRequest)
                        <div><dt>Request</dt><dd><a class="tracker-code-link" href="{{ route('purchase-requests.show', $order->purchaseRequest) }}" data-workflow-request-preview data-workflow-request-title="Request {{ $order->purchaseRequest->request_number }}">{{ $order->purchaseRequest->request_number }}<x-ui.icon name="eye" size="13" /></a></dd></div>
                    @endif
                </dl>
            </div>
            <div class="tracker-summary-status">
                <span class="tracker-summary-status-label">Current status</span>
                <span class="workflow-tracker-current">{{ $tracker['current'] }}</span>
            </div>
        </div>
        <div class="tracker-progress">
            <div class="tracker-progress-top"><strong>{{ $doneCount }} of {{ $steps->count() }} steps done</strong><span>{{ $progress }}%</span></div>
            <div class="tracker-progress-bar" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100" aria-label="Workflow progress"><span style="width: {{ $progress }}%"></span></div>
            <ol class="tracker-progress-steps" aria-hidden="true">
                @foreach($steps as $step)
                    <li class="tracker-progress-step tracker-progress-step--{{ $step['state'] }}" title="{{ $step['label'] }}"><span></span>{{ $step['label'] }}</li>
                @endforeach
            </ol>
        </div>
    </section>

    <div class="tracker-detail-grid">
        <section class="card dashboard-panel workflow-tracker-detail" aria-labelledby="tracker-steps-heading">
            <div class="card-header tracker-card-header">
                <span class="tracker-card-icon" aria-hidden="true"><x-ui.icon name="flow" size="18" /></span>
                <div><h2 id="tracker-steps-heading">Progress</h2><p>Each step shows its latest saved record.</p></div>
            </div>
            @include('purchase-orders.partials.workflow-tracker-timeline')
        </section>

        <section class="card dashboard-panel tracker-remarks" aria-labelledby="tracker-remarks-heading">
            <div class="card-header tracker-card-header">
                <span class="tracker-card-icon" aria-hidden="true"><x-ui.icon name="message-square" size="18" /></span>
                <div><h2 id="tracker-remarks-heading">Remarks</h2><p>Newest first</p></div>
                <span class="tracker-remarks-count">{{ $remarkCount }}</span>
            </div>
            <div class="tracker-remarks-body">
                @include('purchase-orders.partials.remarks-feed', ['groups' => $remarkGroups, 'search' => '', 'showOrder' => false])
            </div>
        </section>
    </div>
</div>
@endsection
