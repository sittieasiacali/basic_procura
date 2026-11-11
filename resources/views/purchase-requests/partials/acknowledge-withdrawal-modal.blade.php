<div class="modal fade" id="acknowledge-withdrawal-{{ $purchaseRequest->id }}" tabindex="-1" aria-labelledby="acknowledge-withdrawal-heading-{{ $purchaseRequest->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="{{ \App\Support\WorkflowUrl::route('purchase-requests.withdrawal.acknowledge', $purchaseRequest) }}">
            @csrf
            <input type="hidden" name="return_to_monitoring" value="1">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="acknowledge-withdrawal-heading-{{ $purchaseRequest->id }}">Acknowledge Withdrawal</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="fw-semibold">{{ $purchaseRequest->request_number }} &middot; {{ $purchaseRequest->title }}</p>
                <dl class="mb-3">
                    <dt>Workflow stopped at</dt>
                    <dd>{{ \App\Support\RequestWithdrawal::stageLabel($purchaseRequest->withdrawal_stage) }}</dd>
                    <dt>Request owner</dt>
                    <dd>{{ $purchaseRequest->requestor?->name ?? 'Not recorded' }}</dd>
                    <dt>Withdrawal reason</dt>
                    <dd class="text-break">{{ $purchaseRequest->withdrawal_reason }}</dd>
                    <dt>Acknowledging account</dt>
                    <dd>{{ auth()->user()->name }}<span class="d-block small text-muted">{{ auth()->user()->email }} &middot; {{ auth()->user()->activeRole()?->name }}</span></dd>
                </dl>
                <label class="form-label" for="acknowledgment-remarks-{{ $purchaseRequest->id }}">Acknowledgment remarks (optional)</label>
                <textarea class="form-control" id="acknowledgment-remarks-{{ $purchaseRequest->id }}" name="acknowledgment_remarks" rows="3" maxlength="2000"></textarea>
                <p class="small text-muted mt-3 mb-0">Acknowledging closes the whole request as voided. Your account, the stopped workflow, and the acknowledgment date will be recorded.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning">Acknowledge Withdrawal</button>
            </div>
        </form>
    </div>
</div>
