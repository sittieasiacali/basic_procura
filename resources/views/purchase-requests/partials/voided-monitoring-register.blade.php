<div class="voided-register-wrap">
    <table class="table mb-0 voided-register" aria-label="Withdrawn requests and acknowledgment history">
        <thead>
            <tr><th scope="col">Request &amp; reason</th><th scope="col">Account owners</th><th scope="col">Workflow stopped at</th><th scope="col">Acknowledgment</th><th scope="col">Actions</th></tr>
        </thead>
        <tbody>
        @forelse($voidedMonitoring as $purchaseRequest)
            @php
                $isVoidPending = $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::WithdrawalPending;
                $canAcknowledgeVoid = $isVoidPending && auth()->user()->can('acknowledgeWithdrawal', $purchaseRequest);
                $withdrawalMetadata = $purchaseRequest->auditLogs->firstWhere('action', 'WITHDRAWAL_REQUESTED')?->metadata ?? [];
                $acknowledgmentMetadata = $purchaseRequest->auditLogs->firstWhere('action', 'WITHDRAWAL_ACKNOWLEDGED')?->metadata ?? [];
                $requestOwnerName = $withdrawalMetadata['request_owner_name'] ?? $purchaseRequest->requestor?->name ?? 'Not recorded';
                $requestOwnerEmail = $withdrawalMetadata['request_owner_email'] ?? $purchaseRequest->requestor?->email;
                $handlingName = $withdrawalMetadata['handling_account_name'] ?? $purchaseRequest->assignedUser?->name ?? ($isVoidPending || array_key_exists('handling_account_id', $withdrawalMetadata) ? 'Not assigned to an individual' : 'Not recorded');
                $handlingEmail = $withdrawalMetadata['handling_account_email'] ?? $purchaseRequest->assignedUser?->email;
                $handlingRole = $withdrawalMetadata['handling_role'] ?? $purchaseRequest->assignedUser?->role?->name ?? $purchaseRequest->withdrawalRole?->name;
                $reason = $purchaseRequest->withdrawal_reason ?? 'Not recorded';
            @endphp
            <tr class="{{ $isVoidPending ? 'voided-register-pending' : '' }}">
                <td data-label="Request & reason">
                    <div class="voided-register-reference"><span class="voided-register-index">{{ $voidedMonitoring->firstItem() + $loop->index }}</span><a href="{{ route('purchase-requests.show', $purchaseRequest) }}#withdrawal-status" data-workflow-request-preview>{{ $purchaseRequest->request_number }}</a></div>
                    <div class="voided-register-title">{{ $purchaseRequest->title }}</div>
                    <div class="voided-register-reason"><span class="voided-register-label">Withdrawal reason</span><p>{{ \Illuminate\Support\Str::limit($reason, 150) }}</p>
                        @if(mb_strlen($reason) > 150)<details class="voided-register-details"><summary>Read full reason</summary><p>{{ $reason }}</p></details>@endif
                    </div>
                </td>
                <td data-label="Account owners">
                    <div class="voided-register-person"><span class="voided-register-label">Requestor</span><strong>{{ $requestOwnerName }}</strong>@if($requestOwnerEmail)<span class="voided-register-email">{{ $requestOwnerEmail }}</span>@endif</div>
                    <div class="voided-register-person"><span class="voided-register-label">Handling staff</span><strong>{{ $handlingName }}</strong>@if($handlingRole)<span class="voided-register-meta">{{ $handlingRole }}</span>@endif @if($handlingEmail)<span class="voided-register-email">{{ $handlingEmail }}</span>@endif</div>
                </td>
                <td data-label="Workflow stopped at">
                    <span class="voided-register-stage"><x-ui.icon name="flow" size="15" /><strong>{{ \App\Support\RequestWithdrawal::stageLabel($purchaseRequest->withdrawal_stage) }}</strong></span>
                    <span class="voided-register-status {{ $isVoidPending ? 'is-pending' : 'is-voided' }}"><span aria-hidden="true"></span>{{ $isVoidPending ? 'Awaiting acknowledgment' : 'Voided' }}</span>
                    <div class="voided-register-date"><span class="voided-register-label">Withdrawn</span><time datetime="{{ $purchaseRequest->withdrawn_at?->toIso8601String() }}">{{ $purchaseRequest->withdrawn_at?->format('M j, Y') ?? 'Not recorded' }}</time><span class="voided-register-meta">{{ $purchaseRequest->withdrawn_at?->format('g:i A') }}</span></div>
                </td>
                <td data-label="Acknowledgment">
                    @if($isVoidPending)
                        <span class="voided-register-wait"><x-ui.icon name="hourglass" size="16" />Waiting for acknowledgment</span>
                        @if($purchaseRequest->withdrawn_at)<span class="voided-register-meta">Waiting &middot; {{ $purchaseRequest->withdrawn_at->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>@endif
                        <p class="voided-register-hint">{{ $canAcknowledgeVoid ? 'You can acknowledge this withdrawal.' : 'The handling staff will acknowledge this withdrawal.' }}</p>
                    @else
                        <span class="voided-register-label">Acknowledged by</span>
                        <strong class="voided-register-actor">{{ $acknowledgmentMetadata['acknowledged_by_name'] ?? $purchaseRequest->withdrawalAcknowledgedBy?->name ?? 'Not recorded' }}</strong>
                        <span class="voided-register-meta">{{ $acknowledgmentMetadata['acknowledged_by_role'] ?? $purchaseRequest->withdrawalAcknowledgedBy?->role?->name }}</span>
                        <span class="voided-register-email">{{ $acknowledgmentMetadata['acknowledged_by_email'] ?? $purchaseRequest->withdrawalAcknowledgedBy?->email }}</span>
                        <time class="voided-register-ack-date" datetime="{{ $purchaseRequest->withdrawal_acknowledged_at?->toIso8601String() }}">{{ $purchaseRequest->withdrawal_acknowledged_at?->format('M j, Y · g:i A') }}</time>
                        @if($purchaseRequest->withdrawal_acknowledgment_remarks)<details class="voided-register-details"><summary>View remarks</summary><p>{{ $purchaseRequest->withdrawal_acknowledgment_remarks }}</p></details>@endif
                    @endif
                </td>
                <td data-label="Actions">
                    <div class="voided-register-actions">
                        @if($canAcknowledgeVoid)<button class="btn btn-sm btn-warning" type="button" data-bs-toggle="modal" data-bs-target="#acknowledge-withdrawal-{{ $purchaseRequest->id }}"><x-ui.icon name="check-circle" size="15" />Acknowledge</button>@endif
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $purchaseRequest) }}#withdrawal-status" data-workflow-request-preview>View request<x-ui.icon name="chevron-right" size="14" /></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5"><x-ui.empty-state icon="x-circle" title="No voided requests" :description="$voidedMonitoringEmptyDescription" /></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
