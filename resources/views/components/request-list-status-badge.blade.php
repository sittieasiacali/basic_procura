@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    [$label, $class] = match ($value) {
        'DRAFT', 'FOR_TL_APPROVAL', 'FOR_OM_APPROVAL' => ['Pending', 'status-pending'],
        'RETURNED' => ['Returned', 'status-pending'],
        'REJECTED', 'CANCELLED' => ['Disapproved', 'status-danger'],
        'FOR_TEAM_SCREENING', 'FOR_IT_SCREENING', 'FOR_PREMISES_SCREENING', 'FOR_INVENTORY_RELEASE' => ['Screening', 'status-info'],
        'FOR_OM_SMTS_APPROVAL' => ['For SMT Approval', 'status-review'],
        'FOR_PROCUREMENT' => ['For Quotation', 'status-review'],
        'FOR_CANVASSING' => ['For Canvassing', 'status-review'],
        'FOR_BUDGET_MAKER', 'FOR_BUDGET_CHECKER', 'FOR_BUDGET_PROCEEDER', 'AWAITING_PRESIDENT_FINAL_APPROVAL' => ['Purchasing Final Approval', 'status-info'],
        'APPROVED' => ['Approved', 'status-success'],
        'PO_CREATED', 'FOR_BUDGET_PROCESSING', 'PAYMENT_PENDING', 'PARTIALLY_PAID',
        'FOR_DELIVERY', 'PARTIALLY_RECEIVED', 'RECEIVED_WITH_INVOICE', 'FULLY_PAID',
        'FOR_INVENTORY_LOGGING', 'ENDORSED' => ['For PO', 'status-review'],
        'COMPLETED', 'FULFILLED' => ['Fulfilled', 'status-success'],
        default => [str($value)->replace('_', ' ')->title(), 'status-draft'],
    };
@endphp

<span {{ $attributes->class(['badge', $class]) }} title="{{ $label }}">{{ $label }}</span>
