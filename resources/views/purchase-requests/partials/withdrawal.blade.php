{{-- Requestor withdrawal: the Withdraw Request form, then the paused / voided status with its record. --}}
@php
    $withdrawalStatus = $purchaseRequest->status;
    $isWithdrawalPending = $withdrawalStatus === \App\Enums\PurchaseRequestStatus::WithdrawalPending;
    $isVoided = $withdrawalStatus === \App\Enums\PurchaseRequestStatus::Voided;
@endphp

@can('withdraw', $purchaseRequest)
    <div class="modal fade" id="withdraw-request-modal" tabindex="-1" aria-labelledby="withdraw-request-heading" aria-hidden="true" @error('withdrawal_reason') data-open-on-load @enderror>
        <div class="modal-dialog">
            <form class="modal-content" method="POST" action="{{ route('purchase-requests.withdraw', $purchaseRequest) }}">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="withdraw-request-heading">Withdraw Request</h2>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small d-flex gap-2" role="note">
                        <x-ui.icon name="warning" size="16" />
                        @if(\App\Support\RequestWithdrawal::voidsImmediately($purchaseRequest))
                            <div>This voids the <strong>whole request and all its items</strong> right away. No staff are handling it yet, so no acknowledgment is needed. This cannot be undone.</div>
                        @else
                            <div>This withdraws the <strong>whole request and all its items</strong>. Processing pauses now, and the staff handling it ({{ \App\Support\RequestWithdrawal::stageLabel($purchaseRequest->status->value) }}) must acknowledge it before it is voided. This cannot be undone.</div>
                        @endif
                    </div>
                    <label class="form-label" for="withdrawal-reason">Reason for withdrawal</label>
                    <textarea class="form-control @error('withdrawal_reason') is-invalid @enderror" id="withdrawal-reason" name="withdrawal_reason" rows="3" minlength="5" maxlength="2000" required placeholder="e.g. No longer needed; the team received equipment from another department.">{{ old('withdrawal_reason') }}</textarea>
                    @error('withdrawal_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Keep request</button>
                    <button class="btn btn-danger" type="submit">Withdraw Request</button>
                </div>
            </form>
        </div>
    </div>
@endcan

@if($isWithdrawalPending || $isVoided)
    <section @class(['card withdrawal-status mb-3', 'withdrawal-status--pending' => $isWithdrawalPending, 'withdrawal-status--voided' => $isVoided]) id="withdrawal-status" aria-labelledby="withdrawal-status-heading" data-embedded-focus="withdrawal">
        <div class="card-body">
            <div class="withdrawal-status-heading">
                <span class="withdrawal-status-icon" aria-hidden="true"><x-ui.icon :name="$isVoided ? 'x-circle' : 'hourglass'" size="20" /></span>
                <div>
                    <h2 class="h6 mb-0" id="withdrawal-status-heading">{{ $isVoided ? 'Request voided' : 'Withdrawal pending acknowledgment' }}</h2>
                    <p class="small mb-0">{{ $isVoided ? 'The requestor withdrew this request. It is closed and kept for history; earlier approvals and quotations remain below.' : 'The requestor withdrew this request. Processing is paused until the staff handling it acknowledge the withdrawal.' }}</p>
                </div>
            </div>
            <dl class="withdrawal-status-details">
                <div><dt>Withdrawn by</dt><dd>{{ $purchaseRequest->withdrawnBy?->name ?? 'Not recorded' }}</dd></div>
                <div><dt>Withdrawn on</dt><dd>{{ $purchaseRequest->withdrawn_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</dd></div>
                <div><dt>Stopped at</dt><dd>{{ \App\Support\RequestWithdrawal::stageLabel($purchaseRequest->withdrawal_stage) }}</dd></div>
                @if($isVoided)
                    <div><dt>Acknowledged by</dt><dd>{{ $purchaseRequest->withdrawalAcknowledgedBy?->name ?? 'Not recorded' }}</dd></div>
                    <div><dt>Acknowledged on</dt><dd>{{ $purchaseRequest->withdrawal_acknowledged_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</dd></div>
                @endif
                <div class="withdrawal-status-wide"><dt>Reason</dt><dd>{{ $purchaseRequest->withdrawal_reason }}</dd></div>
                @if($isVoided && filled($purchaseRequest->withdrawal_acknowledgment_remarks))
                    <div class="withdrawal-status-wide"><dt>Acknowledgment note</dt><dd>{{ $purchaseRequest->withdrawal_acknowledgment_remarks }}</dd></div>
                @endif
            </dl>
            @can('acknowledgeWithdrawal', $purchaseRequest)
                <form class="withdrawal-acknowledge" method="POST" action="{{ route('purchase-requests.withdrawal.acknowledge', $purchaseRequest) }}">
                    @csrf
                    <label class="form-label" for="acknowledgment-remarks">Note <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea class="form-control mb-2" id="acknowledgment-remarks" name="acknowledgment_remarks" rows="2" maxlength="2000" placeholder="e.g. Supplier informed; quotation request cancelled.">{{ old('acknowledgment_remarks') }}</textarea>
                    <button class="btn btn-warning" type="submit">Acknowledge Withdrawal</button>
                    <span class="small text-muted ms-2">This voids the request and all its items.</span>
                </form>
            @elseif($isWithdrawalPending)
                <p class="small text-muted mb-0">Waiting for {{ \App\Support\RequestWithdrawal::stageLabel($purchaseRequest->withdrawal_stage) }} staff to acknowledge.</p>
            @endcan
        </div>
    </section>
@endif
