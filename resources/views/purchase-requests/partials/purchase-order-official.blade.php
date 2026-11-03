@php
    $document = $purchaseOrderDocument;
    $official = $document['official'];
    $items = $document['items']
        ->filter(fn (array $item) => filled($item['details'] ?? null)
            || filled($item['specifications'] ?? null)
            || filled($item['unit'] ?? null)
            || is_numeric($item['quantity'] ?? null)
            || is_numeric($item['unit_price'] ?? null)
            || is_numeric($item['amount'] ?? null))
        ->values();
    $displayTotal = $items->sum(fn (array $item) => is_numeric($item['amount'] ?? null) ? (float) $item['amount'] : 0);
@endphp

<article class="purchase-order-document po-official" data-po-sheet-variant="official" aria-label="Official Purchase Order {{ $document['poNumber'] }}">
    <header class="po-official-brand">
        <img src="{{ $official['companyLogo'] }}" alt="Allianz Synergia">
        <div class="po-official-address">{{ $official['companyAddress'] }}</div>
        <h2>PURCHASE ORDER</h2>
    </header>

    <section class="po-official-parties" aria-label="Purchase Order recipient and reference details">
        <dl class="po-official-recipient">
            <div><dt>To:</dt><dd><strong>{{ $official['recipient'] }}</strong></dd></div>
            <div><dt>Address:</dt><dd>{{ $official['recipientAddress'] ?: 'Not provided' }}</dd></div>
            <div><dt>Acct#:</dt><dd>{{ $official['accountNumber'] ?: 'Not provided' }}</dd></div>
            <div><dt>Acct name:</dt><dd>{{ $official['accountName'] ?: 'Not provided' }}</dd></div>
            <div><dt>Requested by:</dt><dd>{{ $document['requestedBy'] }}</dd></div>
        </dl>
        <dl class="po-official-reference">
            <div><dt>Request Date:</dt><dd>{{ $official['requestDate']?->format('F j, Y') ?: 'Not recorded' }}</dd></div>
            <div><dt>TIN:</dt><dd>{{ $official['tin'] ?: 'Not provided' }}</dd></div>
            <div><dt>PO No.:</dt><dd><strong>{{ $document['poNumber'] }}</strong></dd></div>
            <div><dt>PR No.:</dt><dd>{{ $document['purchaseRequest']->request_number }}</dd></div>
            <div><dt>Terms:</dt><dd>{{ $official['terms'] ?: 'Not provided' }}</dd></div>
            <div><dt>Delivery:</dt><dd>{{ $official['expectedDelivery']?->format('F j, Y') ?: 'Not recorded' }}</dd></div>
        </dl>
    </section>

    <table class="po-official-items" aria-label="Purchase Order items">
        <colgroup><col class="po-official-code"><col class="po-official-description"><col class="po-official-campaign"><col class="po-official-qty"><col class="po-official-unit"><col class="po-official-rate"><col class="po-official-amount"><col class="po-official-total"></colgroup>
        <thead><tr><th>Item Code</th><th>Description</th><th>Campaign</th><th>Qty</th><th>Unit</th><th>Rate</th><th>Amount</th><th>Total</th></tr></thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td>{{ $item['item_code'] }}</td>
                    <td>{{ $item['details'] }}@if(filled($item['specifications']))<small>{{ $item['specifications'] }}</small>@endif</td>
                    <td>{{ $item['campaign'] ?: 'Not recorded' }}</td>
                    <td class="po-number">{{ rtrim(rtrim(number_format($item['quantity'], 2, '.', ''), '0'), '.') }}</td>
                    <td>{{ $item['unit'] }}</td>
                    <td class="po-money">{{ number_format($item['unit_price'], 2) }}</td>
                    <td class="po-money">{{ number_format($item['amount'], 2) }}</td>
                    <td class="po-money">{{ number_format($item['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="po-official-reason">
        <strong>REASON:</strong>
        <div><span>{{ $document['poNumber'] }} - 1</span><p>{{ $official['reason'] ?: 'Not provided' }}</p></div>
        <div class="po-official-reason-detail"><span>Payment details:</span><p>{{ $official['paymentDetails'] ?: 'Not provided' }}</p></div>
        <div class="po-official-reason-detail"><span>Remarks:</span><p>{{ $official['remarks'] ?: 'N/A' }}</p></div>
    </section>

    <dl class="po-official-totals">
        <div><dt>Net of VAT:</dt><dd>{{ number_format($official['netOfVat'], 2) }}</dd></div>
        <div><dt>VAT:</dt><dd>{{ number_format($official['vat'], 2) }}</dd></div>
        <div><dt>Total:</dt><dd>{{ number_format($displayTotal, 2) }}</dd></div>
    </dl>

    <footer class="po-official-signatures">
        @foreach($official['signatories'] as $signatory)
            <section>
                <div class="po-official-signature-label"><strong>{{ $signatory['label'] }}:</strong>@if($signatory['label'] === 'Approved by' && $signatory['date'])<time datetime="{{ $signatory['date']->format('Y-m-d') }}">{{ $signatory['date']->format('F j, Y') }}</time>@endif</div>
                <div class="po-official-signature-space">@if(filled($signatory['signature'] ?? null))<img src="{{ $signatory['signature'] }}" alt="{{ $signatory['label'] }} signature">@endif</div>
                <strong class="po-official-signature-name">{{ $signatory['name'] ?: '' }}</strong>
                @if(filled($signatory['role']))<small class="po-official-signature-role">{{ $signatory['role'] }}</small>@endif
                @if($signatory['date'] && $signatory['label'] !== 'Approved by')<time datetime="{{ $signatory['date']->format('Y-m-d') }}">{{ $signatory['date']->format('F j, Y') }}</time>@endif
            </section>
        @endforeach
    </footer>
</article>