@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : $status;
    $label = match ($value) {
        'FOR_OM_SMTS_APPROVAL' => 'For SMT Approval',
        'FOR_TEAM_SCREENING' => 'For IT / Premises Classification',
        // IT / Premises record supplier quotations at this stage, before Canvassing.
        'FOR_PROCUREMENT' => 'For Quotation',
        'FOR_SUPPLIER_APPROVAL' => 'For President Supplier Approval',
        'WITHDRAWAL_PENDING' => 'Withdrawal Pending Acknowledgment',
        'FOR_BUDGET_MAKER', 'FOR_BUDGET_CHECKER', 'FOR_BUDGET_PROCEEDER', 'AWAITING_PRESIDENT_FINAL_APPROVAL' => 'Awaiting Purchasing Final Approval',
        default => str($value)->replace('_', ' ')->title(),
    };
    $class = match ($value) {
        'DRAFT' => 'status-draft',
        'RETURNED' => 'status-pending',
        'REJECTED', 'CANCELLED', 'VOIDED' => 'status-danger',
        'WITHDRAWAL_PENDING' => 'status-pending',
        'FOR_TL_APPROVAL', 'FOR_OM_APPROVAL', 'FOR_OM_SMTS_APPROVAL' => 'status-review',
        'FOR_TEAM_SCREENING', 'FOR_IT_SCREENING', 'FOR_PREMISES_SCREENING' => 'status-pending',
        'FOR_PROCUREMENT', 'FOR_CANVASSING', 'FOR_BUDGET_PROCESSING', 'FOR_BUDGET_MAKER', 'FOR_BUDGET_CHECKER', 'FOR_BUDGET_PROCEEDER', 'AWAITING_PRESIDENT_FINAL_APPROVAL', 'PAYMENT_PENDING', 'FOR_DELIVERY', 'FOR_INVENTORY_RELEASE', 'FOR_INVENTORY_LOGGING' => 'status-info',
        'FOR_PAYMENT', 'FOR_RECEIVING' => 'status-success',
        'PARTIALLY_PAID', 'PARTIALLY_RECEIVED' => 'status-pending',
        'APPROVED', 'PO_CREATED', 'RECEIVED_WITH_INVOICE', 'FULLY_PAID', 'ENDORSED', 'COMPLETED', 'FULFILLED' => 'status-success',
        default => 'status-draft',
    };
@endphp

<span {{ $attributes->class(['badge', $class]) }}>{{ $label }}</span>
