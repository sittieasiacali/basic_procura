@php
    $embedded = $embedded ?? false;
    $registerAnchor = $embedded ? 'processed-requests-heading' : 'budget-history-heading';
    $filterUrl = $filterUrl ?? route('budget.index');
    $budgetRegisterTab = $budgetRegisterTab ?? 'created';
    $budgetCreatedCount = $budgetCreatedCount ?? null;
    $budgetApprovedCount = $budgetApprovedCount ?? null;
    $isApprovedRegister = $budgetRegisterTab === 'approved';
    $isMakerWorkflow = ($creatorWorkflow ?? null) === 'budget-request';
    $exportStage = $isMakerWorkflow && in_array($processedTab ?? null, ['finance', 'finalized'], true) ? $processedTab : null;
    // Rows Finance can finalize together; nobody else sees tick boxes.
    $finalizableIds = $budgetRequests->getCollection()
        ->filter(fn ($budget) => $budget->stage()[1] === 'finance' && $budget->purchaseRequest && auth()->user()->can('reviewBudgetFinal', $budget->purchaseRequest))
        ->pluck('id')->all();
    $canFinalize = $finalizableIds !== [];
@endphp

@if($embedded)
<div class="budget-register budget-register--embedded">
@else
<section class="card budget-register {{ $class ?? '' }}" aria-labelledby="budget-history-heading">
@endif
    @unless($embedded)
    <div class="card-header">
        <div>
            <div class="section-kicker">Budget register</div>
            <h2 class="section-title" id="budget-history-heading">{{ $isApprovedRegister ? 'Budget Approved' : 'Created Budget Requests' }}</h2>
        </div>
    </div>
    @if(isset($budgetCreatedCount))
        <nav class="budget-register-tabs" aria-label="Budget Request register views">
            <a class="budget-register-tab {{ ! $isApprovedRegister ? 'is-active' : '' }}" href="{{ route('dashboard', [...request()->except(['budget_register_tab', 'history_page']), ...(! $isApprovedRegister ? [] : ['budget_register_tab' => 'created'])]) }}#budget-history-heading" @unless($isApprovedRegister) aria-current="page" @endunless>
                <x-ui.icon name="receipt" size="15" />
                <span>Created</span>
                <span class="budget-register-tab-count">{{ number_format($budgetCreatedCount) }}</span>
            </a>
            <a class="budget-register-tab {{ $isApprovedRegister ? 'is-active' : '' }}" href="{{ route('dashboard', [...request()->except(['budget_register_tab', 'history_page']), ...($isApprovedRegister ? [] : ['budget_register_tab' => 'approved'])]) }}#budget-history-heading" @if($isApprovedRegister) aria-current="page" @endif>
                <x-ui.icon name="check-circle" size="15" />
                <span>Budget Approved</span>
                <span class="budget-register-tab-count">{{ number_format($budgetApprovedCount) }}</span>
            </a>
            @if($isApprovedRegister)<span class="budget-register-readonly"><x-ui.icon name="eye" size="14" />View only</span>@endif
        </nav>
    @endif
    @endunless
    {{-- Search and filters on the left, Final budget and Export on the right: one toolbar row. --}}
    <div class="br-controls">
    {{-- The Purchasing Team's Final Budget Request search sits above its stage tabs instead. --}}
    @unless($isMakerWorkflow)
    @include('partials.table-toolbar', ['toolbar' => [
        'prefix' => 'br',
        'searchName' => 'br_search',
        'statusName' => 'transaction_type',
        'pageName' => 'history_page',
        'action' => $filterUrl,
        'anchor' => $registerAnchor,
        'label' => 'Filter created Budget Requests',
        'searchPlaceholder' => 'Search BR number, campaign, PO, reference…',
        'statusLabel' => 'Transaction type',
        'statusPlaceholder' => 'All transaction types',
        'statusOptions' => ['ONLINE' => 'Online Transaction', 'CASH' => 'Cash Transaction', 'CHECK' => 'Check Transaction'],
    ]])
    @endunless
    <div class="br-action-bar" data-br-final>
        @if($canFinalize)
            <span class="br-action-hint" data-br-final-hint>Tick the Budget Requests to finalize, then click Final budget. Their BR codes are issued then.</span>
            <a class="btn btn-sm btn-primary br-final-button disabled" href="{{ route('budget.finalize') }}" aria-disabled="true" data-br-final-button data-base-url="{{ route('budget.finalize') }}" data-workflow-action-modal data-workflow-action-title="Final budget">
                <x-ui.icon name="check-circle" size="15" />
                <span data-br-final-label>Final budget</span>
            </a>
        @elseif($budgetRequests->getCollection()->contains(fn ($budget) => $budget->stage()[1] === 'finance'))
            <span class="br-action-hint">Final budget for these requests is done by Purchasing / Purchasing Team.</span>
        @endif
        @include('budget-requests.partials.export-button')
    </div>
    </div>
    @if(($processedTab ?? null) === 'finalized' || $isApprovedRegister)
        @include('budget-requests.partials.preparation-table', ['preparationBudgets' => $budgetRequests])
    @else
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>@if($canFinalize)<th class="br-select-cell"><input class="form-check-input" type="checkbox" aria-label="Select all Budget Requests waiting for Finance on this page" data-br-select-all></th>@endif<th class="table-row-number">#</th><th>BR Number</th><th>Campaign / Account</th><th>When Can Pay</th><th>PO / Supplier</th><th>Bank details / Reference</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            @forelse($budgetRequests as $budgetRequest)
                @php
                    // Group BRs list several accounts and POs; the cells show the first and the rest on hover.
                    $accountItems = ($budgetRequest->lines->isNotEmpty() ? $budgetRequest->lines : collect([null]))
                        ->map(fn ($line) => ['title' => $line?->campaign?->name ?? $budgetRequest->campaign?->name ?? 'Not assigned', 'note' => $line?->source_bank_account ?: $budgetRequest->campaign_account_number ?: 'No account number'])
                        ->unique(fn ($item) => $item['title'].'|'.$item['note']);
                    $poItems = $budgetRequest->lines->map(fn ($line) => ['title' => ($line->purchaseOrder?->po_number ?? 'Legacy').' · '.($line->purchaseOrder?->supplier?->name ?? 'Supplier not recorded'), 'note' => $line->details]);
                    $bankItems = $budgetRequest->lines->pluck('bank_details')->filter()->unique()->map(fn ($details) => ['title' => $details]);
                    $references = $budgetRequest->payments->pluck('reference_number')->filter()->unique()->join(', ');
                    [$stageLabel, $stageKey] = $budgetRequest->stage();
                @endphp
                <tr>
                    @if($canFinalize)
                        <td class="br-select-cell">@if(in_array($budgetRequest->id, $finalizableIds, true))<input class="form-check-input" type="checkbox" value="{{ $budgetRequest->id }}" data-amount="{{ (float) $budgetRequest->amount }}" aria-label="Select {{ $budgetRequest->displayNumber() }} for final budget" data-br-select>@endif</td>
                    @endif
                    <td class="table-row-number">{{ $budgetRequests->firstItem() + $loop->index }}</td>
                    <td class="fw-semibold text-nowrap"><a href="{{ route('budget-requests.document', $budgetRequest) }}" data-br-preview-url="{{ route('budget-requests.document', $budgetRequest) }}" aria-label="Preview Budget Request {{ $budgetRequest->displayNumber() }}">{{ $budgetRequest->displayNumber() }}</a><div><span class="br-stage br-stage--{{ $stageKey }}">{{ $stageLabel }}</span></div></td>
                    <td><x-ui.more-list :items="$accountItems" label="source accounts" /></td>
                    <td class="text-nowrap">@if($budgetRequest->payable_on){{ $budgetRequest->payable_on->format('M d, Y') }}@else<span class="br-empty">Not scheduled</span>@endif</td>
                    <td style="min-width: 14rem">@if($poItems->count() > 1)<div class="more-list-count">{{ $poItems->count() }} POs</div>@endif<x-ui.more-list :items="$poItems" label="POs" /></td>
                    <td style="min-width: 14rem">@if($bankItems->isNotEmpty())<x-ui.more-list :items="$bankItems" label="bank details" />@else<div class="br-empty">Bank details not provided</div>@endif<div class="small text-muted">Ref: {{ $references ?: 'not recorded yet' }}</div></td>
                    <td class="text-end text-nowrap fw-semibold">&#8369; {{ number_format((float) $budgetRequest->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $canFinalize ? 8 : 7 }}"><x-ui.empty-state icon="flow" title="No Budget Requests found" description="No created Budget Requests match the search or selected transaction type." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @endif
    @if($budgetRequests->hasPages())<div class="card-footer">{{ $budgetRequests->fragment($registerAnchor)->links() }}</div>@endif
@if($embedded)</div>@else</section>@endif
