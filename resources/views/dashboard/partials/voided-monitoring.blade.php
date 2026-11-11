{{-- Shared register, also used for the IT / Premises team's dashboard monitoring. --}}
@if($voidedMonitoring)
    @php
        $isSharedVoidedMonitoring = request()->routeIs('voided-monitoring.index');
    @endphp
    <section class="card dashboard-panel dashboard-recent-panel voided-monitoring" aria-labelledby="voided-monitoring-heading">
        <div class="card-header voided-monitoring-header">
            <div>
                <h2 id="voided-monitoring-heading">Voided requests</h2>
                <p>{{ $voidedMonitoringDescription ?? "Requests the requestor withdrew while they were at your team's stage. Waiting ones need acknowledgment; voided ones are kept for history." }}</p>
            </div>
            @if($isSharedVoidedMonitoring)<span class="voided-monitoring-count">{{ number_format($voidedMonitoring->total()) }} {{ $voidedMonitoring->total() === 1 ? 'request' : 'requests' }}</span>@endif
        </div>
        @include('partials.table-toolbar', ['toolbar' => [
            'prefix' => 'voided',
            'label' => 'Filter voided requests',
            'anchor' => 'voided-monitoring-heading',
            'searchPlaceholder' => 'Search request, title, reason…',
            'statusLabel' => 'Status',
            'statusPlaceholder' => 'All',
            'statusOptions' => [
                \App\Enums\PurchaseRequestStatus::WithdrawalPending->value => 'Waiting for acknowledgment',
                \App\Enums\PurchaseRequestStatus::Voided->value => 'Voided',
            ],
            'dateLabel' => 'Withdrawn',
        ]])
        @if($isSharedVoidedMonitoring)
            @include('purchase-requests.partials.voided-monitoring-register')
        @else
        <div class="table-responsive">
            <table data-mobile-cards class="table table-hover align-middle mb-0 dashboard-table voided-requests-table">
                <thead><tr><th class="table-row-number">#</th><th>Request</th>@if($isSharedVoidedMonitoring)<th>Account owner</th>@endif<th>Status</th><th>Workflow stopped at</th><th>Reason</th><th>Withdrawn</th><th>Acknowledged by</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($voidedMonitoring as $purchaseRequest)
                    @php
                        $isVoidPending = $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::WithdrawalPending;
                        $withdrawalMetadata = $purchaseRequest->relationLoaded('auditLogs')
                            ? ($purchaseRequest->auditLogs->firstWhere('action', 'WITHDRAWAL_REQUESTED')?->metadata ?? []) : [];
                        $acknowledgmentMetadata = $purchaseRequest->relationLoaded('auditLogs')
                            ? ($purchaseRequest->auditLogs->firstWhere('action', 'WITHDRAWAL_ACKNOWLEDGED')?->metadata ?? []) : [];
                    @endphp
                    <tr>
                        <td class="table-row-number">{{ $voidedMonitoring->firstItem() + $loop->index }}</td>
                        <td><a class="fw-semibold text-decoration-none" href="{{ route('purchase-requests.show', $purchaseRequest) }}#withdrawal-status" data-workflow-request-preview>{{ $purchaseRequest->request_number }}</a><div class="small text-muted text-truncate request-title">{{ $purchaseRequest->title }}</div></td>
                        @if($isSharedVoidedMonitoring)
                            <td class="small">
                                <strong>{{ $withdrawalMetadata['request_owner_name'] ?? $purchaseRequest->requestor?->name ?? 'Not recorded' }}</strong>
                                <div class="text-muted text-break">{{ $withdrawalMetadata['request_owner_email'] ?? $purchaseRequest->requestor?->email }}</div>
                                <div class="text-muted">Requestor</div>
                            </td>
                        @endif
                        <td><x-status-badge :status="$purchaseRequest->status" /></td>
                        <td class="small">
                            <strong>{{ \App\Support\RequestWithdrawal::stageLabel($purchaseRequest->withdrawal_stage) }}</strong>
                            @if($isSharedVoidedMonitoring)
                                <div class="mt-1">Handling account: {{ $withdrawalMetadata['handling_account_name'] ?? $purchaseRequest->assignedUser?->name ?? ($isVoidPending || array_key_exists('handling_account_id', $withdrawalMetadata) ? 'Not assigned to an individual' : 'Not recorded') }}</div>
                                <div class="text-muted text-break">{{ $withdrawalMetadata['handling_account_email'] ?? $purchaseRequest->assignedUser?->email }}</div>
                                <div class="text-muted">{{ $withdrawalMetadata['handling_role'] ?? $purchaseRequest->assignedUser?->role?->name ?? $purchaseRequest->withdrawalRole?->name }}</div>
                            @endif
                        </td>
                        <td class="small voided-reason">{{ $purchaseRequest->withdrawal_reason ?? 'Not recorded' }}</td>
                        <td class="small text-nowrap">{{ $purchaseRequest->withdrawnBy?->name ?? $purchaseRequest->requestor?->name }}<div class="text-muted">{{ $purchaseRequest->withdrawn_at?->format('M j, Y g:i A') }}</div></td>
                        <td class="small text-nowrap">
                            @if($isVoidPending)
                                <span class="text-warning-emphasis"><x-ui.icon name="hourglass" size="13" /> Waiting · {{ $purchaseRequest->withdrawn_at?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                            @else
                                {{ $acknowledgmentMetadata['acknowledged_by_name'] ?? $purchaseRequest->withdrawalAcknowledgedBy?->name ?? 'Not recorded' }}
                                @if($isSharedVoidedMonitoring)
                                    <div class="text-muted text-break">{{ $acknowledgmentMetadata['acknowledged_by_email'] ?? $purchaseRequest->withdrawalAcknowledgedBy?->email }}</div>
                                    <div class="text-muted">{{ $acknowledgmentMetadata['acknowledged_by_role'] ?? $purchaseRequest->withdrawalAcknowledgedBy?->role?->name }}</div>
                                @endif
                                <div class="text-muted">{{ $purchaseRequest->withdrawal_acknowledged_at?->format('M j, Y g:i A') }}</div>
                                @if($purchaseRequest->withdrawal_acknowledgment_remarks)
                                    <div class="text-wrap text-break mt-1">{{ $purchaseRequest->withdrawal_acknowledgment_remarks }}</div>
                                @endif
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            @if($isVoidPending && auth()->user()->can('acknowledgeWithdrawal', $purchaseRequest))
                                @if(request()->routeIs('voided-monitoring.index'))
                                    <button class="btn btn-sm btn-warning" type="button" data-bs-toggle="modal" data-bs-target="#acknowledge-withdrawal-{{ $purchaseRequest->id }}">Acknowledge</button>
                                @else
                                    <a class="btn btn-sm btn-warning" href="{{ route('purchase-requests.show', $purchaseRequest) }}#withdrawal-status" data-workflow-action-modal data-workflow-action-title="Acknowledge withdrawal">Acknowledge <x-ui.icon name="chevron-right" size="15" /></a>
                                @endif
                            @else
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $purchaseRequest) }}#withdrawal-status" data-workflow-request-preview>View request <x-ui.icon name="chevron-right" size="15" /></a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $isSharedVoidedMonitoring ? 9 : 8 }}"><x-ui.empty-state icon="x-circle" title="No voided requests" :description="$voidedMonitoringEmptyDescription ?? 'Requests withdrawn while at your team\'s stage appear here. Adjust the search or filters if you expected results.'" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @endif
        @if($isSharedVoidedMonitoring && $voidedMonitoring->total() > 0)
            <div class="voided-monitoring-range">Showing {{ $voidedMonitoring->firstItem() }}–{{ $voidedMonitoring->lastItem() }} of {{ number_format($voidedMonitoring->total()) }} requests &middot; Pending acknowledgments appear first.</div>
        @endif
        @if($voidedMonitoring->hasPages())<div class="card-footer">{{ $voidedMonitoring->fragment('voided-monitoring-heading')->links() }}</div>@endif
    </section>
    @if(request()->routeIs('voided-monitoring.index'))
        @foreach($voidedMonitoring as $purchaseRequest)
            @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::WithdrawalPending && auth()->user()->can('acknowledgeWithdrawal', $purchaseRequest))
                @include('purchase-requests.partials.acknowledge-withdrawal-modal')
            @endif
        @endforeach
    @endif
@endif
