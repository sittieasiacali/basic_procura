@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    // Each label names the stage the request is actually waiting at.
    [$label, $class] = match ($value) {
        'DRAFT' => ['Draft', 'status-draft'],
        'FOR_TL_APPROVAL', 'FOR_OM_APPROVAL' => ['For Approval', 'status-pending'],
        'RETURNED' => ['Returned', 'status-pending'],
        'REJECTED', 'CANCELLED' => ['Disapproved', 'status-danger'],
        'FOR_TEAM_SCREENING', 'FOR_IT_SCREENING', 'FOR_PREMISES_SCREENING' => ['Screening', 'status-info'],
        'FOR_INVENTORY_RELEASE' => ['For Inventory Release', 'status-info'],
        'FOR_OM_SMTS_APPROVAL' => ['For SMT Approval', 'status-review'],
        'FOR_PROCUREMENT' => ['For Quotation', 'status-review'],
        'FOR_CANVASSING' => ['For Canvassing', 'status-review'],
        'FOR_SUPPLIER_APPROVAL' => ['For President Approval', 'status-review'],
        'SUPPLIER_APPROVED', 'APPROVED' => ['Approved', 'status-success'],
        'PO_CREATED', 'FOR_BUDGET_PROCESSING' => ['For PO', 'status-review'],
        'FOR_BUDGET_MAKER', 'FOR_BUDGET_CHECKER', 'FOR_BUDGET_PROCEEDER', 'AWAITING_PRESIDENT_FINAL_APPROVAL' => ['Purchasing Final Approval', 'status-info'],
        'FOR_PAYMENT', 'PAYMENT_PENDING' => ['For Payment', 'status-info'],
        'PARTIALLY_PAID' => ['Partially Paid', 'status-pending'],
        'FULLY_PAID' => ['Paid', 'status-success'],
        'FOR_DELIVERY', 'FOR_RECEIVING' => ['For Receiving', 'status-info'],
        'PARTIALLY_RECEIVED' => ['Partially Received', 'status-pending'],
        'RECEIVED_WITH_INVOICE' => ['Received', 'status-success'],
        'FOR_INVENTORY_LOGGING', 'ENDORSED' => ['For Inventory Logging', 'status-info'],
        'COMPLETED' => ['Completed', 'status-success'],
        'FULFILLED' => ['Fulfilled', 'status-success'],
        'WITHDRAWAL_PENDING' => ['Void Pending', 'status-pending'],
        'VOIDED' => ['Voided', 'status-danger'],
        default => [str($value)->replace('_', ' ')->title(), 'status-draft'],
    };
@endphp

<span {{ $attributes->class(['badge', $class]) }} title="{{ $label }}">{{ $label }}</span>
