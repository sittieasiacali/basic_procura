@extends('layouts.app')

@section('title', 'Budget Request Preparation')

@section('content')
    @include('budget-requests.partials.preview-modal')
    <x-ui.page-header
        eyebrow="Finance workflow"
        title="Budget Request Preparation"
        description="Create a separate Budget Request for each approved Purchase Order."
    />

    <section class="card mb-3" aria-labelledby="budget-process-heading">
        <div class="card-body">
            <div class="section-kicker">Individual process</div>
            <h2 class="section-title mb-3" id="budget-process-heading">How to prepare a Budget Request</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="process-step">
                        <div class="small text-uppercase text-muted fw-semibold mb-1">1. Choose a PO</div>
                        <div class="fw-semibold">Open one eligible Purchase Order</div>
                        <div class="small text-muted">Each approved PO has its own Budget Request.</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="process-step">
                        <div class="small text-uppercase text-muted fw-semibold mb-1">2. Select account</div>
                        <div class="fw-semibold">Choose the campaign source account</div>
                        <div class="small text-muted">This is the first field on the Budget Request form.</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="process-step">
                        <div class="small text-uppercase text-muted fw-semibold mb-1">3. Submit</div>
                        <div class="fw-semibold">Create this PO's Budget Request</div>
                        <div class="small text-muted">Review the PO, complete the details, and sign.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="card budget-request-queue" aria-labelledby="budget-queue-heading" data-budget-request-queue>
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="section-kicker">Eligible Purchase Orders</div>
                <h2 class="section-title" id="budget-queue-heading">POs available for a new Budget Request</h2>
                <div class="small text-muted mt-1">Open one PO to create its Budget Request.</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="small text-muted">{{ $queue->total() }} PO(s)</span>
            </div>
        </div>
        <form class="budget-queue-filters d-flex flex-wrap align-items-end gap-2 p-3 border-bottom" method="GET" action="{{ route('budget.index') }}" role="search">
            @if(filled($filters['transaction_type'] ?? null))<input type="hidden" name="transaction_type" value="{{ $filters['transaction_type'] }}">@endif
            <div class="budget-queue-filter-field budget-queue-search"><label class="form-label small mb-1" for="budget-search-filter">Search</label><input class="form-control form-control-sm" id="budget-search-filter" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search PO, request, requestor…"></div>
            <div class="budget-queue-filter-field"><label class="form-label small mb-1" for="budget-priority-filter">Priority</label><select class="form-select form-select-sm" id="budget-priority-filter" name="priority"><option value="">All priority levels</option>@foreach(\App\Models\PurchaseRequest::PRIORITY_LEVELS as $value => $label)<option value="{{ $value }}" @selected(($filters['priority'] ?? null) === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="budget-queue-filter-field"><label class="form-label small mb-1" for="budget-date-from">Updated from</label><input class="form-control form-control-sm" id="budget-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="budget-queue-filter-field"><label class="form-label small mb-1" for="budget-date-to">Updated to</label><input class="form-control form-control-sm" id="budget-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
            <div class="d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Apply filters</button>@if(collect($filters)->except('transaction_type')->filter()->isNotEmpty())<a class="btn btn-sm btn-outline-secondary" href="{{ route('budget.index', collect($filters)->only('transaction_type')->filter()->all()) }}">Clear</a>@endif</div>
            <small class="text-muted w-100">Dates match request updates or PO approval dates.</small>
        </form>

        <div class="table-responsive">
            <table data-mobile-cards class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>PO Code</th>
                        <th>Supplier</th>
                        <th>Supplier Bank Details</th>
                        <th>Requestor</th>
                        <th>Campaign</th>
                        <th>Priority Level</th>
                        <th class="text-end">PO Total</th>
                        <th>Request updated</th>
                        <th>PO approved</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($queue as $purchaseRequest)
                    @php
                        $purchaseOrder = $purchaseRequest->budgetPurchaseOrder();
                        $priorityLevel = \App\Models\PurchaseRequest::priorityLabel($purchaseRequest->priority_level);
                        $supplierBankDetails = $purchaseRequest->quotations
                            ->where('supplier_id', $purchaseOrder->supplier_id)
                            ->where('is_approved', true)
                            ->pluck('bank_details')
                            ->first(fn ($details) => filled($details));
                    @endphp
                    <tr>
                        <td class="text-nowrap">
                            @if($purchaseOrder->exists)
                                <a class="fw-semibold text-primary text-decoration-none" href="{{ route('purchase-orders.document', ['purchaseRequest' => $purchaseRequest]) }}">{{ $purchaseOrder->po_number }}</a>
                            @else
                                <a class="fw-semibold text-primary text-decoration-none" href="{{ route('purchase-requests.show', $purchaseRequest) }}">{{ $purchaseRequest->request_number }}</a>
                                <div class="small text-muted">No PO</div>
                            @endif
                        </td>
                        <td>{{ $purchaseOrder->supplier?->name ?? 'Not provided' }}</td>
                        <td style="min-width: 15rem">
                            @if(filled($supplierBankDetails))
                                <span class="text-break">{{ $supplierBankDetails }}</span>
                            @else
                                <span class="text-muted">Not provided</span>
                            @endif
                        </td>
                        <td>{{ $purchaseRequest->requestor->name }}</td>
                        <td>{{ $purchaseRequest->department_campaign }}</td>
                        <td>
                            {{-- Priority is chosen at the Final budget; the Budget Maker only sees it. --}}
                            @if($priorityLevel)
                                <span class="budget-priority-badge budget-priority-badge--{{ strtolower($purchaseRequest->priority_level) }}">{{ $priorityLevel }}</span>
                            @else
                                <span class="text-muted">Set at Final budget</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap fw-semibold">&#8369; {{ number_format((float) $purchaseOrder->total_amount, 2) }}</td>
                        <td class="text-nowrap">{{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}</td>
                        <td class="text-nowrap">{{ $purchaseOrder->exists ? ($purchaseOrder->approved_at?->format('M j, Y g:i A') ?? 'Not recorded') : 'PO skipped '.$purchaseRequest->purchase_order_skipped_at?->format('M j, Y g:i A') }}</td>
                        <td class="text-end text-nowrap">@can('prepareBudgetRequest', $purchaseRequest)<a class="btn btn-sm btn-outline-primary" href="{{ route('budget.create', ['purchase_request_id' => $purchaseRequest->id]) }}">Prepare Budget Request</a>@endcan</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">
                            <x-ui.empty-state icon="flow" title="No eligible Purchase Orders" description="Every issued PO is already included in a Budget Request, or no PO is currently ready for budget preparation." />
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($queue->hasPages())<div class="card-footer">{{ $queue->links() }}</div>@endif
    </section>

    {{-- Budget Makers see the register on their dashboard, under Budget actions. --}}
    @unless(auth()->user()->hasRole(\App\Enums\RoleSlug::BudgetMaker->value))
        @include('budget-requests.partials.register', [
            'class' => 'mt-3',
            'filterUrl' => route('budget.index'),
        ])
    @endunless
@endsection
