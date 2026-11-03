{{-- Finance transaction sheet (online / cashout deposit / check) for a paid Budget Request, shown in the
     Processed payment preview. The title follows the mode of payment. --}}
@php
    $budgetLines = $budget->lines;
    $budgetAccount = $budget->source_bank_account ?: $budget->campaign_account_number ?: $budget->campaign?->account_number;
    // A group BR can fund POs from several campaign source accounts; then each line names its own.
    $lineAccount = fn ($line) => $line->source_bank_account ?: $budgetAccount;
    $hasMixedAccounts = $budgetLines->map($lineAccount)->filter()->unique()->count() > 1;
    $budgetRequester = $budget->requester ?? $purchaseRequest?->requestor;
    $transactionSheetTitle = match ($budget->payment_method) {
        'ONLINE' => 'Online Transactions',
        'CASH' => 'Cashout Deposit Transactions',
        'CHECK' => 'Check Transactions',
        default => 'Budget Request',
    };
    $blankRows = $budget->payment_method === 'CASH' ? max(0, 14 - max(1, $budgetLines->count())) : 0;
    $accountTypeLabel = match ($budget->account_type) {
        'ACA' => 'ACA',
        'OTHER_PARTY' => 'Other Party',
        'OWN_ACCOUNT' => 'Own Account',
        default => 'Not recorded',
    };
@endphp

<div class="br-sheet {{ $budget->payment_method === 'CASH' ? 'br-sheet--cash' : '' }}">
    @include('partials.document-letterhead')
    <div class="br-sheet-title">{{ $transactionSheetTitle }}</div>
    <div class="br-sheet-account-summary">
        <span><strong>Type of Account:</strong> {{ $accountTypeLabel }}</span>
        <span><strong>Campaign Bank Account:</strong> {{ $hasMixedAccounts ? 'Multiple (see each line)' : ($budgetAccount ?: 'Not provided') }}</span>
    </div>

    <div class="table-responsive">
        <table class="br-sheet-table">
            <colgroup>
                <col class="br-col-num"><col class="br-col-details"><col class="br-col-requester"><col class="br-col-amount"><col class="br-col-party">
            </colgroup>
            <thead>
                <tr>
                    <th class="br-cell-blank" rowspan="2"></th>
                    <th class="br-cell-bank">{{ $hasMixedAccounts ? 'Multiple source accounts' : ($budgetAccount ?: 'Source bank account not provided') }}</th>
                    <th class="br-cell-highlight" rowspan="2">Requested By</th>
                    <th class="br-cell-head" rowspan="2">Amount</th>
                    <th class="br-cell-head" rowspan="2">Other Party</th>
                </tr>
                <tr><th class="br-cell-head">Details</th></tr>
            </thead>
            <tbody>
            @forelse($budgetLines as $line)
                @php
                    $linePurchaseOrder = $line->purchaseOrder;
                    $paymentReference = $budget->payments->firstWhere('purchase_order_id', $line->purchase_order_id)?->reference_number;
                    $otherParty = $linePurchaseOrder?->account_name ?: $linePurchaseOrder?->supplier?->name;
                @endphp
                <tr>
                    <td class="br-cell-num">{{ $loop->iteration }}</td>
                    <td>
                        <div><span class="fw-semibold">{{ $linePurchaseOrder?->po_number ?? 'Legacy entry' }}</span> — {{ $line->details }}</div>
                        @if($hasMixedAccounts)<div class="br-cell-note"><strong>Source:</strong> {{ $line->campaign?->name ?? $budget->campaign?->name }} &middot; {{ $lineAccount($line) ?: 'Not provided' }}</div>@endif
                        @if($linePurchaseOrder?->payment_terms)<div class="br-cell-note">Terms: {{ $linePurchaseOrder->payment_terms }}</div>@endif
                        @if($linePurchaseOrder?->payment_details)<div class="br-cell-note text-break">{{ $linePurchaseOrder->payment_details }}</div>@endif
                    </td>
                    <td>{{ $linePurchaseOrder?->requested_by ?: $line->requester?->name ?: '—' }}</td>
                    <td class="br-cell-amount">&#8369; {{ number_format((float) $line->amount, 2) }}</td>
                    <td>
                        <div>{{ $otherParty ?: '—' }}</div>
                        @if($linePurchaseOrder?->account_number)<div class="br-cell-note">Acct No: {{ $linePurchaseOrder->account_number }}</div>@endif
                        @if($line->bank_details)<div class="br-cell-note text-break">{{ $line->bank_details }}</div>@endif
                        @if($paymentReference)<div class="br-cell-note">Ref: {{ $paymentReference }}</div>@endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="br-cell-num">1</td>
                    <td><span class="fw-semibold">{{ $purchaseRequest?->purchaseOrder?->po_number ?? '—' }}</span> — {{ $purchaseRequest?->title ?? 'Budget Request '.$budget->budget_request_number }}</td>
                    <td>{{ $budgetRequester?->name ?? '—' }}</td>
                    <td class="br-cell-amount">&#8369; {{ number_format((float) $budget->amount, 2) }}</td>
                    <td>—</td>
                </tr>
            @endforelse
            @for($row = 0; $row < $blankRows; $row++)
                <tr class="br-sheet-empty-row"><td class="br-cell-num">{{ max(1, $budgetLines->count()) + $row + 1 }}</td><td></td><td></td><td class="br-cell-amount"></td><td></td></tr>
            @endfor
            </tbody>
            <tfoot>
                <tr>
                    <td class="br-cell-blank"></td>
                    <th class="br-cell-total">Total</th>
                    <td class="br-cell-total"></td>
                    <th class="br-cell-grand">&#8369; {{ number_format((float) $budget->amount, 2) }}</th>
                    <td class="br-cell-blank"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    @include('budget-requests.partials.br-signatures')
</div>
