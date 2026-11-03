@php
    $lowestQuotation = $quotationSummary['lowest'];
    $recommendedQuotation = $quotationSummary['recommended'];
    $combinedQuotation = $lowestQuotation && $recommendedQuotation
        && $lowestQuotation['supplier_id'] === $recommendedQuotation['supplier_id'];
    $summaryQuotation = $quotationSummary['entries']->firstWhere('is_approved', true)
        ?? $quotationSummary['entries']->firstWhere('is_selected', true)
        ?? $recommendedQuotation ?? $lowestQuotation ?? $quotationSummary['entries']->first();
    $receivedCount = $quotations->count();
@endphp

<div class="card border-0 shadow-none h-100 request-summary-card quotation-summary-card">
    <div class="card-header bg-white"><div class="section-kicker">Supplier response</div><div class="fw-semibold">Quotation Summary</div></div>
    <div class="card-body">
        <dl class="quotation-overview mb-0">
            <div><dt>Supplier</dt><dd>{{ $summaryQuotation['supplier_name'] ?? 'Not recorded' }}</dd></div>
            <div><dt>Total</dt><dd class="quotation-overview-amount">{!! $summaryQuotation ? '&#8369; '.number_format($summaryQuotation['amount'], 2) : 'Not recorded' !!}</dd></div>@if($summaryQuotation)<div><dt>Net / VAT</dt><dd>&#8369; {{ number_format($summaryQuotation['base_amount'], 2) }} / &#8369; {{ number_format($summaryQuotation['vat_amount'], 2) }}</dd></div>@endif
        </dl>
        <div class="quotation-summary-actions">
            <span class="quotation-received-count">{{ $receivedCount }} Quotation{{ $receivedCount === 1 ? '' : 's' }} Received</span>
            @if($receivedCount > 0)
                <a class="btn btn-sm btn-outline-primary" href="#supplier-quotations" onclick="document.getElementById('supplier-quotations-panel').open = true">View Quotations</a>
            @else
                <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">View Quotations</span>
            @endif
        </div>

        <details class="quotation-analysis">
            <summary>Pricing analysis &amp; creator accounts</summary>
            <div class="quotation-analysis-body">
                @if($combinedQuotation)
                    <article class="quotation-highlight">
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <span class="badge quotation-badge-lowest">Lowest Price</span>
                            <span class="badge quotation-badge-recommended">Recommended</span>
                            @if($recommendedQuotation['is_selected'])<span class="badge text-bg-primary">Selected</span>@endif
                            @if($recommendedQuotation['is_approved'])<span class="badge text-bg-success">Approved</span>@endif
                        </div>
                        <div class="fw-semibold">{{ $lowestQuotation['supplier_name'] }}</div>
                        <div class="quotation-highlight-amount">&#8369; {{ number_format($lowestQuotation['amount'], 2) }}</div>
                        <div class="small text-muted">Recorded quotation total</div>
                        <div class="quotation-reason mt-3"><div class="detail-label">Recorded reason</div><div>{{ $recommendedQuotation['recommendation_reason'] ?: 'No recommendation reason was recorded.' }}</div></div>
                    </article>
                @else
                    @if($lowestQuotation)
                        <article class="quotation-highlight mb-3">
                            <span class="badge quotation-badge-lowest mb-2">Lowest Price</span>
                            @if($lowestQuotation['is_selected'])<span class="badge text-bg-primary mb-2">Selected</span>@endif
                            @if($lowestQuotation['is_approved'])<span class="badge text-bg-success mb-2">Approved</span>@endif
                            <div class="fw-semibold">{{ $lowestQuotation['supplier_name'] }}</div>
                            <div class="quotation-highlight-amount">&#8369; {{ number_format($lowestQuotation['amount'], 2) }}</div>
                            <div class="small text-muted">Lowest total among like-for-like recorded scopes</div>
                        </article>
                    @elseif($quotationSummary['lowest_tie'])
                        <div class="quotation-empty-state mb-3">The lowest comparable quotation total is tied, so no single supplier is labeled lowest.</div>
                    @elseif($quotationSummary['entries']->count() === 1)
                        <div class="quotation-empty-state mb-3">One quotation is recorded. At least two like-for-like quotations are needed for a lowest-price comparison.</div>
                    @elseif($quotationSummary['entries']->isEmpty())
                        <div class="quotation-empty-state mb-3">No supplier quotations are recorded yet.</div>
                    @else
                        <div class="quotation-empty-state mb-3">No lowest-price result is shown because the recorded quotation scopes are incomplete or not comparable.</div>
                    @endif
                    @if($recommendedQuotation)
                        <article class="quotation-highlight quotation-highlight--recommended">
                            <span class="badge quotation-badge-recommended mb-2">Recommended</span>
                            @if($recommendedQuotation['is_selected'])<span class="badge text-bg-primary mb-2">Selected</span>@endif
                            @if($recommendedQuotation['is_approved'])<span class="badge text-bg-success mb-2">Approved</span>@endif
                            <div class="fw-semibold">{{ $recommendedQuotation['supplier_name'] }}</div>
                            <div class="quotation-highlight-amount">&#8369; {{ number_format($recommendedQuotation['amount'], 2) }}</div>
                            <div class="quotation-reason mt-3"><div class="detail-label">Recorded reason</div><div>{{ $recommendedQuotation['recommendation_reason'] ?: 'No recommendation reason was recorded.' }}</div></div>
                        </article>
                    @else
                        <div class="quotation-empty-state">No recommended quotation yet.</div>
                    @endif
                @endif
            </div>
            <div class="quotation-context">
                @if($quotationSummary['entries']->isNotEmpty())
                    <div class="quotation-creator-context">
                        <span>Quote creator account{{ $quotationSummary['entries']->flatMap(fn ($entry) => $entry['creator_accounts'])->unique('email')->count() === 1 ? '' : 's' }}</span>
                        <div class="quotation-creator-list">
                            @foreach($quotationSummary['entries'] as $entry)
                                <div><strong>{{ $entry['supplier_name'] }}</strong><span>
                                    @forelse($entry['creator_accounts'] as $creator)
                                        {{ $creator['name'] }} &lt;{{ $creator['email'] }}&gt;{{ $creator['role'] ? ' · '.$creator['role'] : '' }}{{ ! $loop->last ? '; ' : '' }}
                                    @empty
                                        Unknown account
                                    @endforelse
                                </span></div>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if($purchaseRequest->total_amount !== null)<div><span>Request estimate</span><strong>&#8369; {{ number_format((float) $purchaseRequest->total_amount, 2) }}</strong></div>@endif
                @if($purchaseRequest->purchaseOrder)<div><span>Issued PO amount</span><strong>Shown in Purchase Order below</strong></div>@endif
                <p>Quotation totals show Net of VAT + VAT. The selected VAT treatment is stored with each quotation.</p>
            </div>
        </details>
    </div>
</div>
