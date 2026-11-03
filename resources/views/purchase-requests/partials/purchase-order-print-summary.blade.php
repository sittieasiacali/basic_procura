@php
    $printApprovedQuotations = \App\Models\SupplierQuotation::deduplicateForReview(
        $allQuotations->filter(fn ($quotation) => $quotation->is_approved && (int) $quotation->supplier_id === (int) $purchaseRequest->selected_supplier_id)
    );
    $printQuotation = $printApprovedQuotations->first()
        ?? $quotations->firstWhere('is_recommended', true)
        ?? $quotations->first();
    $printQuotationTotal = $printApprovedQuotations->isNotEmpty()
        ? $printApprovedQuotations->sum('price')
        : ($printQuotation ? $quotations->where('supplier_id', $printQuotation->supplier_id)->sum('price') : null);
@endphp

<article class="po-print-summary" aria-label="Purchase request print summary">
    <header class="po-print-header">
        <img src="{{ asset('assets/images/asi-procura-logo.png') }}" alt="ASI Procura">
        <div>
            <span class="po-print-eyebrow">Purchase request</span>
            <h1>Request summary</h1>
            <p>{{ $purchaseRequest->request_number }}</p>
        </div>
    </header>

    <div class="po-print-title">
        <div>
            <span class="po-print-eyebrow">Request title</span>
            <h2>{{ $purchaseRequest->title ?: $purchaseRequest->request_number }}</h2>
        </div>
        <span class="po-print-stage">{{ $workflowStage }}</span>
    </div>

    <section class="po-print-section">
        <h3>Request details</h3>
        <dl class="po-print-facts">
            <div><dt>Requested by</dt><dd>{{ $purchaseRequest->requestor->name }}</dd></div>
            <div><dt>Department</dt><dd>{{ $purchaseRequest->requestor->department ?: 'Not recorded' }}</dd></div>
            <div><dt>Request date</dt><dd>{{ $purchaseRequest->created_at->format('M d, Y') }}</dd></div>
            <div><dt>Required date</dt><dd>{{ $purchaseRequest->required_date?->format('M d, Y') ?? 'Not recorded' }}</dd></div>
            <div><dt>Delivery location</dt><dd>{{ $purchaseRequest->delivery_location }}</dd></div>
            <div><dt>Request type</dt><dd>{{ $requestTypeLabel }}</dd></div>
        </dl>
        @if($purchaseRequest->purpose)
            <p class="po-print-purpose"><strong>Purpose</strong> {{ $purchaseRequest->purpose }}</p>
        @endif
    </section>

    <section class="po-print-section">
        <h3>Requested items</h3>
        @php
            $hasPrintEstimates = $purchaseRequest->items->contains(fn ($item) => $item->quantity !== null || $item->unit !== null || $item->estimated_total !== null);
        @endphp
        <table class="po-print-table">
            <thead><tr><th>Item and specifications</th>@if($hasPrintEstimates)<th class="po-print-number">Qty</th><th>Unit</th><th class="po-print-number">Estimated total</th>@endif</tr></thead>
            <tbody>
                @foreach($purchaseRequest->items as $item)
                    <tr>
                        <td><strong>{{ $item->description }}</strong>@if($item->brand_specifications)<small>{{ $item->brand_specifications }}</small>@endif</td>
                        @if($hasPrintEstimates)
                            <td class="po-print-number">{{ $item->quantity === null ? '—' : number_format((float) $item->quantity, 2) }}</td>
                            <td>{{ $item->unit ?: '—' }}</td>
                            <td class="po-print-number">{{ $item->estimated_total === null ? '—' : '₱ '.number_format((float) $item->estimated_total, 2) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            @if($hasPrintEstimates && $purchaseRequest->total_amount !== null)<tfoot><tr><th colspan="3">Request estimate</th><th class="po-print-number">&#8369; {{ number_format((float) $purchaseRequest->total_amount, 2) }}</th></tr></tfoot>@endif
        </table>
    </section>

    <section class="po-print-section po-print-approval">
        <h3>{{ $printApprovedQuotations->isNotEmpty() ? 'Approved quotation' : 'Quotation snapshot' }}</h3>
        <dl class="po-print-facts">
            <div><dt>Supplier</dt><dd>{{ $printQuotation?->supplier?->name ?? 'Not recorded' }}</dd></div>
            <div><dt>{{ $printApprovedQuotations->isNotEmpty() ? 'Approved amount' : 'Recorded amount' }}</dt><dd class="po-print-total">{{ $printQuotationTotal !== null ? '₱ '.number_format((float) $printQuotationTotal, 2) : 'Not available' }}</dd></div>
            <div><dt>Lead time</dt><dd>{{ $printQuotation?->delivery_lead_time ?: 'Not provided' }}</dd></div>
            <div><dt>Warranty</dt><dd>{{ $printQuotation?->warranty ?: 'Not provided' }}</dd></div>
        </dl>
    </section>

    <footer class="po-print-footer">
        <span>ASI PROCURA · Purchase request summary</span>
        <span>{{ $purchaseRequest->request_number }} · Printed {{ now()->format('M d, Y') }}</span>
    </footer>
</article>
