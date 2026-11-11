@extends('layouts.app')

@section('title', 'For Approval')

@section('content')
    <header class="page-header">
        <div>
            <div class="page-eyebrow">Executive workspace</div>
            <h1>For Approval</h1>
            <p>Review Purchasing submissions, including requests without supplier pricing.</p>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('president-approvals.index', ['tab' => 'pending']) }}">Refresh pending requests</a>
    </header>

    <div class="row g-3 mb-4" aria-label="Approval summary">
        @foreach([
            ['All Pending', $summary['all_pending'], 'all_pending'],
            ['Supplier Approval', $summary['supplier'], 'supplier'],
            ['Purchase Order Approval', $summary['purchase_order'], 'purchase_order'],
            ['Budget Approval', $summary['budget'], 'budget'],
        ] as [$label, $value, $key])
            <div class="col-6 col-md-4 col-xl">
                <div class="card system-kpi-card h-100">
                    <div class="card-body py-3">
                        <div class="small text-muted mb-1">{{ $label }}</div>
                        <div class="h3 mb-0 text-dark">{{ number_format($value) }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <nav class="nav nav-pills approval-tabs" aria-label="President approval history">
            @foreach(['all' => 'All', 'pending' => 'For Approval', 'approved' => 'Approved', 'acknowledged' => 'Acknowledged', 'rejected' => 'Rejected / Returned'] as $key => $label)
                <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('president-approvals.index', ['tab' => $key]) }}">{{ $label }}</a>
            @endforeach
        </nav>
        <span class="small text-muted">{{ number_format($requests->total()) }} {{ $requests->total() === 1 ? 'request' : 'requests' }}</span>
    </div>

    <section class="card" aria-labelledby="approval-queue-heading">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="section-kicker">{{ $tab === 'pending' ? 'Action required' : 'Decision history' }}</div>
                <h2 class="section-title mb-0" id="approval-queue-heading">{{ $tab === 'pending' ? 'Pending actions' : str($tab)->title().' requests' }}</h2>
            </div>
        </div>
        <div class="table-responsive">
            <table data-mobile-cards class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Request</th>
                        <th>Description</th>
                        <th>Approval stage</th>
                        <th>Purchasing recommendation</th>
                        <th class="text-end">Amount</th>
                        <th>Submitted</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($requests as $purchaseRequest)
                    @php
                        $reviewQuotations = \App\Models\SupplierQuotation::deduplicateForReview($purchaseRequest->quotations);
                        $recommendation = $reviewQuotations->firstWhere('is_recommended', true);
                        $recommendedQuotes = $recommendation ? $reviewQuotations->where('supplier_id', $recommendation->supplier_id) : collect();
                        $stage = $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForSupplierApproval ? ($reviewQuotations->isEmpty() ? 'Request acknowledgement' : 'Supplier Approval') : 'President Decision';
                        $reviewAnchor = $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForSupplierApproval ? '#supplier-approval-actions' : '';
                    @endphp
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $purchaseRequest->request_number }}</div>
                            <div class="small text-muted">{{ $purchaseRequest->requestor?->name ?? 'Unknown requestor' }}</div>
                        </td>
                        <td>{{ $purchaseRequest->title }}</td>
                        <td>
                            <span class="badge text-bg-primary">{{ $stage }}</span>
                            <div class="small text-muted mt-1">{{ $purchaseRequest->responsibleRole?->name ?? 'President' }}</div>
                        </td>
                        <td>
                            @if($recommendation)
                                <div class="fw-semibold">{{ $recommendation->supplier?->name ?? 'Unavailable' }}</div>
                                <div class="small text-muted">&#8369; {{ number_format((float) $recommendedQuotes->sum('price'), 2) }}{{ $recommendedQuotes->count() > 1 ? ' across '.$recommendedQuotes->count().' quoted lines' : '' }}</div>
                            @else
                                @if($reviewQuotations->isEmpty())
                                    <span class="badge text-bg-warning">No quotation / pricing provided</span>
                                @else
                                    <span class="text-muted">Not supplied</span>
                                @endif
                            @endif
                        </td>
                        <td class="text-end text-nowrap">{{ $purchaseRequest->total_amount === null ? 'Not estimated' : '₱ '.number_format((float) $purchaseRequest->total_amount, 2) }}</td>
                        <td class="text-nowrap">{{ ($purchaseRequest->submitted_at ?? $purchaseRequest->updated_at)->format('M j, Y') }}</td>
                        <td class="text-end"><a class="btn btn-sm btn-primary" href="{{ route('purchase-requests.show', ['purchaseRequest' => $purchaseRequest, 'from_stage' => 'supplier-approval']) }}{{ $reviewAnchor }}" data-workflow-action-modal data-workflow-action-title="Supplier review">Review</a><x-admin-delete-transaction :purchase-request="$purchaseRequest" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center py-5"><x-ui.empty-state icon="flow" title="No requests in this view" description="Requests requiring your action or completed President decisions will appear here." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($requests->hasPages())
            <div class="card-footer bg-white">{{ $requests->links() }}</div>
        @endif
    </section>
@endsection
