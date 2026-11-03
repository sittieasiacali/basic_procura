{{-- Budget Request document: the Budget Maker details for every PO. The online / cash / check transaction
     sheet belongs to the payment and is shown in Finance's Processed payment preview instead. --}}
@php
    $brPriority = $budget->priorityLevel();
@endphp
<div class="br-sheet">
    @include('partials.document-letterhead')
    <div class="br-sheet-title">Budget Request</div>
    <div class="br-sheet-account-summary">
        <span><strong>BR Number:</strong> {{ $budget->budget_request_number ?? 'Issued at Final budget' }}</span>
        <span><strong>Priority Level:</strong> {{ $brPriority === 'NORMAL' ? 'Medium' : \App\Models\PurchaseRequest::priorityLabel($brPriority) }}</span>
        <span><strong>Purchase Orders:</strong> {{ max(1, $budget->lines->count()) }}</span>
        <span><strong>Total:</strong> &#8369; {{ number_format((float) $budget->amount, 2) }}</span>
    </div>

    @include('budget-requests.partials.preparation-table', ['preparationBudgets' => collect([$budget]), 'preparationDocument' => true])

    @include('budget-requests.partials.br-signatures')
</div>
