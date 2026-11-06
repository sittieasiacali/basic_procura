@php
    $preparationDocument = $preparationDocument ?? false;
    // One Priority Level per BR, chosen at the Final budget (so that form hides it).
    $showPriority = $showPriority ?? true;
    $priorityLabel = fn (string $priority) => $priority === 'NORMAL' ? 'Medium' : \App\Models\PurchaseRequest::priorityLabel($priority);
    $columns = [
        'campaign' => 'Campaign',
        'details' => 'Details',
        'amount' => 'Amount',
        'source_account' => 'Campaign2',
        'mode_of_payment' => 'Mode of Payment',
        'bank_details' => 'Bank Details (for Deposit)',
        'requesting_team' => 'Requesting Team',
        'date_to_pay' => 'Date to Pay',
        'remarks' => 'Remarks',
    ];
    // On screen, placeholder values are muted so real data stands out; the printed document keeps plain text.
    $isPlaceholder = fn ($value) => in_array(trim((string) $value), ['', 'N/A', 'To be set by Finance'], true);
@endphp
@if($preparationDocument)
    {{-- Printed document: grouped by BR, one line per PO, the BR's priority repeated on each line. --}}
    @php
        $columnCount = $showPriority ? 10 : 9;
    @endphp
    <div class="table-responsive budget-preparation-table-wrap">
        <table class="budget-preparation-table budget-preparation-table--document" aria-label="Budget Maker preparation details">
            <thead><tr>@if($showPriority)<th scope="col">Priority Level</th>@endif @foreach($columns as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($preparationBudgets as $preparedBudget)
                <tr class="budget-preparation-group"><th colspan="{{ $columnCount }}" scope="rowgroup">
                    {{ $preparedBudget->displayNumber() }}
                    <span class="budget-preparation-total">Total: &#8369; {{ number_format((float) $preparedBudget->amount, 2) }}</span>
                </th></tr>
                @foreach($preparedBudget->lines as $preparedLine)
                    @php
                        $preparedLine->setRelation('budgetRequest', $preparedBudget);
                        $preparation = $preparedLine->preparationData();
                    @endphp
                    <tr>
                        @if($showPriority)<td class="budget-preparation-priority">{{ $priorityLabel($preparedBudget->priorityLevel()) }}</td>@endif
                        @foreach(array_keys($columns) as $field)
                            <td class="{{ $field === 'amount' ? 'budget-preparation-amount' : (in_array($field, ['details', 'bank_details', 'remarks'], true) ? 'budget-preparation-text' : '') }}">{{ $field === 'amount' ? '₱ '.number_format((float) $preparedLine->amount, 2) : $preparation[$field] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="{{ $columnCount }}">No finalized Budget Requests match the selected filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@else
    {{-- Screen: one row per BR number. Columns whose POs differ show the first value and "+N more" on hover. --}}
    <div class="budget-preparation-view" data-br-view>
    @if($preparationBudgets->count() > 0)
        <div class="budget-preparation-toolbar">
            <span>{{ $preparationBudgets->count() }} {{ $preparationBudgets->count() === 1 ? 'Budget Request' : 'Budget Requests' }} · hover “+ more” to see every PO</span>
            <div class="budget-preparation-zoom" role="group" aria-label="Table size">
                <button class="btn btn-sm" type="button" data-br-zoom="out" aria-label="Zoom out" title="Zoom out">&minus;</button>
                <button class="btn btn-sm budget-preparation-zoom-level" type="button" data-br-zoom="reset" title="Reset to 100%" aria-live="polite">100%</button>
                <button class="btn btn-sm" type="button" data-br-zoom="in" aria-label="Zoom in" title="Zoom in">+</button>
            </div>
            <button class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1" type="button" data-br-fullscreen><x-ui.icon name="eye" size="14" /><span data-br-fullscreen-label>Full screen</span></button>
        </div>
    @endif
    <div class="table-responsive budget-preparation-table-wrap">
        <table class="budget-preparation-table budget-preparation-table--screen" aria-label="Budget Maker preparation details">
            <thead><tr><th scope="col">BR Number</th>@if($showPriority)<th scope="col">Priority Level</th>@endif @foreach($columns as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($preparationBudgets as $preparedBudget)
                @php
                    $lineData = $preparedBudget->lines->map(function ($preparedLine) use ($preparedBudget) {
                        $preparedLine->setRelation('budgetRequest', $preparedBudget);

                        return ['po' => $preparedLine->purchaseOrder?->po_number ?? 'PO', 'amount' => (float) $preparedLine->amount] + $preparedLine->preparationData();
                    });
                    $brPriority = $priorityLabel($preparedBudget->priorityLevel());
                @endphp
                <tr>
                    <td class="text-nowrap">
                        <a class="budget-preparation-code" href="{{ route('budget-requests.document', $preparedBudget) }}" data-br-preview-url="{{ route('budget-requests.document', $preparedBudget) }}" title="Preview {{ $preparedBudget->displayNumber() }}"><x-ui.icon name="receipt" size="14" />{{ $preparedBudget->displayNumber() }}</a><div class="mt-2"><x-admin-delete-transaction :record="$preparedBudget" /></div>
                        <div class="budget-preparation-br-meta">{{ $lineData->count() }} {{ $lineData->count() === 1 ? 'PO' : 'POs' }}</div>
                        <div class="budget-preparation-br-suppliers">{{ $preparedBudget->lines->map(fn ($line) => $line->purchaseOrder?->supplier?->name)->filter()->unique()->join(' · ') }}</div>
                    </td>
                    @if($showPriority)
                        <td><span class="budget-priority-pill budget-priority-pill--{{ str($brPriority)->lower()->slug() }}">{{ $brPriority }}</span></td>
                    @endif
                    @foreach(array_keys($columns) as $field)
                        @if($field === 'amount')
                            <td class="budget-preparation-amount">
                                <strong>&#8369; {{ number_format((float) $preparedBudget->amount, 2) }}</strong>
                                @if($lineData->count() > 1)
                                    <x-ui.more-list class="budget-preparation-more" :items="$lineData->map(fn ($line) => ['title' => '₱ '.number_format($line['amount'], 2), 'note' => $line['po']])" label="PO amounts" :chip="'View '.$lineData->count().' POs'" />
                                @endif
                            </td>
                        @else
                            @php
                                $values = $lineData->pluck($field)->map(fn ($value) => (string) $value);
                            @endphp
                            <td class="{{ in_array($field, ['details', 'bank_details', 'remarks'], true) ? 'budget-preparation-text' : '' }} {{ in_array($field, ['campaign', 'requesting_team', 'date_to_pay', 'mode_of_payment'], true) ? 'text-nowrap' : '' }}" title="{{ $values->unique()->count() <= 1 ? $values->first() : '' }}">@if($values->unique()->count() <= 1)@if($isPlaceholder($values->first()))<span class="budget-preparation-empty">{{ $values->first() ?: 'N/A' }}</span>@else<span>{{ $values->first() }}</span>@endif @else<x-ui.more-list :items="$lineData->map(fn ($line) => ['title' => (string) $line[$field], 'note' => $line['po']])" :label="strtolower($columns[$field])" />@endif</td>
                        @endif
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ $showPriority ? 11 : 10 }}"><x-ui.empty-state icon="receipt" title="No finalized Budget Requests" description="No finalized Budget Requests match the selected filters." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    </div>
@endif
