@php
    $document = $purchaseOrderDocument;
    $variant = $documentVariant ?? 'complete';
    $rows = $document['items']
        ->filter(fn (array $item) => filled($item['details'] ?? null)
            || filled($item['specifications'] ?? null)
            || filled($item['unit'] ?? null)
            || is_numeric($item['quantity'] ?? null)
            || is_numeric($item['unit_price'] ?? null)
            || is_numeric($item['amount'] ?? null))
        ->values();
    $displayTotal = $rows->sum(fn (array $item) => is_numeric($item['amount'] ?? null) ? (float) $item['amount'] : 0);
@endphp

<section class="purchase-order-document po-sheet {{ $variant === 'campaign-bank' ? 'po-sheet-campaign-bank' : '' }}" data-po-sheet-variant="{{ $variant }}" aria-label="{{ $document['poNumber'] }} Purchase Order">
    @if($variant !== 'campaign-bank')
    <section class="po-sheet-transaction" aria-label="Purchase Order transaction information">
        <time datetime="{{ $document['issueDate']?->format('Y-m-d') }}">{{ $document['issueDate']?->format('F j, Y') ?? '' }}</time>
        <h3>TYPE OF TRANSACTION</h3>
        <strong>{{ $document['transactionTypeLabel'] === 'Not recorded' ? '' : $document['transactionTypeLabel'] }}</strong>
    </section>
    @else
        <div class="po-sheet-campaign-spacer" aria-hidden="true"></div>
    @endif

    <div class="po-sheet-table-wrap">
        <table class="po-sheet-table" aria-label="Purchase Order details">
            @if($variant === 'campaign-bank')
                <colgroup><col class="po-sheet-col-details"><col class="po-sheet-col-requestor"><col class="po-sheet-col-account"><col class="po-sheet-col-amount"><col class="po-sheet-col-bank"></colgroup>
            @else
                <colgroup><col class="po-sheet-col-line"><col class="po-sheet-col-details"><col class="po-sheet-col-requestor"><col class="po-sheet-col-account"><col class="po-sheet-col-amount"><col class="po-sheet-col-bank"></colgroup>
            @endif
            <thead>
                <tr class="po-sheet-source-row">
                    @if($variant !== 'campaign-bank')<th rowspan="2" scope="col" class="po-sheet-line-heading">Line<br>no.</th>@endif
                    <th colspan="5">Campaign Bank Account @if($variant !== 'account-type' && filled($document['campaignBankAccount']['accountNumber'])) &mdash; <span>{{ $document['campaignBankAccount']['accountNumber'] }}</span>@endif</th>
                </tr>
                <tr class="po-sheet-column-row">
                    <th scope="col">DETAILS</th>
                    <th scope="col">REQUESTED BY</th>
                    <th scope="col">ACCOUNT TYPE</th>
                    <th scope="col">AMOUNT</th>
                    <th scope="col">Bank Details (for Deposit)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $index => $item)
                    <tr class="po-sheet-detail-row">
                        @if($variant !== 'campaign-bank')<td class="po-sheet-line">{{ $item['line_number'] ?? $index + 1 }}</td>@endif
                        <td>{{ $item['details'] }}@if(filled($item['specifications']))<span class="po-sheet-item-note">{{ $item['specifications'] }}</span>@endif</td>
                        <td>{{ $item['requested_by'] ?? '' }}</td>
                        <td>{{ $variant !== 'campaign-bank' ? ($item['account_type'] ?? '') : '' }}</td>
                        <td class="po-sheet-amount">@if(is_numeric($item['amount'] ?? null))&#8369; {{ number_format((float) $item['amount'], 2) }}@endif</td>
                        <td class="po-sheet-bank-details">{{ $variant !== 'account-type' ? ($item['bank_details'] ?? '') : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>@if($variant !== 'campaign-bank')<td class="po-sheet-line"></td>@endif<th scope="row">TOTAL</th><td></td><td></td><td class="po-sheet-total">&#8369; {{ number_format($displayTotal, 2) }}</td><td class="po-sheet-bank-total"></td></tr>
            </tfoot>
        </table>
    </div>

    @if($variant !== 'campaign-bank')
    <section class="po-sheet-signatures" aria-label="Purchase Order signatories">
        @foreach($document['signatories'] as $signatory)
            <div class="po-sheet-signatory" data-signatory="{{ $loop->index }}">
                <h3>{{ $signatory['label'] === 'Approved by' ? 'APPROVED BY' : $signatory['label'] }}:</h3>
                <div class="po-sheet-signature-space">@if(filled($signatory['signature'] ?? null))<img src="{{ $signatory['signature'] }}" alt="{{ $signatory['label'] }} signature">@endif</div>
                <strong>{{ $signatory['name'] === 'Not recorded' ? '' : $signatory['name'] }}</strong>
                <div class="po-sheet-signature-line" aria-hidden="true"></div>
            </div>
        @endforeach
    </section>
    @endif
</section>
