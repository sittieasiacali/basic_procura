{{-- Final budget button for the ticked rows (app.js keeps its link and count in sync), or who does it. --}}
@php
    $finalizerCanAct = $budgetRequests->getCollection()
        ->contains(fn ($budget) => $budget->stage()[1] === 'finance' && $budget->purchaseRequest && auth()->user()->can('reviewBudgetFinal', $budget->purchaseRequest));
@endphp
<div class="br-action-bar" data-br-final>
    @if($finalizerCanAct)
        <span class="br-action-hint" data-br-final-hint>Tick the Budget Requests to finalize, then click Final budget. Their BR codes are issued then.</span>
        <a class="btn btn-sm btn-primary br-final-button disabled" href="{{ route('budget.finalize') }}" aria-disabled="true" data-br-final-button data-base-url="{{ route('budget.finalize') }}" data-workflow-action-modal data-workflow-action-title="Final budget">
            <x-ui.icon name="check-circle" size="15" />
            <span data-br-final-label>Final budget</span>
        </a>
    @elseif($budgetRequests->getCollection()->contains(fn ($budget) => $budget->stage()[1] === 'finance'))
        <span class="br-action-hint">Final budget for these requests is done by Purchasing / Purchasing Team.</span>
    @endif
    {{ $slot ?? '' }}
</div>
