{{-- Requestor history · Voided: withdrawals waiting for acknowledgment, then voided requests. --}}
@include('partials.table-toolbar', ['toolbar' => [
    'prefix' => 'voided',
    'label' => 'Filter voided requests',
    'anchor' => 'completed-requests-heading',
    'searchPlaceholder' => 'Search request, title…',
    'statusLabel' => 'Status',
    'statusPlaceholder' => 'All',
    'statusOptions' => [
        \App\Enums\PurchaseRequestStatus::WithdrawalPending->value => 'Waiting for acknowledgment',
        \App\Enums\PurchaseRequestStatus::Voided->value => 'Voided',
    ],
    'dateLabel' => 'Updated',
]])
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0 voided-requests-table">
        <thead><tr><th class="table-row-number">#</th><th>Request</th><th>Status</th><th>Stopped at</th><th>Reason</th><th>Withdrawn</th><th>Acknowledged</th><th class="text-end">Action</th></tr></thead>
        <tbody>
        @forelse($voidedRequests as $purchaseRequest)
            @php($isVoidPending = $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::WithdrawalPending)
            <tr>
                <td class="table-row-number">{{ $voidedRequests->firstItem() + $loop->index }}</td>
                <td><span class="fw-semibold">{{ $purchaseRequest->request_number }}</span><div class="small text-muted text-truncate request-title">{{ $purchaseRequest->title }}</div></td>
                <td><x-status-badge :status="$purchaseRequest->status" /></td>
                <td class="small">{{ \App\Support\RequestWithdrawal::stageLabel($purchaseRequest->withdrawal_stage) }}</td>
                <td class="small voided-reason">{{ $purchaseRequest->withdrawal_reason ?? 'Not recorded' }}</td>
                <td class="small text-nowrap">{{ $purchaseRequest->withdrawn_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</td>
                <td class="small text-nowrap">
                    @if($isVoidPending)
                        <span class="text-warning-emphasis"><x-ui.icon name="hourglass" size="13" /> Waiting · {{ $purchaseRequest->withdrawn_at?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                    @else
                        {{ $purchaseRequest->withdrawalAcknowledgedBy?->name ?? 'Not recorded' }}
                        <div class="text-muted">{{ $purchaseRequest->withdrawal_acknowledged_at?->format('M j, Y g:i A') }}</div>
                    @endif
                </td>
                <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $purchaseRequest) }}#withdrawal-status" data-workflow-request-preview>View request <x-ui.icon name="chevron-right" size="15" /></a></td>
            </tr>
        @empty
            <tr><td colspan="8"><x-ui.empty-state icon="x-circle" title="No voided requests" description="Requests you void appear here, first waiting for staff acknowledgment, then as voided." /></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@if($voidedRequests->hasPages())<div class="card-footer">{{ $voidedRequests->fragment('completed-requests-heading')->links() }}</div>@endif
