{{-- Requested / Prepared / Checked / Approved signatures shared by the BR document and the payment transaction sheet. --}}
@php
    $budgetPreparer = $budget->maker ?? $budget->purchaseOrder?->creator ?? $purchaseRequest?->purchaseOrder?->creator;
    $budgetMakerSignature = $purchaseRequest?->approvals->firstWhere('approval_level', 'BUDGET_REQUEST_PREPARATION');
    $budgetChecker = $budget->checker ?? ($purchaseRequest?->purchaseOrder ? $purchaseRequest->canvassingActor() : null);
@endphp
<div class="br-sheet-signatures budget-signatures">
    <div class="br-signature">
        <div class="br-signature-label">Requested by:</div>
        <div class="br-signature-name">{{ config('procurement.budget_request_signatories.requested_by') ?: ($budget->requester?->name ?? 'Not recorded') }}</div>
    </div>
    <div class="br-signature">
        <div class="br-signature-label">Prepared by:</div>
        @if($budgetMakerSignature?->signature_data)
            <img class="budget-maker-signature" src="{{ \App\Support\WorkflowSignature::forDisplay($budgetMakerSignature->signature_data) }}" alt="Budget Maker signature">
        @endif
        <div class="br-signature-name">{{ $budgetPreparer?->name ?? 'Pending preparation' }}</div>
    </div>
    <div class="br-signature">
        <div class="br-signature-label">Checked by:</div>
        <div class="br-signature-name">{{ $budget->campaign?->name ? $budget->campaign->name.' - ' : '' }}{{ $budgetChecker?->name ?? 'Pending check' }}</div>
    </div>
    <div class="br-signature">
        <div class="br-signature-label">Approved by:</div>
        <div class="br-signature-name">{{ config('procurement.budget_request_signatories.approved_by') ?: ($budget->finalApprover?->name ?? $budget->approver?->name ?? 'Pending approval') }}</div>
    </div>
</div>
