{{-- Finance · Partially paid: requests with part of the amount paid, until the last payment clears the balance. --}}
@include('partials.table-toolbar', ['toolbar' => [
    'prefix' => 'partial',
    'label' => 'Filter partially paid requests',
    'anchor' => 'payment-queue-heading',
    'searchPlaceholder' => 'Search BR number, PO, title…',
    'dateLabel' => 'Updated',
]])
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0 dashboard-table partial-payments-table">
        <thead><tr><th class="table-row-number">#</th><th>BR Number</th><th>Project title</th><th class="text-end">Amount due</th><th>Paid</th><th class="text-end">Balance</th><th>Last payment</th><th class="text-end">Action</th></tr></thead>
        <tbody>
        @forelse($partialRequests as $purchaseRequest)
            @php
                $partialDue = (float) ($purchaseRequest->purchaseOrder?->total_amount ?? $purchaseRequest->total_amount);
                $partialPaid = (float) ($purchaseRequest->amount_paid ?? 0);
                $partialBalance = max(0, $partialDue - $partialPaid);
                $partialPercent = $partialDue > 0 ? min(100, $partialPaid / $partialDue * 100) : 0;
                $lastPayment = $purchaseRequest->payments->first();
            @endphp
            <tr>
                <td class="table-row-number">{{ $partialRequests->firstItem() + $loop->index }}</td>
                <td class="text-nowrap">
                    @if($purchaseRequest->budgetRequest)
                        <a class="budget-preparation-code" href="{{ route('budget-requests.document', $purchaseRequest->budgetRequest) }}" data-br-preview-url="{{ route('budget-requests.document', $purchaseRequest->budgetRequest) }}" aria-label="Preview Budget Request {{ $purchaseRequest->budgetRequest->displayNumber() }}"><x-ui.icon name="receipt" size="14" />{{ $purchaseRequest->budgetRequest->displayNumber() }}</a>
                    @else
                        <span class="text-muted">Not linked</span>
                    @endif
                </td>
                <td><span class="fw-semibold">{{ $purchaseRequest->title }}</span></td>
                <td class="text-end text-nowrap">&#8369; {{ number_format($partialDue, 2) }}</td>
                <td class="partial-payments-progress-cell">
                    <div class="d-flex justify-content-between small"><span class="fw-semibold">&#8369; {{ number_format($partialPaid, 2) }}</span><span class="text-muted">{{ round($partialPercent) }}%</span></div>
                    <div class="progress partial-payments-progress" role="progressbar" aria-label="Paid {{ round($partialPercent) }} percent" aria-valuenow="{{ round($partialPercent) }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width: {{ $partialPercent }}%"></div></div>
                    <div class="small text-muted mt-1">{{ $purchaseRequest->payments->count() }} {{ str('payment')->plural($purchaseRequest->payments->count()) }}</div>
                </td>
                <td class="text-end text-nowrap"><span class="partial-payments-balance">&#8369; {{ number_format($partialBalance, 2) }}</span></td>
                <td class="small text-nowrap">
                    @if($lastPayment)
                        <div>{{ ($lastPayment->paid_at ?? $lastPayment->created_at)?->format('M j, Y') }}</div>
                        <div class="text-muted">{{ str($lastPayment->payment_method)->replace('_', ' ')->title() }}@if($lastPayment->reference_number) · {{ $lastPayment->reference_number }}@endif</div>
                        @php($lastReceipt = $lastPayment->attachments->first())
                        @if($lastReceipt)<a href="{{ route('attachments.download', $lastReceipt) }}"><x-ui.icon name="file-text" size="12" /> Receipt</a>@endif
                    @endif
                </td>
                <td class="text-end text-nowrap">
                    @can('processPayment', $purchaseRequest)
                        <a class="btn btn-sm btn-success dashboard-row-action" href="{{ route('purchase-requests.show', ['purchaseRequest' => $purchaseRequest, 'from_stage' => 'payment']) }}#payment-actions" data-workflow-action-modal data-workflow-action-title="Mark as fully paid">Mark as fully paid <x-ui.icon name="chevron-right" size="15" /></a>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="8"><x-ui.empty-state icon="check-circle" title="No partially paid requests" description="Requests appear here after a partial payment is recorded, until the balance is fully paid." /></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@if($partialRequests->hasPages())<div class="card-footer">{{ $partialRequests->fragment('payment-queue-heading')->links() }}</div>@endif
